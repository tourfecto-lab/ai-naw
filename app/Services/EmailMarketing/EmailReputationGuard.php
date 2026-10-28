<?php

/**
 * Tourfecto - Email Reputation Guard (حماية سمعة الإرسال + حدود الباقة)
 * @version 1.0.0
 *
 * طبقة حماية بين الحملات والإرسال الفعلي، هدفها حماية سمعة دومين/IP المنصة:
 *
 *   1) حدود الاستهلاك: ساعي/يومي/شهري + سقف معدل (Throttle) لكل دقيقة، مع
 *      أولوية: تخصيص الحساب > الباقة (EMAIL_PLAN_LIMITS) > الافتراضي.
 *   2) Warm-up تدريجي للحسابات الجديدة (الحسابات القديمة المعروفة تُعفى تلقائيًا).
 *   3) حجز الحصة atomically (reserve ثم refund) — يمنع تجاوز الحد لو اشتغلت
 *      حملتان لنفس العميل بالتوازي.
 *   4) إيقاف تلقائي: bounce/complaint فوق العتبة => إيقاف الحملة، وعلى مستوى
 *      الحساب (نافذة 30 يوم) => إيقاف الحساب كله + تنبيه الأدمن.
 *   5) تصنيف أخطاء الإرسال (مؤقت/عنوان غير صالح/حظر/دائم) وجدولة إعادة المحاولة
 *      بـ exponential backoff لكل مستلم.
 *   6) حظر عام (Global suppression) لإيميل/دومين على مستوى المنصة.
 *   7) سجل تدقيق لكل تدخل (نظام/أدمن).
 *   8) مراجعة أول حملة (اختياري: EMAIL_REQUIRE_FIRST_CAMPAIGN_REVIEW).
 *
 * التصميم: الدوال "النقية" (static) بلا DB وسهلة الاختبار: classifyError,
 * retryDelaySeconds, warmupCap, evaluateRates, nextBoundary.
 * الأوقات في جداولنا UTC (gmdate). الساعة قابلة للحقن للاختبار.
 *
 * الإعدادات (constants اختيارية في .env/config):
 *   EMAIL_DEFAULT_HOURLY_LIMIT / _DAILY_LIMIT / _MONTHLY_LIMIT / _PER_MINUTE_LIMIT
 *   EMAIL_PLAN_LIMITS = ['free'=>['daily'=>500,'monthly'=>5000], ...]
 *   EMAIL_BOUNCE_PAUSE_RATE (5.0) / EMAIL_COMPLAINT_PAUSE_RATE (0.3)
 *   EMAIL_REQUIRE_FIRST_CAMPAIGN_REVIEW (false)
 */
class EmailReputationGuard
{
    public const DEFAULT_HOURLY = 1500;
    public const DEFAULT_DAILY = 5000;
    public const DEFAULT_MONTHLY = 100000;
    public const DEFAULT_PER_MINUTE = 100;

    public const BOUNCE_WARN = 2.0;
    public const BOUNCE_PAUSE = 5.0;
    public const COMPLAINT_WARN = 0.1;
    public const COMPLAINT_PAUSE = 0.3;

    public const CAMPAIGN_MIN_SAMPLE = 100;
    public const ACCOUNT_MIN_SAMPLE = 500;
    /** عدد شكاوى مطلق يوقف الحملة حتى لو العينة صغيرة */
    public const ABSOLUTE_COMPLAINTS = 5;
    public const ACCOUNT_WINDOW_DAYS = 30;
    public const MAX_ATTEMPTS = 3;
    public const ACCOUNT_PAUSE_PREFIX = '[account] ';
    public const USER_PAUSE_PREFIX = '[user] ';

    /** سقف الإرسال اليومي لكل يوم warm-up (اليوم 0 = أول يوم إرسال) */
    private const WARMUP_SCHEDULE = [50, 100, 250, 500, 1000, 2000, 3500, 5000];

    public const CLASS_TEMPORARY = 'temporary';
    public const CLASS_INVALID_RECIPIENT = 'invalid_recipient';
    public const CLASS_BLOCKED = 'blocked';
    public const CLASS_PERMANENT = 'permanent';
    public const CLASS_UNKNOWN = 'unknown';

    /** @var Database */
    private $db;
    /** @var callable */
    private $clock;
    /** @var callable|null  fn(int $userId): ?string  اسم الباقة */
    private $planResolver;
    /** @var callable|null  fn(string $level, string $message, array $context): void */
    private $notifier;

    public function __construct(?callable $clock = null, ?callable $planResolver = null, ?callable $notifier = null, $db = null)
    {
        $this->db = $db ?: Database::getInstance();
        $this->clock = $clock ?: 'time';
        $this->planResolver = $planResolver;
        $this->notifier = $notifier;
    }

    // ==================================================================
    // دوال نقية (بلا DB)
    // ==================================================================

    /**
     * تصنيف خطأ الإرسال. المهم: الأولوية للأكواد المحسّنة (5.1.1) ثم أكواد SMTP
     * ثم الكلمات المفتاحية. بس CLASS_TEMPORARY هو اللي يُعاد محاولته.
     */
    public static function classifyError(?string $error): string
    {
        $e = strtolower(trim((string) $error));
        if ($e === '') {
            return self::CLASS_UNKNOWN;
        }

        // 1) enhanced status code: x.y.z
        if (preg_match('/(?<![\d.])([245])\.(\d{1,3})\.(\d{1,3})(?![\d.])/', $e, $m)) {
            $cls = (int) $m[1];
            $subject = (int) $m[2];
            $detail = (int) $m[3];
            if ($cls === 4) {
                return self::CLASS_TEMPORARY;
            }
            if ($cls === 5) {
                if ($subject === 1 && in_array($detail, [1, 2, 3, 6, 10], true)) {
                    return self::CLASS_INVALID_RECIPIENT;
                }
                if ($subject === 2 && $detail === 1) {
                    return self::CLASS_INVALID_RECIPIENT; // mailbox disabled
                }
                if ($subject === 2 && $detail === 2) {
                    return self::CLASS_TEMPORARY; // mailbox full: قد يفضى
                }
                if ($subject === 7) {
                    return self::CLASS_BLOCKED;
                }
                return self::CLASS_PERMANENT;
            }
        }

        // 2) SMTP reply code (قائمة صريحة عشان منلخبطش مع أرقام بورتات/ids)
        if (preg_match('/(?<![\d.])(421|450|451|452|454|455|521|550|551|552|553|554|530|535)(?![\d.])/', $e, $m)) {
            $code = (int) $m[1];
            if (in_array($code, [421, 450, 451, 452, 454, 455], true)) {
                return self::CLASS_TEMPORARY;
            }
            if (in_array($code, [550, 551, 553], true) && self::hasAny($e, ['user unknown', 'no such user', 'does not exist', 'unknown user', 'invalid recipient', 'mailbox unavailable', 'recipient rejected', 'address rejected', 'no mailbox'])) {
                return self::CLASS_INVALID_RECIPIENT;
            }
            if (self::hasAny($e, ['spam', 'blacklist', 'blocklist', 'blocked', 'denied', 'policy', 'reputation', 'rejected due', 'dnsbl', 'not authorized'])) {
                return self::CLASS_BLOCKED;
            }
            return self::CLASS_PERMANENT;
        }

        // 3) كلمات مفتاحية
        if (self::hasAny($e, ['user unknown', 'no such user', 'unknown user', 'does not exist', 'invalid recipient', 'recipient address rejected', 'no mailbox', 'account disabled'])) {
            return self::CLASS_INVALID_RECIPIENT;
        }
        if (self::hasAny($e, ['spam', 'blacklist', 'blocklist', 'dnsbl', 'blocked by', 'reputation'])) {
            return self::CLASS_BLOCKED;
        }
        if (self::hasAny($e, ['timeout', 'timed out', 'try again', 'temporar', 'greylist', 'graylist', 'rate limit', 'too many', 'throttl', 'connection refused', 'connection reset', 'could not connect', 'could not resolve', 'service unavailable', 'mailbox full', 'over quota'])) {
            return self::CLASS_TEMPORARY;
        }
        return self::CLASS_UNKNOWN;
    }

    /** الأخطاء اللي تعني إن العنوان نفسه ميت => نعامله Hard bounce. */
    public static function isHardBounce(string $errorClass): bool
    {
        return $errorClass === self::CLASS_INVALID_RECIPIENT;
    }

    /** تأخير إعادة المحاولة (ثواني): 5د، 20د، 80د ... (±10% jitter اختياري). */
    public static function retryDelaySeconds(int $attempt, bool $jitter = true): int
    {
        $attempt = max(1, min(6, $attempt));
        $base = 300 * (4 ** ($attempt - 1));
        if ($jitter) {
            $spread = (int) round($base * 0.1);
            $base += random_int(-$spread, $spread);
        }
        return max(60, $base);
    }

    /** سقف warm-up اليومي أو null لو خلص الـ warm-up/غير مفعّل. */
    public static function warmupCap(?string $startedAtUtc, int $now): ?int
    {
        if ($startedAtUtc === null || $startedAtUtc === '') {
            return self::WARMUP_SCHEDULE[0];
        }
        $start = strtotime($startedAtUtc . ' UTC');
        if ($start === false) {
            return null;
        }
        $day = (int) floor(max(0, $now - $start) / 86400);
        return $day >= count(self::WARMUP_SCHEDULE) ? null : self::WARMUP_SCHEDULE[$day];
    }

    /**
     * تقييم نسب الارتداد/الشكاوى.
     * @return array{action:string,bounce_rate:float,complaint_rate:float,insufficient:bool,reasons:string[]}
     *   action: ok | warn | pause
     */
    public static function evaluateRates(
        int $attempted,
        int $bounced,
        int $complaints,
        int $minSample,
        float $bouncePause,
        float $complaintPause,
        float $bounceWarn = self::BOUNCE_WARN,
        float $complaintWarn = self::COMPLAINT_WARN
    ): array {
        $attempted = max(0, $attempted);
        $bounceRate = $attempted > 0 ? round($bounced / $attempted * 100, 3) : 0.0;
        $complaintRate = $attempted > 0 ? round($complaints / $attempted * 100, 3) : 0.0;
        $out = ['action' => 'ok', 'bounce_rate' => $bounceRate, 'complaint_rate' => $complaintRate, 'insufficient' => false, 'reasons' => []];

        if ($complaints >= self::ABSOLUTE_COMPLAINTS && $attempted < $minSample) {
            $out['action'] = 'pause';
            $out['reasons'][] = "complaints_absolute:{$complaints}";
            return $out;
        }
        if ($attempted < $minSample) {
            $out['insufficient'] = true;
            return $out;
        }
        if ($bounceRate >= $bouncePause) {
            $out['action'] = 'pause';
            $out['reasons'][] = "bounce_rate:{$bounceRate}>={$bouncePause}";
        }
        if ($complaintRate >= $complaintPause) {
            $out['action'] = 'pause';
            $out['reasons'][] = "complaint_rate:{$complaintRate}>={$complaintPause}";
        }
        if ($out['action'] === 'ok' && ($bounceRate >= $bounceWarn || $complaintRate >= $complaintWarn)) {
            $out['action'] = 'warn';
            $out['reasons'][] = "approaching_threshold";
        }
        return $out;
    }

    /** ثواني لحد بداية الساعة/اليوم/الشهر الجاي (UTC). */
    public static function secondsToBoundary(string $unit, int $now): int
    {
        if ($unit === 'hour') {
            $next = (int) (floor($now / 3600) + 1) * 3600;
        } elseif ($unit === 'day') {
            $next = (int) (floor($now / 86400) + 1) * 86400;
        } else {
            $next = (int) strtotime(gmdate('Y-m-01 00:00:00', $now) . ' UTC +1 month');
        }
        return max(1, $next - $now);
    }

    /** مقطع SQL: المستلم في الحظر العام (إيميل أو دومين). $emailExpr مثل s.email */
    public static function globallySuppressedSql(string $emailExpr): string
    {
        return "EXISTS (SELECT 1 FROM email_global_suppressions gs WHERE "
            . "(gs.kind = 'email' AND gs.value = LOWER({$emailExpr})) "
            . "OR (gs.kind = 'domain' AND gs.value = LOWER(SUBSTRING_INDEX({$emailExpr}, '@', -1))))";
    }

    /** نقيض الدالة السابقة. */
    public static function notGloballySuppressedSql(string $emailExpr): string
    {
        return 'NOT ' . self::globallySuppressedSql($emailExpr);
    }

    private static function hasAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $n) {
            if (strpos($haystack, $n) !== false) {
                return true;
            }
        }
        return false;
    }

    // ==================================================================
    // الحساب والحدود
    // ==================================================================

    private function now(): int
    {
        return (int) call_user_func($this->clock);
    }

    private function ts(?int $t = null): string
    {
        return gmdate('Y-m-d H:i:s', $t ?? $this->now());
    }

    private function pdo(): PDO
    {
        return $this->db->getConnection();
    }

    private static function cfg(string $name, $default)
    {
        return defined($name) ? constant($name) : $default;
    }

    /** يرجع صف الحساب (ينشئه لو مش موجود). الحسابات ذات التاريخ تُعفى من warm-up والمراجعة. */
    public function account(int $userId): array
    {
        $rows = $this->db->query("SELECT * FROM email_account_limits WHERE user_id = ? LIMIT 1", [$userId]);
        if (!empty($rows)) {
            return $rows[0];
        }
        $established = (int) ($this->db->query(
            "SELECT COUNT(*) AS n FROM email_campaign_recipients r
             JOIN email_campaigns c ON c.id = r.campaign_id
             WHERE c.user_id = ? AND r.sent_at IS NOT NULL",
            [$userId]
        )[0]['n'] ?? 0) > 0;

        try {
            $st = $this->pdo()->prepare(
                "INSERT INTO email_account_limits (user_id, status, warmup_enabled, review_approved_at, updated_at)
                 VALUES (?, 'active', ?, ?, ?)"
            );
            $st->execute([$userId, $established ? 0 : 1, $established ? $this->ts() : null, $this->ts()]);
        } catch (PDOException $e) {
            // سباق: حد تاني أنشأه — نكمل قراءة
        }
        $rows = $this->db->query("SELECT * FROM email_account_limits WHERE user_id = ? LIMIT 1", [$userId]);
        return $rows[0] ?? ['user_id' => $userId, 'status' => 'active', 'warmup_enabled' => 0];
    }

    /**
     * الحدود الفعلية: تخصيص الحساب > الباقة > الافتراضي.
     * @return array{hourly:int,daily:int,monthly:int,per_minute:int,bounce_pause:float,complaint_pause:float}
     */
    public function effectiveLimits(int $userId, ?array $account = null): array
    {
        $account = $account ?? $this->account($userId);
        $plan = [];
        if ($this->planResolver && defined('EMAIL_PLAN_LIMITS')) {
            $name = (string) call_user_func($this->planResolver, $userId);
            $all = (array) constant('EMAIL_PLAN_LIMITS');
            $plan = (array) ($all[$name] ?? []);
        }
        $pick = function (string $col, string $planKey, string $const, int $default) use ($account, $plan): int {
            if (isset($account[$col]) && $account[$col] !== null && (int) $account[$col] > 0) {
                return (int) $account[$col];
            }
            if (isset($plan[$planKey]) && (int) $plan[$planKey] > 0) {
                return (int) $plan[$planKey];
            }
            return (int) self::cfg($const, $default);
        };
        return [
            'hourly' => $pick('hourly_limit', 'hourly', 'EMAIL_DEFAULT_HOURLY_LIMIT', self::DEFAULT_HOURLY),
            'daily' => $pick('daily_limit', 'daily', 'EMAIL_DEFAULT_DAILY_LIMIT', self::DEFAULT_DAILY),
            'monthly' => $pick('monthly_limit', 'monthly', 'EMAIL_DEFAULT_MONTHLY_LIMIT', self::DEFAULT_MONTHLY),
            'per_minute' => $pick('per_minute_limit', 'per_minute', 'EMAIL_DEFAULT_PER_MINUTE_LIMIT', self::DEFAULT_PER_MINUTE),
            'bounce_pause' => isset($account['max_bounce_rate']) && $account['max_bounce_rate'] !== null
                ? (float) $account['max_bounce_rate'] : (float) self::cfg('EMAIL_BOUNCE_PAUSE_RATE', self::BOUNCE_PAUSE),
            'complaint_pause' => isset($account['max_complaint_rate']) && $account['max_complaint_rate'] !== null
                ? (float) $account['max_complaint_rate'] : (float) self::cfg('EMAIL_COMPLAINT_PAUSE_RATE', self::COMPLAINT_PAUSE),
        ];
    }

    /** الاستهلاك الحالي (ساعة/يوم/شهر UTC). */
    public function usage(int $userId): array
    {
        $now = $this->now();
        $hour = gmdate('Y-m-d H:00:00', $now);
        $day = gmdate('Y-m-d 00:00:00', $now);
        $month = gmdate('Y-m-01 00:00:00', $now);
        $sum = function (string $from) use ($userId): int {
            return (int) ($this->db->query(
                "SELECT COALESCE(SUM(sent_count), 0) AS n FROM email_send_usage WHERE user_id = ? AND bucket_start >= ?",
                [$userId, $from]
            )[0]['n'] ?? 0);
        };
        return ['hour' => $sum($hour), 'day' => $sum($day), 'month' => $sum($month)];
    }

    /** لقطة كاملة للعرض (للعميل: عدّاد الاستهلاك، وللأدمن: التفاصيل). */
    public function snapshot(int $userId): array
    {
        $acct = $this->account($userId);
        $lim = $this->effectiveLimits($userId, $acct);
        $use = $this->usage($userId);
        $warm = (int) ($acct['warmup_enabled'] ?? 0) === 1 && !empty($acct['warmup_started_at'])
            ? self::warmupCap($acct['warmup_started_at'], $this->now())
            : (((int) ($acct['warmup_enabled'] ?? 0) === 1) ? self::WARMUP_SCHEDULE[0] : null);
        $dailyCap = $warm !== null ? min($lim['daily'], $warm) : $lim['daily'];
        return [
            'status' => $acct['status'] ?? 'active',
            'pause_reason' => $acct['pause_reason'] ?? null,
            'limits' => $lim + ['daily_effective' => $dailyCap, 'warmup_cap' => $warm],
            'usage' => $use,
            'remaining' => [
                'hour' => max(0, $lim['hourly'] - $use['hour']),
                'day' => max(0, $dailyCap - $use['day']),
                'month' => max(0, $lim['monthly'] - $use['month']),
            ],
        ];
    }

    // ==================================================================
    // Preflight + حجز الحصة
    // ==================================================================

    /**
     * هل الحساب مسموح له يبعت؟ (حالة الحساب + مراجعة أول حملة)
     * @return array{allowed:bool,code:?string,message:?string}
     */
    public function preflight(int $userId, int $campaignId = 0): array
    {
        $acct = $this->account($userId);
        $status = (string) ($acct['status'] ?? 'active');
        if ($status !== 'active') {
            return ['allowed' => false, 'code' => 'account_' . $status,
                'message' => 'إرسال الحملات موقوف لهذا الحساب' . (!empty($acct['pause_reason']) ? ': ' . $acct['pause_reason'] : '')];
        }
        if ((bool) self::cfg('EMAIL_REQUIRE_FIRST_CAMPAIGN_REVIEW', false) && empty($acct['review_approved_at'])) {
            return ['allowed' => false, 'code' => 'review_required',
                'message' => 'أول حملة تحتاج مراجعة من الإدارة قبل الإرسال'];
        }
        return ['allowed' => true, 'code' => null, 'message' => null];
    }

    /**
     * يحجز حصة إرسال لـ $want رسالة. يرجع المسموح فعليًا (قد يكون 0).
     * @return array{granted:int,retry_after:int,reason:?string,bucket:?string}
     */
    public function reserve(int $userId, int $want): array
    {
        $want = max(0, $want);
        if ($want === 0) {
            return ['granted' => 0, 'retry_after' => 0, 'reason' => null, 'bucket' => null];
        }
        $lockName = 'email_quota:' . $userId;
        $locked = $this->acquireLock($lockName);
        try {
            $acct = $this->account($userId);
            $lim = $this->effectiveLimits($userId, $acct);
            $use = $this->usage($userId);
            $now = $this->now();

            $remaining = [
                'hourly' => $lim['hourly'] - $use['hour'],
                'daily' => $lim['daily'] - $use['day'],
                'monthly' => $lim['monthly'] - $use['month'],
            ];
            $warmup = null;
            if ((int) ($acct['warmup_enabled'] ?? 0) === 1) {
                $warmup = self::warmupCap($acct['warmup_started_at'] ?? null, $now);
                if ($warmup !== null) {
                    $remaining['warmup'] = $warmup - $use['day'];
                }
            }

            $granted = min($want, max(1, $lim['per_minute']));
            $binding = $granted < $want ? 'per_minute' : null;
            foreach ($remaining as $name => $left) {
                if ($left < $granted) {
                    $granted = max(0, $left);
                    $binding = $name;
                }
            }

            if ($granted <= 0) {
                if ($binding === 'hourly') {
                    $retry = self::secondsToBoundary('hour', $now);
                } elseif ($binding === 'daily' || $binding === 'warmup') {
                    $retry = self::secondsToBoundary('day', $now);
                } else {
                    $retry = self::secondsToBoundary('month', $now);
                }
                // نعيد التقييم كل ساعة على الأكثر (ممكن الأدمن يرفع الحد)
                return ['granted' => 0, 'retry_after' => min(3600, $retry + 5), 'reason' => 'limit_' . $binding, 'bucket' => null];
            }

            // أول إرسال: نبدأ ساعة warm-up
            if ((int) ($acct['warmup_enabled'] ?? 0) === 1 && empty($acct['warmup_started_at'])) {
                $st = $this->pdo()->prepare("UPDATE email_account_limits SET warmup_started_at = ? WHERE user_id = ? AND warmup_started_at IS NULL");
                $st->execute([$this->ts($now), $userId]);
            }

            $bucket = gmdate('Y-m-d H:00:00', $now);
            $this->bumpUsage($userId, $bucket, $granted);

            return [
                'granted' => $granted,
                'retry_after' => $binding === 'per_minute' ? 60 : ($granted < $want ? min(3600, self::secondsToBoundary('hour', $now) + 5) : 0),
                'reason' => $binding !== null ? 'limit_' . $binding : null,
                'bucket' => $bucket,
            ];
        } finally {
            $this->releaseLock($lockName, $locked);
        }
    }

    /** يرد الحصة غير المستخدمة (مثلًا مستلمين اتحظروا/اتخطّوا قبل الإرسال). */
    public function refund(int $userId, int $n, ?string $bucket): void
    {
        if ($n <= 0 || $bucket === null) {
            return;
        }
        $st = $this->pdo()->prepare(
            "UPDATE email_send_usage SET sent_count = CASE WHEN sent_count > ? THEN sent_count - ? ELSE 0 END
             WHERE user_id = ? AND bucket_start = ?"
        );
        $st->execute([$n, $n, $userId, $bucket]);
    }

    private function bumpUsage(int $userId, string $bucket, int $n): void
    {
        $up = $this->pdo()->prepare("UPDATE email_send_usage SET sent_count = sent_count + ? WHERE user_id = ? AND bucket_start = ?");
        $up->execute([$n, $userId, $bucket]);
        if ($up->rowCount() > 0) {
            return;
        }
        try {
            $this->pdo()->prepare("INSERT INTO email_send_usage (user_id, bucket_start, sent_count) VALUES (?, ?, ?)")
                ->execute([$userId, $bucket, $n]);
        } catch (PDOException $e) {
            $up->execute([$n, $userId, $bucket]); // سباق: الصف اتعمل
        }
    }

    private function acquireLock(string $name): bool
    {
        try {
            if ($this->pdo()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
                $st = $this->pdo()->prepare("SELECT GET_LOCK(?, 5)");
                $st->execute([$name]);
                return (int) $st->fetchColumn() === 1;
            }
        } catch (Throwable $e) {
        }
        return false;
    }

    private function releaseLock(string $name, bool $locked): void
    {
        if (!$locked) {
            return;
        }
        try {
            $st = $this->pdo()->prepare("SELECT RELEASE_LOCK(?)");
            $st->execute([$name]);
        } catch (Throwable $e) {
        }
    }

    // ==================================================================
    // مراقبة الصحة + الإيقاف التلقائي
    // ==================================================================

    /** أحدث baseline (آخر recipient id وقت الاستئناف) للحساب أو الحملة. */
    private function baselineId(int $userId, ?int $campaignId): int
    {
        $type = $campaignId ? 'campaign_resumed' : 'account_resumed';
        $sql = "SELECT details FROM email_reputation_events WHERE user_id = ? AND event_type = ?"
            . ($campaignId ? " AND campaign_id = ?" : "") . " ORDER BY id DESC LIMIT 1";
        $rows = $this->db->query($sql, $campaignId ? [$userId, $type, $campaignId] : [$userId, $type]);
        if (empty($rows)) {
            return 0;
        }
        $d = json_decode((string) $rows[0]['details'], true);
        return (int) ($d['baseline_recipient_id'] ?? 0);
    }

    /** @return array{attempted:int,bounced:int,complaints:int} */
    public function campaignCounts(int $campaignId, int $userId = 0): array
    {
        $base = $userId > 0 ? $this->baselineId($userId, $campaignId) : 0;
        $r = $this->db->query(
            "SELECT COUNT(*) AS attempted,
                    COALESCE(SUM(CASE WHEN bounced_at IS NOT NULL THEN 1 ELSE 0 END), 0) AS bounced,
                    COALESCE(SUM(CASE WHEN complained_at IS NOT NULL THEN 1 ELSE 0 END), 0) AS complaints
             FROM email_campaign_recipients
             WHERE campaign_id = ? AND id > ? AND (sent_at IS NOT NULL OR bounced_at IS NOT NULL)",
            [$campaignId, $base]
        )[0] ?? [];
        return ['attempted' => (int) ($r['attempted'] ?? 0), 'bounced' => (int) ($r['bounced'] ?? 0), 'complaints' => (int) ($r['complaints'] ?? 0)];
    }

    /** @return array{attempted:int,bounced:int,complaints:int} */
    public function accountCounts(int $userId, int $days = self::ACCOUNT_WINDOW_DAYS): array
    {
        $base = $this->baselineId($userId, null);
        $cut = $this->ts($this->now() - $days * 86400);
        $r = $this->db->query(
            "SELECT COUNT(*) AS attempted,
                    COALESCE(SUM(CASE WHEN r.bounced_at IS NOT NULL THEN 1 ELSE 0 END), 0) AS bounced,
                    COALESCE(SUM(CASE WHEN r.complained_at IS NOT NULL THEN 1 ELSE 0 END), 0) AS complaints
             FROM email_campaign_recipients r
             JOIN email_campaigns c ON c.id = r.campaign_id
             WHERE c.user_id = ? AND r.id > ? AND (r.sent_at >= ? OR r.bounced_at >= ?)",
            [$userId, $base, $cut, $cut]
        )[0] ?? [];
        return ['attempted' => (int) ($r['attempted'] ?? 0), 'bounced' => (int) ($r['bounced'] ?? 0), 'complaints' => (int) ($r['complaints'] ?? 0)];
    }

    /**
     * يقيّم صحة الحملة والحساب ويوقف تلقائيًا عند تجاوز العتبات.
     * @return array{action:string,campaign:array,account:array}
     */
    public function checkHealth(int $userId, int $campaignId = 0): array
    {
        $lim = $this->effectiveLimits($userId);
        $result = ['action' => 'none', 'campaign' => null, 'account' => null];

        if ($campaignId > 0) {
            $c = $this->campaignCounts($campaignId, $userId);
            $eval = self::evaluateRates($c['attempted'], $c['bounced'], $c['complaints'], self::CAMPAIGN_MIN_SAMPLE, $lim['bounce_pause'], $lim['complaint_pause']);
            $result['campaign'] = $eval + $c;
            if ($eval['action'] === 'pause') {
                if ($this->pauseCampaign($userId, $campaignId, 'إيقاف تلقائي: ' . implode(', ', $eval['reasons']), null)) {
                    $result['action'] = 'paused_campaign';
                    $this->notify('warning', "تم إيقاف حملة #{$campaignId} تلقائيًا للعميل #{$userId}", ['rates' => $eval]);
                }
            } elseif ($eval['action'] === 'warn') {
                $this->logOnce($userId, $campaignId, 'rate_warning', ['rates' => $eval], 86400);
            }
        }

        $a = $this->accountCounts($userId);
        $evalA = self::evaluateRates($a['attempted'], $a['bounced'], $a['complaints'], self::ACCOUNT_MIN_SAMPLE, $lim['bounce_pause'], $lim['complaint_pause']);
        $result['account'] = $evalA + $a;
        if ($evalA['action'] === 'pause') {
            if ($this->pauseAccount($userId, 'إيقاف تلقائي (سمعة الحساب): ' . implode(', ', $evalA['reasons']), null)) {
                $result['action'] = 'paused_account';
                $this->notify('critical', "تم إيقاف إرسال العميل #{$userId} تلقائيًا بسبب معدلات الارتداد/الشكاوى", ['rates' => $evalA]);
            }
        } elseif ($evalA['action'] === 'warn') {
            $this->logOnce($userId, null, 'rate_warning_account', ['rates' => $evalA], 86400);
        }
        return $result;
    }

    /**
     * يسجّل ارتداد/شكوى على آخر مستلم بنفس الإيميل عند هذا العميل ثم يعيد تقييم الصحة.
     * آمن للتكرار (idempotent). يُستدعى من ContactManagementService::recordDeliveryIssue.
     * @return int|null campaign_id المتأثرة
     */
    public function onDeliveryIssue(int $userId, string $email, string $type, ?string $reason = null, bool $checkHealth = true): ?int
    {
        if (!in_array($type, ['bounce', 'complaint', 'spam'], true)) {
            return null;
        }
        $email = strtolower(trim($email));
        $rows = $this->db->query(
            "SELECT r.id, r.campaign_id, r.bounced_at, r.complained_at
             FROM email_campaign_recipients r
             JOIN email_campaigns c ON c.id = r.campaign_id
             WHERE c.user_id = ? AND r.email = ? AND r.status IN ('sent','opened','clicked','failed','uncertain','sending','bounced','unsubscribed')
             ORDER BY r.id DESC LIMIT 1",
            [$userId, $email]
        );
        if (empty($rows)) {
            return null;
        }
        $row = $rows[0];
        $campaignId = (int) $row['campaign_id'];
        $now = $this->ts();

        if ($type === 'complaint') {
            if (empty($row['complained_at'])) {
                $this->pdo()->prepare("UPDATE email_campaign_recipients SET complained_at = ?, status = 'unsubscribed' WHERE id = ? AND complained_at IS NULL")
                    ->execute([$now, (int) $row['id']]);
            }
        } else {
            if (empty($row['bounced_at'])) {
                $this->pdo()->prepare("UPDATE email_campaign_recipients SET bounced_at = ?, status = 'bounced', error_message = COALESCE(?, error_message) WHERE id = ? AND bounced_at IS NULL")
                    ->execute([$now, $reason !== null ? substr($reason, 0, 1000) : null, (int) $row['id']]);
            }
        }

        if (class_exists('EmailTrackingService')) {
            try {
                (new EmailTrackingService())->recomputeCampaignCounts($campaignId);
            } catch (Throwable $e) {
            }
        }
        if ($checkHealth) {
            $this->checkHealth($userId, $campaignId);
        }
        return $campaignId;
    }

    // ==================================================================
    // إجراءات الإيقاف/الاستئناف (نظام أو أدمن) + تدقيق
    // ==================================================================

    /** @return bool true لو الحالة اتغيّرت فعلًا */
    public function pauseAccount(int $userId, string $reason, ?int $actorId = null, string $status = 'paused'): bool
    {
        $status = $status === 'suspended' ? 'suspended' : 'paused';
        $this->account($userId);
        $st = $this->pdo()->prepare(
            "UPDATE email_account_limits SET status = ?, pause_reason = ?, paused_at = ?, paused_by = ? WHERE user_id = ? AND status = 'active'"
        );
        $st->execute([$status, substr($reason, 0, 500), $this->ts(), $actorId, $userId]);
        if ($st->rowCount() === 0) {
            return false;
        }
        // أي حملة بتبعت دلوقتي تتوقف
        // البادئة [account] تميّز الحملات الموقوفة بسبب إيقاف الحساب (بتتستأنف مع الحساب)
        $this->pdo()->prepare(
            "UPDATE email_campaigns SET status = 'paused', paused_reason = ?, paused_at = ? WHERE user_id = ? AND status = 'sending'"
        )->execute([self::ACCOUNT_PAUSE_PREFIX . substr($reason, 0, 480), $this->ts(), $userId]);
        $this->logEvent($userId, null, $status === 'suspended' ? 'account_suspended' : 'account_paused', $actorId, ['reason' => $reason]);
        return true;
    }

    public function resumeAccount(int $userId, ?int $actorId = null, ?string $note = null): bool
    {
        $this->account($userId);
        $st = $this->pdo()->prepare(
            "UPDATE email_account_limits SET status = 'active', pause_reason = NULL, paused_at = NULL, paused_by = NULL WHERE user_id = ? AND status <> 'active'"
        );
        $st->execute([$userId]);
        if ($st->rowCount() === 0) {
            return false;
        }
        // baseline: نسب الارتداد القديمة متتحسبش تاني بعد قرار الأدمن
        $this->logEvent($userId, null, 'account_resumed', $actorId, ['note' => $note, 'baseline_recipient_id' => $this->maxRecipientId()]);
        $this->requeueUserCampaigns($userId);
        return true;
    }

    public function pauseCampaign(int $userId, int $campaignId, string $reason, ?int $actorId = null): bool
    {
        $st = $this->pdo()->prepare(
            "UPDATE email_campaigns SET status = 'paused', paused_reason = ?, paused_at = ? WHERE id = ? AND user_id = ? AND status = 'sending'"
        );
        $st->execute([substr($reason, 0, 500), $this->ts(), $campaignId, $userId]);
        if ($st->rowCount() === 0) {
            return false;
        }
        $this->logEvent($userId, $campaignId, 'campaign_paused', $actorId, ['reason' => $reason]);
        return true;
    }

    /** استئناف حملة موقوفة (الحساب لازم يكون active). */
    public function resumeCampaign(int $userId, int $campaignId, ?int $actorId = null): array
    {
        $pre = $this->preflight($userId, $campaignId);
        if (!$pre['allowed']) {
            return ['success' => false, 'error' => $pre['message']];
        }
        $st = $this->pdo()->prepare(
            "UPDATE email_campaigns SET status = 'sending', paused_reason = NULL, paused_at = NULL WHERE id = ? AND user_id = ? AND status = 'paused'"
        );
        $st->execute([$campaignId, $userId]);
        if ($st->rowCount() === 0) {
            return ['success' => false, 'error' => 'الحملة غير موقوفة'];
        }
        $this->logEvent($userId, $campaignId, 'campaign_resumed', $actorId, ['baseline_recipient_id' => $this->maxRecipientId()]);
        $this->pushJob($userId, $campaignId, 1);
        return ['success' => true];
    }

    /** إيقاف يدوي من العميل نفسه لحملة قيد الإرسال (يقدر يستأنفها بنفسه). */
    public function userPauseCampaign(int $userId, int $campaignId): array
    {
        $ok = $this->pauseCampaign($userId, $campaignId, self::USER_PAUSE_PREFIX . 'أوقفها العميل', $userId);
        return $ok ? ['success' => true] : ['success' => false, 'error' => 'يمكن إيقاف الحملات قيد الإرسال فقط'];
    }

    /** استئناف من العميل: مسموح فقط للحملات اللي هو أوقفها بنفسه (مش الإيقاف التلقائي/الإداري). */
    public function userResumeCampaign(int $userId, int $campaignId): array
    {
        $row = $this->db->query("SELECT status, paused_reason FROM email_campaigns WHERE id = ? AND user_id = ? LIMIT 1", [$campaignId, $userId]);
        if (empty($row) || $row[0]['status'] !== 'paused') {
            return ['success' => false, 'error' => 'الحملة غير موقوفة'];
        }
        if (strpos((string) $row[0]['paused_reason'], self::USER_PAUSE_PREFIX) !== 0) {
            return ['success' => false, 'error' => 'الحملة موقوفة من الإدارة/النظام ولا يمكن استئنافها إلا بعد المراجعة'];
        }
        return $this->resumeCampaign($userId, $campaignId, $userId);
    }

    public function approveReview(int $userId, int $actorId): void
    {
        $this->account($userId);
        $this->pdo()->prepare("UPDATE email_account_limits SET review_approved_at = ?, review_approved_by = ? WHERE user_id = ?")
            ->execute([$this->ts(), $actorId, $userId]);
        $this->logEvent($userId, null, 'review_approved', $actorId, []);
        $this->requeueUserCampaigns($userId);
    }

    /**
     * تعديل حدود العميل. القيم: عدد صحيح موجب أو null (= الرجوع للافتراضي/الباقة).
     * المفاتيح المسموحة: hourly_limit, daily_limit, monthly_limit, per_minute_limit,
     * max_bounce_rate, max_complaint_rate, warmup_enabled.
     */
    public function setLimits(int $userId, array $limits, ?int $actorId = null): array
    {
        $this->account($userId);
        $allowedInt = ['hourly_limit', 'daily_limit', 'monthly_limit', 'per_minute_limit'];
        $allowedRate = ['max_bounce_rate' => 100.0, 'max_complaint_rate' => 100.0];
        $set = [];
        $params = [];
        foreach ($limits as $k => $v) {
            if (in_array($k, $allowedInt, true)) {
                if ($v !== null && (!is_numeric($v) || (int) $v < 1 || (int) $v > 100000000)) {
                    return ['success' => false, 'error' => "قيمة غير صالحة: {$k}"];
                }
                $set[] = "{$k} = ?";
                $params[] = $v === null ? null : (int) $v;
            } elseif (isset($allowedRate[$k])) {
                if ($v !== null && (!is_numeric($v) || (float) $v <= 0 || (float) $v > $allowedRate[$k])) {
                    return ['success' => false, 'error' => "قيمة غير صالحة: {$k}"];
                }
                $set[] = "{$k} = ?";
                $params[] = $v === null ? null : (float) $v;
            } elseif ($k === 'warmup_enabled') {
                $set[] = "warmup_enabled = ?";
                $params[] = $v ? 1 : 0;
            }
        }
        if (!$set) {
            return ['success' => false, 'error' => 'لا توجد حدود صالحة للتعديل'];
        }
        $params[] = $userId;
        $this->pdo()->prepare("UPDATE email_account_limits SET " . implode(', ', $set) . " WHERE user_id = ?")->execute($params);
        $this->logEvent($userId, null, 'limits_changed', $actorId, ['changes' => $limits]);
        return ['success' => true];
    }

    // ==================================================================
    // الحظر العام
    // ==================================================================

    public function addGlobalSuppression(string $value, string $kind = 'email', ?string $reason = null, ?int $actorId = null): array
    {
        $value = strtolower(trim($value));
        $kind = $kind === 'domain' ? 'domain' : 'email';
        if ($kind === 'email' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'error' => 'بريد غير صالح'];
        }
        if ($kind === 'domain' && !preg_match('/^(?=.{3,190}$)([a-z0-9-]+\.)+[a-z]{2,}$/', $value)) {
            return ['success' => false, 'error' => 'دومين غير صالح'];
        }
        $exists = $this->db->query("SELECT id FROM email_global_suppressions WHERE kind = ? AND value = ? LIMIT 1", [$kind, $value]);
        if (!empty($exists)) {
            return ['success' => true, 'id' => (int) $exists[0]['id'], 'existing' => true];
        }
        $this->pdo()->prepare("INSERT INTO email_global_suppressions (kind, value, reason, created_by, created_at) VALUES (?, ?, ?, ?, ?)")
            ->execute([$kind, $value, $reason !== null ? substr($reason, 0, 255) : null, $actorId, $this->ts()]);
        $id = (int) $this->pdo()->lastInsertId(); // قبل logEvent (اللي بيعمل INSERT تاني)
        $this->logEvent(0, null, 'global_suppression_added', $actorId, ['kind' => $kind, 'value' => $value, 'reason' => $reason]);
        return ['success' => true, 'id' => $id];
    }

    public function removeGlobalSuppression(int $id, ?int $actorId = null): bool
    {
        $st = $this->pdo()->prepare("DELETE FROM email_global_suppressions WHERE id = ?");
        $st->execute([$id]);
        if ($st->rowCount() > 0) {
            $this->logEvent(0, null, 'global_suppression_removed', $actorId, ['id' => $id]);
            return true;
        }
        return false;
    }

    public function globalSuppressions(int $limit = 200): array
    {
        return $this->db->query("SELECT * FROM email_global_suppressions ORDER BY id DESC LIMIT " . max(1, min(1000, $limit)));
    }

    public function isGloballySuppressed(string $email): bool
    {
        $email = strtolower(trim($email));
        $domain = substr(strrchr($email, '@') ?: '', 1);
        $r = $this->db->query(
            "SELECT id FROM email_global_suppressions WHERE (kind = 'email' AND value = ?) OR (kind = 'domain' AND value = ?) LIMIT 1",
            [$email, $domain]
        );
        return !empty($r);
    }

    // ==================================================================
    // تقارير الأدمن + التدقيق
    // ==================================================================

    /** لوحة الأدمن: كل العملاء مرتبين بأعلى معدل ارتداد/شكاوى. */
    public function adminOverview(int $days = 30, int $limit = 100): array
    {
        $cut = $this->ts($this->now() - max(1, $days) * 86400);
        $rows = $this->db->query(
            "SELECT c.user_id,
                    COUNT(*) AS attempted,
                    COALESCE(SUM(CASE WHEN r.bounced_at IS NOT NULL THEN 1 ELSE 0 END), 0) AS bounced,
                    COALESCE(SUM(CASE WHEN r.complained_at IS NOT NULL THEN 1 ELSE 0 END), 0) AS complaints,
                    COUNT(DISTINCT c.id) AS campaigns
             FROM email_campaign_recipients r
             JOIN email_campaigns c ON c.id = r.campaign_id
             WHERE (r.sent_at >= ? OR r.bounced_at >= ?)
             GROUP BY c.user_id",
            [$cut, $cut]
        );
        $out = [];
        foreach ($rows as $r) {
            $uid = (int) $r['user_id'];
            $acct = $this->account($uid);
            $att = max(1, (int) $r['attempted']);
            $out[] = [
                'user_id' => $uid,
                'status' => $acct['status'] ?? 'active',
                'pause_reason' => $acct['pause_reason'] ?? null,
                'campaigns' => (int) $r['campaigns'],
                'attempted' => (int) $r['attempted'],
                'bounce_rate' => round((int) $r['bounced'] / $att * 100, 2),
                'complaint_rate' => round((int) $r['complaints'] / $att * 100, 3),
                'usage' => $this->usage($uid),
            ];
        }
        usort($out, fn ($a, $b) => [$b['complaint_rate'], $b['bounce_rate']] <=> [$a['complaint_rate'], $a['bounce_rate']]);
        return array_slice($out, 0, max(1, $limit));
    }

    public function events(int $userId = 0, int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));
        if ($userId > 0) {
            return $this->db->query("SELECT * FROM email_reputation_events WHERE user_id = ? ORDER BY id DESC LIMIT {$limit}", [$userId]);
        }
        return $this->db->query("SELECT * FROM email_reputation_events ORDER BY id DESC LIMIT {$limit}");
    }

    public function logEvent(int $userId, ?int $campaignId, string $type, ?int $actorId, array $details): void
    {
        try {
            $this->pdo()->prepare(
                "INSERT INTO email_reputation_events (user_id, campaign_id, event_type, actor_id, details, created_at) VALUES (?, ?, ?, ?, ?, ?)"
            )->execute([$userId, $campaignId, $type, $actorId, json_encode($details, JSON_UNESCAPED_UNICODE), $this->ts()]);
        } catch (Throwable $e) {
            $this->notify('error', 'تعذر تسجيل حدث السمعة: ' . $e->getMessage(), []);
        }
    }

    private function logOnce(int $userId, ?int $campaignId, string $type, array $details, int $withinSeconds): void
    {
        $since = $this->ts($this->now() - $withinSeconds);
        $sql = "SELECT id FROM email_reputation_events WHERE user_id = ? AND event_type = ? AND created_at >= ?"
            . ($campaignId ? " AND campaign_id = ?" : "") . " LIMIT 1";
        $found = $this->db->query($sql, $campaignId ? [$userId, $type, $since, $campaignId] : [$userId, $type, $since]);
        if (empty($found)) {
            $this->logEvent($userId, $campaignId, $type, null, $details);
            $this->notify('info', "تحذير سمعة للعميل #{$userId} ({$type})", $details);
        }
    }

    // ==================================================================
    // مساعدات داخلية
    // ==================================================================

    private function maxRecipientId(): int
    {
        return (int) ($this->db->query("SELECT COALESCE(MAX(id), 0) AS m FROM email_campaign_recipients")[0]['m'] ?? 0);
    }

    /**
     * بعد استئناف الحساب/موافقة المراجعة: الحملات الموقوفة بسبب الحساب ترجع sending،
     * وأي حملة (sending/scheduled/draft) لها مستلمين pending تتجدول تاني.
     * الحملات الموقوفة بسبب معدلاتها هي (campaign-level) تفضل موقوفة لحد قرار الأدمن.
     */
    private function requeueUserCampaigns(int $userId): void
    {
        $this->pdo()->prepare(
            "UPDATE email_campaigns SET status = 'sending', paused_reason = NULL, paused_at = NULL
             WHERE user_id = ? AND status = 'paused' AND paused_reason LIKE ?"
        )->execute([$userId, self::ACCOUNT_PAUSE_PREFIX . '%']);

        $rows = $this->db->query(
            "SELECT c.id, c.status, c.scheduled_at FROM email_campaigns c
             WHERE c.user_id = ? AND c.status IN ('sending','scheduled','draft')
               AND EXISTS (SELECT 1 FROM email_campaign_recipients r WHERE r.campaign_id = c.id AND r.status = 'pending')",
            [$userId]
        );
        foreach ($rows as $r) {
            // المجدولة لمستقبل: الـ job الأصلي لسه في الطابور — منكرّرهوش (يبعت بدري)
            if ($r['status'] === 'scheduled' && !empty($r['scheduled_at'])
                && (int) strtotime($r['scheduled_at'] . ' UTC') > $this->now()) {
                continue;
            }
            $this->pushJob($userId, (int) $r['id'], 1);
        }
    }

    private function pushJob(int $userId, int $campaignId, int $delay): void
    {
        if (!class_exists('QueueManager') || !class_exists('SendEmailCampaignBatchJob')) {
            return;
        }
        try {
            (new QueueManager())->push('SendEmailCampaignBatchJob', ['user_id' => $userId, 'campaign_id' => $campaignId], 'email', $delay);
        } catch (Throwable $e) {
            $this->notify('error', 'تعذر جدولة استئناف الحملة: ' . $e->getMessage(), ['campaign_id' => $campaignId]);
        }
    }

    private function notify(string $level, string $message, array $context): void
    {
        if ($this->notifier) {
            try {
                call_user_func($this->notifier, $level, $message, $context);
            } catch (Throwable $e) {
            }
            return;
        }
        if (class_exists('Logger')) {
            $m = $level === 'error' || $level === 'critical' ? 'error' : 'warning';
            if (method_exists('Logger', $m)) {
                Logger::$m('[EmailReputation] ' . $message, $context);
            }
        }
    }
}
