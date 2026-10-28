<?php

/**
 * Tourfecto - AI Governor
 * نقطة تحكم مركزية في استهلاك الذكاء الاصطناعي لكل شركة (حساب عميل).
 *
 * 1) نسب كل طلب لصاحبه: لو الاستدعاء ما بعتش user_id (وده حال معظم الاستدعاءات)
 *    بناخده من جلسة الويب الحالية، فـ api_usage_logs يبقى فيه "مين استهلك إيه".
 * 2) حدود عادلة لكل شركة (في الدقيقة / اليوم / الشهر) عشان شركة واحدة ما تستنزفش
 *    المنصة أو الفاتورة. الأدمن والمهام الداخلية (من غير مستخدم) مستثنيين.
 *
 * مصمّم "يفتح عند الفشل" (fail-open): أي خطأ داخلي هنا ما يوقفش أي ميزة ذكاء اصطناعي.
 *
 * الإعدادات (كلها اختيارية في .env - 0 يعني "من غير حد"):
 *   AI_LIMIT_PER_MINUTE=20     AI_LIMIT_PER_DAY=400
 *   AI_LIMIT_USD_PER_DAY=0     AI_LIMIT_USD_PER_MONTH=0
 *   AI_GOVERNOR_ENABLED=true   (false لإيقافه بالكامل)
 */
class AIGovernor
{
    /** @var array<int,bool> كاش دور المستخدم داخل الطلب الواحد */
    private static array $exempt = [];

    /** المستخدم صاحب الطلب: من الاستدعاء لو متبعت، وإلا من جلسة الويب */
    public static function resolveUserId($explicit = null): ?int
    {
        $id = (int) $explicit;
        if ($id > 0) {
            return $id;
        }
        if (PHP_SAPI !== 'cli' && !empty($_SESSION['user_id'])) {
            return (int) $_SESSION['user_id'];
        }
        return null;
    }

    /**
     * @return array|null null = مسموح. غير كده: ['message'=>..., 'reason'=>'minute|day|usd_day|usd_month']
     */
    public static function check(?int $userId, ?string $task = null): ?array
    {
        try {
            if (!$userId || !self::enabled() || self::isExempt($userId)) {
                return null;
            }

            $perMin = self::limit('AI_LIMIT_PER_MINUTE', 20);
            $perDay = self::limit('AI_LIMIT_PER_DAY', 400);
            $usdDay = self::limitFloat('AI_LIMIT_USD_PER_DAY', 0.0);
            $usdMonth = self::limitFloat('AI_LIMIT_USD_PER_MONTH', 0.0);

            if ($perMin === 0 && $perDay === 0 && $usdDay === 0.0 && $usdMonth === 0.0) {
                return null;
            }

            // استعلام واحد يغطي الدقيقة واليوم والشهر
            $rows = Database::getInstance()->query(
                "SELECT
                    COALESCE(SUM(created_at >= (NOW() - INTERVAL 1 MINUTE)), 0) AS c_min,
                    COALESCE(SUM(created_at >= CURDATE()), 0) AS c_day,
                    COALESCE(SUM(CASE WHEN created_at >= CURDATE() THEN cost_in_usd ELSE 0 END), 0) AS usd_day,
                    COALESCE(SUM(cost_in_usd), 0) AS usd_month
                 FROM api_usage_logs
                 WHERE user_id = ? AND created_at >= DATE_FORMAT(NOW(), '%Y-%m-01')",
                [$userId]
            );
            $u = $rows[0] ?? null;
            if (!$u) {
                return null;
            }

            if ($perMin > 0 && (int) $u['c_min'] >= $perMin) {
                return self::blocked('minute');
            }
            if ($perDay > 0 && (int) $u['c_day'] >= $perDay) {
                return self::blocked('day');
            }
            if ($usdDay > 0 && (float) $u['usd_day'] >= $usdDay) {
                return self::blocked('usd_day');
            }
            if ($usdMonth > 0 && (float) $u['usd_month'] >= $usdMonth) {
                return self::blocked('usd_month');
            }
        } catch (Throwable $e) {
            // fail-open: مفيش خطأ هنا يوقف الذكاء الاصطناعي
            if (class_exists('Logger')) {
                Logger::warning('AIGovernor check failed (allowing)', ['error' => $e->getMessage()]);
            }
        }
        return null;
    }

    private static function blocked(string $reason): array
    {
        $ar = function_exists('current_lang') ? current_lang() === 'ar' : true;
        $msgs = [
            'minute' => ['طلبات كتير في وقت قصير. استنى دقيقة وجرّب تاني.', 'Too many AI requests in a short time. Please wait a minute and try again.'],
            'day' => ['وصلت للحد اليومي لاستخدام الذكاء الاصطناعي. هيتجدد بكرة، أو تواصل معانا لزيادة الحد.', 'You have reached today\'s AI usage limit. It resets tomorrow, or contact us to raise it.'],
            'usd_day' => ['وصلت للحد اليومي لاستخدام الذكاء الاصطناعي. هيتجدد بكرة.', 'You have reached today\'s AI usage limit. It resets tomorrow.'],
            'usd_month' => ['وصلت للحد الشهري لاستخدام الذكاء الاصطناعي. تواصل معانا لزيادة الحد.', 'You have reached this month\'s AI usage limit. Contact us to raise it.'],
        ];
        $m = $msgs[$reason] ?? $msgs['day'];
        return ['reason' => $reason, 'message' => $ar ? $m[0] : $m[1]];
    }

    private static function enabled(): bool
    {
        $v = function_exists('env') ? env('AI_GOVERNOR_ENABLED') : null;
        return !($v !== null && in_array(strtolower((string) $v), ['0', 'false', 'off', 'no'], true));
    }

    private static function limit(string $key, int $default): int
    {
        $v = function_exists('env') ? env($key) : null;
        return ($v === null || $v === false || $v === '') ? $default : max(0, (int) $v);
    }

    private static function limitFloat(string $key, float $default): float
    {
        $v = function_exists('env') ? env($key) : null;
        return ($v === null || $v === false || $v === '') ? $default : max(0.0, (float) $v);
    }

    /** الأدمن مستثنى من الحدود */
    private static function isExempt(int $userId): bool
    {
        if (isset(self::$exempt[$userId])) {
            return self::$exempt[$userId];
        }
        $rows = Database::getInstance()->query('SELECT role FROM users WHERE id = ? LIMIT 1', [$userId]);
        $role = (string) ($rows[0]['role'] ?? 'user');
        return self::$exempt[$userId] = in_array($role, ['admin', 'super_admin'], true);
    }
}
