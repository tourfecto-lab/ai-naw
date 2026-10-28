<?php

/**
 * Tourfecto - Ad Rule Alerts Info Controller (بند 4)
 * نقطة معلوماتية (read-only) فوق AdAlertService القائم: بتكشف أنواع قواعد
 * التنبيهات المدعومة — القواعد الخمس القديمة (مستوى الحملة من
 * ad_performance_reports) + الأربع الجديدة (مستوى الأصل/التنويع/التجربة من
 * ad_creative_variants + ad_ab_tests) — مع الافتراضيات والنطاق لكل قاعدة،
 * عشان الواجهة تقدر تعرض إعدادات القواعد بدون hardcoding.
 *
 * التقييم نفسه لسه بيتم عبر AdAlertService (نفس نقاط /api/ads/alerts* القائمة).
 * @version 1.0.0
 */
class AdRuleAlertController extends Controller
{
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * أنواع القواعد المدعومة: القواعد الجديدة (بند 4) + القواعد القائمة.
     * @return array
     */
    public static function ruleCatalog(): array
    {
        return [
            // ---- مستوى الحملة (قواعد قائمة من المزامنة) ----
            [
                'type' => 'budget_exhausted',
                'scope' => 'campaign',
                'default_threshold' => 90.0,
                'threshold_unit' => '% من الميزانية اليومية',
                'label' => 'ads.alerts.rule.budget_exhausted',
                'description' => 'ads.alerts.rule.budget_exhausted.desc',
            ],
            [
                'type' => 'cpc_spike',
                'scope' => 'campaign',
                'default_threshold' => 200.0,
                'threshold_unit' => '% من متوسط تكلفة النقرة',
                'label' => 'ads.alerts.rule.cpc_spike',
                'description' => 'ads.alerts.rule.cpc_spike.desc',
            ],
            [
                'type' => 'ctr_drop',
                'scope' => 'campaign',
                'default_threshold' => 50.0,
                'threshold_unit' => '% انخفاض في نسبة النقر',
                'label' => 'ads.alerts.rule.ctr_drop',
                'description' => 'ads.alerts.rule.ctr_drop.desc',
            ],
            [
                'type' => 'landing_page_down',
                'scope' => 'campaign',
                'default_threshold' => null,
                'threshold_unit' => 'لا يوجد',
                'label' => 'ads.alerts.rule.landing_page_down',
                'description' => 'ads.alerts.rule.landing_page_down.desc',
            ],
            [
                'type' => 'budget_pacing',
                'scope' => 'campaign',
                'default_threshold' => 75.0,
                'threshold_unit' => '% من اليوم',
                'label' => 'ads.alerts.rule.budget_pacing',
                'description' => 'ads.alerts.rule.budget_pacing.desc',
            ],

            // ---- بند 4: مستوى الأصل/التنويع/التجربة ----
            [
                'type' => 'creative_underperforming',
                'scope' => 'creative',
                'default_threshold' => 50.0,
                'threshold_unit' => '% من CTR الحملة',
                'label' => 'ads.alerts.rule.creative_underperforming',
                'description' => 'ads.alerts.rule.creative_underperforming.desc',
            ],
            [
                'type' => 'creative_stale',
                'scope' => 'creative',
                'default_threshold' => 7.0,
                'threshold_unit' => 'أيام بلا أداء مُسجّل',
                'label' => 'ads.alerts.rule.creative_stale',
                'description' => 'ads.alerts.rule.creative_stale.desc',
            ],
            [
                'type' => 'variant_wasted_spend',
                'scope' => 'variant',
                'default_threshold' => 50.0,
                'threshold_unit' => 'إنفاق بلا تحويلات',
                'label' => 'ads.alerts.rule.variant_wasted_spend',
                'description' => 'ads.alerts.rule.variant_wasted_spend.desc',
            ],
            [
                'type' => 'ab_test_inconclusive',
                'scope' => 'ab_test',
                'default_threshold' => 14.0,
                'threshold_unit' => 'أيام بلا دلالة إحصائية',
                'label' => 'ads.alerts.rule.ab_test_inconclusive',
                'description' => 'ads.alerts.rule.ab_test_inconclusive.desc',
            ],
        ];
    }

    /** GET /api/ads/alerts/rule-types */
    public function ruleTypes(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        return $this->success(['rules' => self::ruleCatalog()]);
    }
}
