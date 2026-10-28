<?php

/**
 * Tourfecto - Feature Resolver
 * =============================
 * بيدمج ميزات الباقة الافتراضية (SUBSCRIPTION_PLANS) مع أي تخصيص
 * (Override) عملَه الأدمن لعميل بذاته من لوحة الأدمن، ويرجّع القيمة
 * الفعلية (Effective) اللي المفروض الكود يتصرف على أساسها.
 *
 * أي حتة في الكود عايزة تعرف هل عميل معين معاه ميزة معينة، أو إيه
 * الحد المسموح له بيه، المفروض تستخدم الكلاس ده بدل ما تقرا
 * SUBSCRIPTION_PLANS مباشرة - عشان override الأدمن يتطبق فعليًا.
 *
 * @version 1.0.0
 */

class FeatureResolver
{
    /**
     * يرجّع كل الميزات الفعلية لمستخدم معين (افتراضي الباقة + أي تخصيص).
     * @param int $userId
     * @return array ['features' => [...], 'plan_name' => string|null, 'has_overrides' => bool]
     */
    public static function resolve(int $userId): array
    {
        $subscription = Subscription::activeSubscriptionRow($userId);
        $planName = $subscription['plan_name'] ?? 'starter';
        $planDefaults = [];
        if ($subscription) {
            require_once dirname(__DIR__) . '/Services/Subscription/BillingPlanCatalog.php';
            try {
                $planDefaults = BillingPlanCatalog::features([
                    'features_json' => $subscription['plan_features_json'] ?? '{}',
                    'plan_name' => $planName,
                    'ai_analysis_limit' => $subscription['ai_credits'] ?? 0,
                    'ai_message_limit' => $subscription['chat_credits'] ?? 0,
                    'review_auto_reply_limit' => $subscription['review_credits'] ?? 0,
                ]);
                if (isset($subscription['competitor_analysis_limit'])) $planDefaults['competitor_analysis'] = BillingPlanCatalog::quota($subscription['competitor_analysis_limit']);
            } catch (Throwable $e) {
                if (class_exists('Logger')) Logger::error('Invalid subscription entitlement configuration', ['user_id'=>$userId]);
                return ['features'=>[], 'plan_name'=>$planName, 'has_overrides'=>false];
            }
        } else {
            $catalog = class_exists('SubscriptionPlan') ? SubscriptionPlan::allAsLegacyArray() : [];
            $planDefaults = $catalog[$planName]['features'] ?? [];
        }

        $overrides = [];
        if ($subscription && !empty($subscription['feature_overrides'])) {
            $decoded = json_decode((string) $subscription['feature_overrides'], true);
            if (is_array($decoded)) {
                $overrides = $decoded;
            }
        }

        // الدمج: أي قيمة موجودة في overrides (حتى لو false أو 0) بتغلب
        // على قيمة الباقة الافتراضية. القيم اللي مالهاش override بتفضل
        // زي ما هي من الباقة.
        $effective = $planDefaults;
        foreach ($overrides as $key => $value) {
            $effective[$key] = $value;
        }

        return [
            'plan_name' => $planName,
            'plan_defaults' => $planDefaults,
            'overrides' => $overrides,
            'features' => $effective,
            'has_overrides' => count($overrides) > 0,
        ];
    }

    /**
     * فحص سريع: هل عند المستخدم ده ميزة معينة (Boolean) مفعّلة فعليًا؟
     */
    public static function has(int $userId, string $featureKey): bool
    {
        $resolved = self::resolve($userId);
        return (bool) ($resolved['features'][$featureKey] ?? false);
    }

    /**
     * فحص سريع: إيه القيمة الفعلية لميزة رقمية (زي حد الرسايل الشهري)؟
     */
    public static function value(int $userId, string $featureKey)
    {
        $resolved = self::resolve($userId);
        return $resolved['features'][$featureKey] ?? null;
    }

    /**
     * حفظ تخصيصات جديدة لعميل (بيستبدل كل الـ overrides القديمة).
     * $overrides ممكن يبقى فيها بعض الميزات بس - مش لازم كل الميزات.
     * لو عايز ترجع ميزة لقيمة الباقة الافتراضية تاني، امسحها من المصفوفة
     * دي بدل ما تبعتها.
     */
    public static function saveOverrides(int $userId, array $overrides): bool
    {
        $row = Subscription::activeSubscriptionRow($userId);
        if (!$row || empty($row['id'])) {
            return false;
        }

        $json = json_encode($overrides, JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return false;
        }
        // Only change overrides: saving the whole model could overwrite concurrent credit usage.
        return Database::getInstance()->query(
            'UPDATE subscriptions SET feature_overrides = ? WHERE id = ? AND user_id = ?',
            [$json, (int) $row['id'], $userId]
        ) !== false;
    }
}
