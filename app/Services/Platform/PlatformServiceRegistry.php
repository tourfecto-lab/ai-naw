<?php

/**
 * Tourfecto - سجل الخدمات الموحّد (Services × Plans × Frontend)
 *
 * قبل كده كان عندنا 3 مصادر منفصلة لنفس الخدمات:
 *   - ServiceCenterCatalog   (شاشات الخدمات جوه الحساب)
 *   - MarketingContent       (صفحات /features/{slug} العامة)
 *   - features_json للباقات  (BillingPlanCatalog + PlanUsage)
 * ومفيش أي ربط بينهم: العميل مش عارف الخدمة دي في باقته ولا لأ، وصفحة
 * الأسعار مش بتقول كل باقة فيها أنهي خدمات.
 *
 * الكلاس ده هو الجسر الوحيد بينهم. منطق نقي (من غير قاعدة بيانات) عشان
 * يتختبر لوحده؛ الأسعار والحدود نفسها لسه جاية من صفوف الفوترة بس.
 *
 * أنواع الاستحقاق:
 *   meter     حصة لكل فترة اشتراك، ولو خلصت أو = 0 بيتخصم من المحفظة (PlanUsage)
 *   allowance رصيد شهري للباقة (من غير بديل محفظة)
 *   limit     سعة ثابتة (مواقع، أعضاء فريق، أسواق)
 *   flag      تفعيل/تعطيل؛ gate=true معناه الخدمة مش متاحة من غيره
 *
 * حالات الخدمة في أي باقة:
 *   core         موجودة في كل الباقات
 *   included     الباقة فيها حصة/صلاحية للخدمة
 *   pay_per_use  متاحة، بس الاستخدام من المحفظة (حصة الباقة = 0)
 *   not_included محتاجة ترقية
 */
class PlatformServiceRegistry
{
    public const STATES = ['core', 'included', 'pay_per_use', 'not_included'];

    /** ترتيب "الأفضل" لما خدمة تسويقية واحدة بتجمع كذا خدمة داخلية */
    private const RANK = ['not_included' => 0, 'pay_per_use' => 1, 'included' => 2, 'core' => 3];

    /** كل مفتاح ميزة في features_json: النوع + اسم العرض [ar, en] + مفتاح عدّاد PlanUsage لو موجود */
    public const ENTITLEMENTS = [
        'multiple_websites'           => ['limit', ['مواقع', 'Websites']],
        'team_members'                => ['limit', ['أعضاء الفريق', 'Team members']],
        'seo_markets'                 => ['limit', ['أسواق SEO', 'SEO markets']],
        'ai_analysis'                 => ['allowance', ['تحليلات الذكاء الاصطناعي / شهر', 'AI analyses / month']],
        'chat_credits'                => ['allowance', ['رسائل الشات الذكي / شهر', 'AI chat messages / month']],
        'review_credits'              => ['allowance', ['ردود التقييمات / شهر', 'Review replies / month']],
        'competitor_analysis'         => ['allowance', ['تحليلات المنافسين / شهر', 'Competitor analyses / month']],
        'whatsapp_marketing_messages' => ['allowance', ['رسائل تسويق واتساب', 'WhatsApp marketing messages']],
        'seo_data'                    => ['meter', ['قياسات SEO', 'SEO measurements'], 'seo_data'],
        'ai_visibility'               => ['meter', ['فحوصات الظهور في الذكاء الاصطناعي', 'AI visibility checks'], 'ai_visibility'],
        'ai_assistant_messages'       => ['meter', ['رسائل المساعد الذكي', 'AI assistant messages'], 'ai_assistant_message'],
        'website_generations'         => ['meter', ['توليد مواقع', 'Website generations'], 'website_generation'],
        'website_items'               => ['meter', ['عناصر الموقع (رحلات/غرف)', 'Website items (tours/rooms)'], 'website_item'],
        'articles'                    => ['meter', ['مقالات', 'Articles'], 'article_generation'],
        'marketing_tools'             => ['meter', ['أدوات التسويق', 'Marketing tool uses'], 'marketing_tool'],
        'media_images'                => ['meter', ['صور', 'Images'], 'media_image'],
        'media_videos'                => ['meter', ['فيديوهات', 'Videos'], 'media_video'],
        'ai_tools'                    => ['meter', ['أدوات AI الصغيرة', 'Small AI tools'], 'ai_tool'],
        'whatsapp_bot'                => ['flag', ['شات واتساب', 'WhatsApp chat']],
        'auto_pilot'                  => ['flag', ['الطيار الآلي', 'Autopilot']],
        'advanced_analytics'          => ['flag', ['تحليلات متقدمة', 'Advanced analytics']],
    ];

    /**
     * مفتاح ServiceCenterCatalog => [صفحة /features/{slug}, [[مفتاح ميزة, gate?], ...]]
     * خدمة من غير استحقاقات = core (في كل الباقات).
     */
    public const MAP = [
        'account'      => ['agency-teams', []],
        'business'     => ['agency-teams', [['team_members', false]]],
        'subscription' => ['bookings-payments', []],
        'wallet'       => ['bookings-payments', []],
        'payments'     => ['bookings-payments', []],
        'invoices'     => ['bookings-payments', []],
        'tours'        => ['bookings-payments', []],
        'hotel'        => ['bookings-payments', []],
        'tickets'      => ['bookings-payments', []],
        'reminders'    => ['trip-automation', []],
        'builder'      => ['booking-website', [['multiple_websites', false], ['website_generations', false], ['website_items', false], ['ai_assistant_messages', false]]],
        'seo'          => ['booking-website', [['seo_data', false], ['ai_visibility', false], ['ai_analysis', false]]],
        'keywords'     => ['google-visibility', [['seo_data', false], ['seo_markets', false]]],
        'analytics'    => ['google-visibility', [['advanced_analytics', false]]],
        'indexing'     => ['google-visibility', []],
        'articles'     => ['content-marketing', [['articles', false]]],
        'gbp'          => ['google-visibility', [['ai_tools', false]]],
        'reputation'   => ['reviews-reputation', [['review_credits', false], ['auto_pilot', false]]],
        'reviews'      => ['reviews-reputation', []],
        'whatsapp'     => ['ai-chat', [['whatsapp_bot', true], ['whatsapp_marketing_messages', false]]],
        'inbox'        => ['ai-chat', [['chat_credits', false], ['auto_pilot', false]]],
        'knowledge'    => ['ai-chat', []],
        'assistant'    => ['content-marketing', [['marketing_tools', false]]],
        'images'       => ['content-marketing', [['media_images', false]]],
        'video'        => ['content-marketing', [['media_videos', false]]],
        'social'       => ['content-marketing', [['ai_tools', false]]],
        'ads'          => ['content-marketing', [['ai_tools', false], ['auto_pilot', false]]],
        'crm'          => ['crm-revenue', []],
        'email'        => ['content-marketing', []],
        'competitors'  => ['competitors', [['competitor_analysis', false]]],
        'outreach'     => ['google-visibility', [['ai_tools', false]]],
        'ota'          => ['bookings-payments', []],
        'revenue'      => ['crm-revenue', [['advanced_analytics', false]]],
        'agency'       => ['agency-teams', [['multiple_websites', false]]],
        'integrations' => ['bookings-payments', []],
        'automation'   => ['trip-automation', [['auto_pilot', false]]],
    ];

    /** خدمات المركز + الربط بالصفحة التسويقية والاستحقاقات */
    public static function services(?array $catalog = null): array
    {
        $catalog ??= ServiceCenterCatalog::all();
        $out = [];
        foreach ($catalog as $key => $service) {
            [$slug, $ents] = self::MAP[$key] ?? [null, []];
            $service['marketing_slug'] = $slug;
            $service['marketing_url'] = $slug ? '/features/' . $slug : null;
            $service['entitlements'] = array_map(fn ($e) => self::entitlementMeta($e[0], $e[1]), $ents);
            $out[$key] = $service;
        }
        return $out;
    }

    public static function entitlementMeta(string $feature, bool $gate = false): array
    {
        if (!isset(self::ENTITLEMENTS[$feature])) {
            throw new InvalidArgumentException('Unknown plan entitlement: ' . $feature);
        }
        $def = self::ENTITLEMENTS[$feature];
        return ['feature' => $feature, 'kind' => $def[0], 'label' => $def[1], 'usage_key' => $def[2] ?? null, 'gate' => $gate];
    }

    /** -1 = غير محدود، true/false للـ flags، رقم صحيح للباقي */
    public static function normalize($value, string $kind)
    {
        if ($kind === 'flag') {
            return $value === true || $value === 1 || $value === '1' || $value === -1;
        }
        if ($value === true || $value === -1 || $value === '-1') return -1;
        return is_numeric($value) ? max(0, (int) $value) : 0;
    }

    /**
     * حالة خدمة واحدة مقابل ميزات باقة (features بعد BillingPlanCatalog::features أو FeatureResolver).
     * @return array{state:string, values:array<string,mixed>}
     */
    public static function coverage(array $entitlements, array $features): array
    {
        $values = [];
        foreach ($entitlements as $e) {
            $values[$e['feature']] = self::normalize($features[$e['feature']] ?? null, $e['kind']);
        }
        if (!$entitlements) {
            return ['state' => 'core', 'values' => $values];
        }
        foreach ($entitlements as $e) {
            if ($e['gate'] && empty($values[$e['feature']])) {
                return ['state' => 'not_included', 'values' => $values];
            }
        }
        $hasMeter = false;
        $hasAllowance = false;
        $hasGrant = false;
        foreach ($entitlements as $e) {
            $v = $values[$e['feature']];
            if ($e['kind'] === 'meter') $hasMeter = true;
            if ($e['kind'] === 'allowance') $hasAllowance = true;
            if ($v === true || $v === -1 || (is_int($v) && $v > 0)) $hasGrant = true;
        }
        if ($hasGrant) return ['state' => 'included', 'values' => $values];
        if ($hasMeter) return ['state' => 'pay_per_use', 'values' => $values];
        // رصيد شهري = 0 ومفيش بديل محفظة: الجزء المدفوع من الخدمة محتاج ترقية
        if ($hasAllowance) return ['state' => 'not_included', 'values' => $values];
        // سعات/flags اختيارية كلها صفر: الخدمة الأساسية شغالة من غير الإضافات
        return ['state' => 'core', 'values' => $values];
    }

    /**
     * مصفوفة الخدمات × الباقات (للوحة الأسعار والـ API العام).
     * $plans = ناتج BillingPlanCatalog::read() (مترتّب حسب sort_order).
     */
    public static function matrix(array $plans, ?array $catalog = null): array
    {
        $rows = [];
        foreach (self::services($catalog) as $key => $s) {
            $cells = [];
            $min = null;
            foreach ($plans as $planKey => $plan) {
                $cells[$planKey] = self::coverage($s['entitlements'], $plan['features'] ?? []);
                if ($min === null && in_array($cells[$planKey]['state'], ['core', 'included'], true)) $min = $planKey;
            }
            $rows[$key] = [
                'key' => $key, 'name' => $s['name'], 'note' => $s['note'] ?? '', 'url' => $s['url'] ?? null,
                'marketing_slug' => $s['marketing_slug'], 'entitlements' => $s['entitlements'],
                'plans' => $cells, 'min_plan' => $min,
            ];
        }
        return $rows;
    }

    /**
     * تجميع على مستوى الصفحات التسويقية: لكل /features/{slug} أفضل وأسوأ حالة في كل باقة.
     * best = أقوى خدمة في المجموعة؛ full = كل خدمات المجموعة متاحة من غير ترقية.
     */
    public static function marketingMatrix(array $plans, ?array $catalog = null): array
    {
        $groups = [];
        foreach (self::matrix($plans, $catalog) as $row) {
            $slug = $row['marketing_slug'];
            if ($slug === null) continue;
            $groups[$slug] ??= ['slug' => $slug, 'services' => [], 'plans' => []];
            $groups[$slug]['services'][] = $row['key'];
            foreach ($row['plans'] as $planKey => $cell) {
                $g = &$groups[$slug]['plans'][$planKey];
                $g ??= ['best' => 'not_included', 'worst' => 'core', 'included' => [], 'upgrade' => []];
                if (self::RANK[$cell['state']] > self::RANK[$g['best']]) $g['best'] = $cell['state'];
                if (self::RANK[$cell['state']] < self::RANK[$g['worst']]) $g['worst'] = $cell['state'];
                $g[$cell['state'] === 'not_included' ? 'upgrade' : 'included'][] = $row['key'];
                unset($g);
            }
        }
        return $groups;
    }

    /**
     * عرض حساب العميل: حالة كل خدمة في باقته الحالية + الاستخدام + أقل باقة ترقية.
     * $features = FeatureResolver::resolve()['features'] (فاضية = مفيش اشتراك فعّال)
     * $usage    = PlanUsage::summary()
     */
    public static function accountView(array $features, array $usage, array $plans, ?string $currentPlan, ?array $catalog = null): array
    {
        $subscribed = $features !== [];
        $order = array_keys($plans);
        $currentTier = $currentPlan !== null ? preg_replace('/_(monthly|yearly)$/D', '', $currentPlan) : null;
        $pos = $currentTier !== null ? array_search($currentTier, $order, true) : false;
        $out = [];
        foreach (self::matrix($plans, $catalog) as $key => $row) {
            $cov = self::coverage($row['entitlements'], $features);
            $state = $cov['state'];
            if (!$subscribed && $state !== 'core') $state = $row['entitlements'] && self::hasMeter($row['entitlements']) ? 'pay_per_use' : 'not_included';
            $lines = [];
            foreach ($row['entitlements'] as $e) {
                $line = ['feature' => $e['feature'], 'kind' => $e['kind'], 'label' => $e['label'], 'value' => $cov['values'][$e['feature']] ?? null];
                if ($e['usage_key'] && isset($usage[$e['usage_key']])) {
                    $u = $usage[$e['usage_key']];
                    $line['usage'] = ['limit' => (int) ($u['limit'] ?? 0), 'used' => (int) ($u['used'] ?? 0), 'remaining' => $u['remaining'] ?? null, 'covered' => (bool) ($u['covered'] ?? false)];
                    if ($state === 'included' && $line['usage']['limit'] !== 0 && !$line['usage']['covered']) $line['exhausted'] = true;
                }
                $lines[] = $line;
            }
            // أقل باقة أعلى من الحالية فيها الخدمة (أو أي باقة لو مفيش اشتراك)
            $upgrade = null;
            if (!in_array($state, ['core', 'included'], true)) {
                foreach ($row['plans'] as $planKey => $cell) {
                    $idx = array_search($planKey, $order, true);
                    if ($pos !== false && $idx <= $pos) continue;
                    if ($cell['state'] === 'included') { $upgrade = $planKey; break; }
                }
            }
            $out[$key] = ['state' => $state, 'entitlements' => $lines, 'upgrade_plan' => $upgrade, 'min_plan' => $row['min_plan']];
        }
        return $out;
    }

    private static function hasMeter(array $entitlements): bool
    {
        foreach ($entitlements as $e) if ($e['kind'] === 'meter' && !$e['gate']) return true;
        return false;
    }

    /**
     * فحص سلامة الربط (بيستخدمه الاختبار وأداة الـ audit):
     * كل خدمة في المركز ليها ربط، كل صفحة تسويقية متغطية، وكل عدّاد معروف لـ PlanUsage.
     * @return string[] قائمة أخطاء (فاضية = سليم)
     */
    public static function audit(array $catalogKeys, array $marketingSlugs, array $usageKeys): array
    {
        $errors = [];
        foreach ($catalogKeys as $k) if (!isset(self::MAP[$k])) $errors[] = "Service center key '$k' has no plan/marketing mapping";
        foreach (self::MAP as $k => [$slug, $ents]) {
            if (!in_array($k, $catalogKeys, true)) $errors[] = "Mapping '$k' is not a service center key";
            if (!in_array($slug, $marketingSlugs, true)) $errors[] = "Mapping '$k' points to unknown marketing page '$slug'";
            foreach ($ents as [$f]) if (!isset(self::ENTITLEMENTS[$f])) $errors[] = "Mapping '$k' uses unknown entitlement '$f'";
        }
        $used = array_unique(array_column(self::MAP, 0));
        foreach ($marketingSlugs as $slug) if (!in_array($slug, $used, true)) $errors[] = "Marketing page '$slug' is not linked to any service";
        foreach (self::ENTITLEMENTS as $f => $def) {
            if (isset($def[2]) && !in_array($def[2], $usageKeys, true)) $errors[] = "Entitlement '$f' meters unknown usage key '{$def[2]}'";
        }
        return $errors;
    }
}
