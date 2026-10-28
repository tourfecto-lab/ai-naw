<?php

/**
 * Tourfecto - Revenue Dashboard Personalization Service
 * @version 1.0.0
 *
 * v1.6.0: تخصيص داشبورد ذكاء الإيرادات لكل مستخدم (Tenant Isolation).
 *
 * القاعدة الثابتة: كل مقياس معروض في الداشبورد يأتي من قائمة معروفة
 * (widgets keys) صادرة عن خدمات الموديول الحقيقية. أي مفتاح غير معروف
 * يُتجاهل ولا يُحفظ أبدًا - لا مقياس مخترع ولا widgets وهمية.
 *
 * Pure functions (قابلة للاختبار بفيكسشرات):
 *   - defaultLayout()            - التخطيط الافتراضي (كل المقياس ظاهرة)
 *   - normalizeLayout(array)     - تطبيع أي مدخل إلى تخطيط نظيف وآمن
 *   - visibleWidgets(array)      - المقياس الظاهرة مرتبة حسب الطلب
 *   - applyLayoutToSummary()     - فلترة ملخص Executive حسب التخصيص
 */
class RevenueDashboardService
{
    /** @var RevenueDataGateway */
    private $gateway;

    /** القائمة الكاملة للمقياس المعروفة - المرجع الوحيد للصحة. */
    public const WIDGET_KEYS = [
        'current_revenue',
        'growth_percent',
        'forecast',
        'top_opportunity',
        'top_risk',
        'top_customer_segment',
        'top_revenue_source',
        'recommended_actions',
    ];

    public function __construct(?RevenueDataGateway $gateway = null)
    {
        $this->gateway = $gateway ?? new RevenueDataGateway();
    }

    /** التخطيط الافتراضي: كل المقياس ظاهرة بترتيبها الطبيعي. */
    public static function defaultLayout(): array
    {
        $widgets = [];
        foreach (self::WIDGET_KEYS as $i => $key) {
            $widgets[] = ['key' => $key, 'visible' => true, 'order' => $i];
        }
        return ['widgets' => $widgets, 'is_default' => true];
    }

    /**
     * تطبيع أي مدخل (من الواجهة أو DB) إلى تخطيط نظيف وآمن:
     *  - يبقي فقط المفاتيح المعروفة (يتجاهل أي شيء غيرها)
     *  - يضمن unique لكل مفتاح
     *  - يملأ أي مفتاح ناقص بالظهور الافتراضي
     *  - يرتب حسب order ثم الظهور
     */
    public static function normalizeLayout(array $input): array
    {
        $known = array_flip(self::WIDGET_KEYS);
        $seen = [];
        $widgets = [];

        $raw = $input['widgets'] ?? $input;
        if (is_array($raw)) {
            foreach ($raw as $w) {
                if (!is_array($w)) {
                    continue;
                }
                $key = (string) ($w['key'] ?? '');
                if (!isset($known[$key]) || isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $widgets[] = [
                    'key' => $key,
                    'visible' => (bool) ($w['visible'] ?? true),
                    'order' => (int) ($w['order'] ?? count($widgets)),
                ];
            }
        }

        // أضف أي مفتاح معروف لم يُذكَر بظهوره الافتراضي
        foreach (self::WIDGET_KEYS as $key) {
            if (!isset($seen[$key])) {
                $widgets[] = ['key' => $key, 'visible' => true, 'order' => count($widgets)];
            }
        }

        usort($widgets, static function ($a, $b) {
            return $a['order'] <=> $b['order'];
        });

        return ['widgets' => $widgets, 'is_default' => false];
    }

    /** المقياس الظاهرة فقط، مرتبة حسب order. Pure. */
    public static function visibleWidgets(array $layout): array
    {
        $widgets = [];
        foreach (($layout['widgets'] ?? []) as $w) {
            if (!empty($w['visible']) && isset($w['key'])) {
                $widgets[] = $w['key'];
            }
        }
        return $widgets;
    }

    /**
     * تطبيق التخصيص على ملخص Executive Summary: فلترة وإعادة ترتيب.
     * Pure. لا يحسب أي شيء - فقط يختار/يرتب ما هو موجود فعلًا.
     */
    public static function applyLayoutToSummary(array $summary, array $layout): array
    {
        $visible = self::visibleWidgets($layout);
        $out = ['has_data' => $summary['has_data'] ?? false, 'applied_layout' => $visible];

        foreach (self::WIDGET_KEYS as $key) {
            if (in_array($key, $visible, true) && array_key_exists($key, $summary)) {
                $out[$key] = $summary[$key];
            }
        }
        return $out;
    }

    /** قراءة تخطيط المستخدم المحفوظ (أو الافتراضي لو مفيش). */
    public function getLayout(int $userId): array
    {
        try {
            $row = $this->gateway->getDashboardPrefs($userId);
            if ($row === null) {
                return self::defaultLayout();
            }
            $decoded = json_decode((string) ($row['layout'] ?? ''), true);
            if (!is_array($decoded)) {
                return self::defaultLayout();
            }
            return self::normalizeLayout($decoded);
        } catch (Exception $e) {
            return self::defaultLayout();
        }
    }

    /** حفظ تخطيط مُطبَّع نظيف (يُرجع التخطيط المحفوظ). */
    public function saveLayout(int $userId, array $layout): array
    {
        $normalized = self::normalizeLayout($layout);
        $this->gateway->upsertDashboardPrefs($userId, $normalized);
        return $normalized;
    }

    /** مسح التخصيص والعودة للافتراضي. */
    public function resetLayout(int $userId): array
    {
        $default = self::defaultLayout();
        $this->gateway->deleteDashboardPrefs($userId);
        return $default;
    }
}
