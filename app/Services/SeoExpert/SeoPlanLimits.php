<?php
require_once __DIR__ . '/../Subscription/BillingPlanCatalog.php';

/**
 * حدود خدمة السيو من باقة العميل: عدد الفحوصات في الشهر لكل موقع، والصفحات في كل فحص، والأسواق.
 * باقات السيو (seo-*) والباقات العامة بتتقري من نفس features_json؛ لو الحد مش متكتب بنرجع لافتراضي الباقة.
 */
final class SeoPlanLimits
{
    private const DEFAULTS = [
        'starter' => ['cycles' => 1, 'pages' => 100, 'markets' => 1],
        'professional' => ['cycles' => 2, 'pages' => 100, 'markets' => 3],
        'enterprise' => ['cycles' => 4, 'pages' => 300, 'markets' => 6],
        'seo-essential' => ['cycles' => 2, 'pages' => 100, 'markets' => 1],
        'seo-growth' => ['cycles' => 4, 'pages' => 300, 'markets' => 3],
        'seo-agency' => ['cycles' => 4, 'pages' => 500, 'markets' => 6],
    ];
    private static array $cache = [];

    public static function isSeoPlan(string $planKey): bool
    {
        return str_starts_with($planKey, 'seo-');
    }

    public static function forUser(int $userId, ?Database $db = null): array
    {
        if (isset(self::$cache[$userId])) return self::$cache[$userId];
        $db ??= Database::getInstance();
        $owner = (int) ($db->query('SELECT owner_user_id FROM users WHERE id = ? LIMIT 1', [$userId])[0]['owner_user_id'] ?? 0) ?: $userId;
        $sub = class_exists('Subscription') ? Subscription::activeSubscriptionRow($owner) : null;
        $key = (string) ($sub['plan_name'] ?? '');
        $base = self::DEFAULTS[$key] ?? ['cycles' => 1, 'pages' => 100, 'markets' => 1];
        $features = [];
        if ($sub) {
            try {
                $row = !empty($sub['plan_id']) ? ($db->query('SELECT * FROM subscription_plans WHERE id = ? LIMIT 1', [(int) $sub['plan_id']])[0] ?? null) : null;
                $features = BillingPlanCatalog::features($row ?: ['plan_code' => $key . '_' . ($sub['plan_type'] ?? 'monthly'), 'features_json' => $sub['plan_features_json'] ?? '{}']);
            } catch (Throwable $e) {
                $features = [];
            }
        }
        $int = static fn($v, int $fallback) => is_numeric($v) && (int) $v > 0 ? (int) $v : $fallback;
        $pages = $int($features['seo_max_pages'] ?? null, $base['pages']);
        $limits = [
            'plan_key' => $key,
            'seo_plan' => self::isSeoPlan($key),
            'cycles' => max(1, min(4, $int($features['seo_cycles'] ?? null, $base['cycles']))),
            // إعدادات الفحص بتقبل 100 أو 300 أو 500 صفحة بس
            'pages' => $pages >= 500 ? 500 : ($pages >= 300 ? 300 : 100),
            'markets' => $int($features['seo_markets'] ?? null, $base['markets']),
        ];
        return self::$cache[$userId] = $limits;
    }
}
