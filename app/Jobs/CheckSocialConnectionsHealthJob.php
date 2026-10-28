<?php

/**
 * Tourfecto - فحص صحة اتصالات فيسبوك/إنستجرام (يومي).
 *
 * قبل كده مفيش حاجة بتفحص التوكنات: العميل كان بيعرف إن الربط انتهى لما منشور مجدول يفشل.
 * هنا: debug_token لكل اتصال -> (صالح؟ / ينتهي إمتى؟ / صلاحيات ناقصة؟) -> تتخزن في
 * social_connection_health، وبيتبعت إشعار للعميل لما الحالة تسوء (مرة واحدة لكل تغيّر حالة).
 * ما بنعدّلش platform_connections المشتركة.
 * @version 1.0.0
 */
require_once __DIR__ . '/../Services/SocialMedia/MetaApiError.php';
require_once __DIR__ . '/../Services/SocialMedia/MetaSocialAPI.php';
class CheckSocialConnectionsHealthJob implements QueueJobInterface
{
    private const INTERVAL_SECONDS = 86400;
    private const WARN_DAYS = 7;
    private const MAX_PER_RUN = 200;

    private const REQUIRED_SCOPES = [
        'facebook' => ['pages_manage_posts', 'pages_read_engagement'],
        'instagram' => ['instagram_basic', 'instagram_content_publish'],
    ];

    /** @var callable|null fn(string $token): MetaSocialAPI - للاختبارات */
    public static $apiFactory = null;

    public function handle(array $payload): void
    {
        @set_time_limit(120);
        try {
            $this->run();
        } finally {
            self::schedule();
        }
    }

    private function run(): void
    {
        $appId = MetaOAuthClient::setting('meta_app_id', 'META_APP_ID');
        $appSecret = MetaOAuthClient::setting('meta_app_secret', 'META_APP_SECRET');
        if ($appId === '' || $appSecret === '') {
            return; // مفيش بيانات تطبيق Meta: debug_token مش ممكن
        }
        $db = Database::getInstance();
        $rows = $db->query(
            "SELECT id, user_id, platform, access_token FROM platform_connections WHERE platform IN ('facebook','instagram') AND status = 'connected' ORDER BY id ASC LIMIT " . self::MAX_PER_RUN
        ) ?: [];
        $enc = new Encryption();
        foreach ($rows as $r) {
            try {
                $token = $enc->decrypt((string) $r['access_token']);
                $api = self::$apiFactory ? (self::$apiFactory)($token) : new MetaSocialAPI($token);
                $dbg = $api->debugToken($token, $appId, $appSecret);
                $eval = self::evaluate($dbg, (string) $r['platform'], time());
                $this->store((int) $r['id'], $r['user_id'] ? (int) $r['user_id'] : null, (string) $r['platform'], $eval);
                // معرّف مستخدم فيسبوك: أساس مطابقة Deauthorize/Data Deletion. العمود من migration الخصوصية، فالفشل هنا مسموح
                if (!empty($dbg['success']) && !empty($dbg['user_id'])) {
                    try {
                        $db->query('UPDATE social_connection_health SET fb_user_id = ? WHERE platform_connection_id = ?', [(string) $dbg['user_id'], (int) $r['id']]);
                    } catch (Throwable $e) {
                    }
                }
            } catch (Throwable $e) {
                Logger::warning('Social health check failed', ['connection_id' => $r['id'], 'message' => $e->getMessage()]);
            }
        }
    }

    /**
     * تقييم نقي (من غير DB): الأولوية invalid/expired ثم missing_permissions ثم expiring ثم ok.
     * فشل نداء debug_token نفسه (شبكة/حد استخدام) = error ومش بيتحسب مشكلة في الاتصال ولا بيتبعت عنه إشعار.
     * @param array $dbg نتيجة MetaSocialAPI::debugToken
     * @return array ['state','missing','expires_at','data_access_expires_at','error']
     */
    public static function evaluate(array $dbg, string $platform, int $now): array
    {
        $base = ['missing' => [], 'expires_at' => null, 'data_access_expires_at' => null, 'error' => null];
        if (empty($dbg['success'])) {
            // خطأ توكن صريح (190) = مش صالح؛ غير كده فحص فاشل مؤقت
            $isToken = ($dbg['category'] ?? '') === MetaApiError::TOKEN;
            return ['state' => $isToken ? 'invalid' : 'error', 'error' => (string) ($dbg['error'] ?? '')] + $base;
        }
        $exp = $dbg['expires_at'] ?? null;
        $dataExp = $dbg['data_access_expires_at'] ?? null;
        $out = ['expires_at' => $exp, 'data_access_expires_at' => $dataExp, 'error' => $dbg['error'] ?? null, 'missing' => []];

        if (empty($dbg['is_valid'])) {
            $expired = stripos((string) ($dbg['error'] ?? ''), 'expired') !== false;
            return ['state' => $expired ? 'expired' : 'invalid'] + $out;
        }
        if (($exp !== null && $exp <= $now) || ($dataExp !== null && $dataExp <= $now)) {
            return ['state' => 'expired'] + $out;
        }
        $required = self::REQUIRED_SCOPES[$platform] ?? [];
        $scopes = (array) ($dbg['scopes'] ?? []);
        // debug_token بيرجّع scopes فاضية لبعض أنواع التوكنات: ما نحكمش إن الصلاحيات ناقصة من غير بيانات
        if ($scopes) {
            $out['missing'] = array_values(array_diff($required, $scopes));
            if ($out['missing']) {
                return ['state' => 'missing_permissions'] + $out;
            }
        }
        $soon = $now + self::WARN_DAYS * 86400;
        if (($exp !== null && $exp <= $soon) || ($dataExp !== null && $dataExp <= $soon)) {
            return ['state' => 'expiring'] + $out;
        }
        return ['state' => 'ok'] + $out;
    }

    private function store(int $connectionId, ?int $userId, string $platform, array $e): void
    {
        $db = Database::getInstance();
        $prev = $db->query('SELECT state, notified_state FROM social_connection_health WHERE platform_connection_id = ?', [$connectionId]);
        $prevNotified = $prev ? (string) ($prev[0]['notified_state'] ?? '') : '';
        $fmt = static fn (?int $ts): ?string => $ts ? gmdate('Y-m-d H:i:s', $ts) : null;

        $bad = in_array($e['state'], ['expired', 'invalid', 'missing_permissions', 'expiring'], true);
        $notify = $bad && $userId && $prevNotified !== $e['state'];
        $notifiedState = $notify ? $e['state'] : ($e['state'] === 'ok' ? null : ($prevNotified ?: null));

        $db->query(
            "INSERT INTO social_connection_health (platform_connection_id, state, token_expires_at, data_access_expires_at, missing_scopes, last_error, checked_at, notified_state, notified_at)
             VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), ?, " . ($notify ? 'UTC_TIMESTAMP()' : 'NULL') . ")
             ON DUPLICATE KEY UPDATE state = VALUES(state), token_expires_at = VALUES(token_expires_at), data_access_expires_at = VALUES(data_access_expires_at),
                 missing_scopes = VALUES(missing_scopes), last_error = VALUES(last_error), checked_at = UTC_TIMESTAMP(), notified_state = VALUES(notified_state)"
                . ($notify ? ', notified_at = UTC_TIMESTAMP()' : ''),
            [$connectionId, $e['state'], $fmt($e['expires_at']), $fmt($e['data_access_expires_at']), $e['missing'] ? implode(',', $e['missing']) : null, $e['error'] ? mb_substr((string) $e['error'], 0, 290) : null, $notifiedState]
        );

        if ($notify && class_exists('Notification')) {
            [$title, $body] = self::message($e['state'], $platform, $e);
            try {
                Notification::notify($userId, 'social_connection_health', $title, $body, '/social');
            } catch (Throwable $ex) {
                Logger::warning('Social health notification failed', ['connection_id' => $connectionId]);
            }
        }
    }

    /** @return array{0:string,1:string} */
    public static function message(string $state, string $platform, array $e): array
    {
        $p = $platform === 'instagram' ? 'إنستجرام' : 'فيسبوك';
        return match ($state) {
            'expired', 'invalid' => ["ربط {$p} محتاج إعادة ربط", "انتهت صلاحية ربط {$p} أو اتلغى. المنشورات المجدولة هتفشل لحد ما تعيد الربط من صفحة السوشيال."],
            'missing_permissions' => ["صلاحيات ناقصة في ربط {$p}", 'الصلاحيات الناقصة: ' . implode(', ', $e['missing'] ?? []) . '. أعد الربط ووافق على كل الصلاحيات.'],
            'expiring' => ["ربط {$p} قرّب ينتهي", 'أعد الربط من صفحة السوشيال قبل ما ينتهي علشان المنشورات المجدولة ما تتعطلش.'],
            default => ['حالة ربط ' . $p, $state],
        };
    }

    public static function ensureScheduled(): void
    {
        try {
            if (!Database::getInstance()->query("SHOW TABLES LIKE 'social_connection_health'")) {
                return; // قبل migration الموثوقية
            }
            if (!Database::getInstance()->query("SELECT id FROM jobs WHERE job_class = 'CheckSocialConnectionsHealthJob' AND status = 'pending' LIMIT 1")) {
                self::schedule();
            }
        } catch (Throwable $e) {
        }
    }

    private static function schedule(): void
    {
        try {
            Container::getInstance()->make(QueueManager::class)->push(self::class, [], 'social', self::INTERVAL_SECONDS);
        } catch (Throwable $e) {
            Logger::warning('Health job reschedule failed', ['message' => $e->getMessage()]);
        }
    }
}
