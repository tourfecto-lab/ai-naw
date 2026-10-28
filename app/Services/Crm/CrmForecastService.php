<?php

/**
 * Tourfecto - CRM AI Forecasting Service (بند 25)
 * @version 1.0.0
 *
 * لا "تنبؤ AI" بمعنى نموذج تعلّم آلي هنا - هذا حساب إحصائي شفاف مبني على
 * بيانات المسار الفعلية (Weighted Pipeline بنفس منطق CrmDashboardService،
 * بالإضافة لتصنيف "صفقات قريبة الإغلاق" و"صفقات معرّضة للخطر" الموجودة
 * فعليًا). كل قيمة تُعرض دائمًا مع توصيف "Estimated/Forecast" واضح
 * (بند 25: "لا تعرض التوقع كحقيقة")، وأي مقياس بدون بيانات كافية لحسابه
 * يُعاد كـnull صراحة بدل اختلاق رقم (بند 39).
 */
class CrmForecastService
{
    private $db;
    private $dealService;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->dealService = new CrmDealService();
    }

    public function forecast(int $ownerUserId): array
    {
        $dashboard = new CrmDashboardService();
        $stats = $dashboard->stats($ownerUserId);

        // صفقات "قريبة الإغلاق" (Likely Wins): مرحلة متقدمة (احتمالية >= 60%)
        // أو تاريخ إغلاق متوقع خلال 30 يوم - إشارات حقيقية موجودة بالفعل في القاعدة.
        $likelyWins = $this->db->query(
            "SELECT d.id, d.title, d.value, d.currency, d.expected_close_date,
                    COALESCE(d.probability, s.win_probability) AS probability, s.name AS stage_name
             FROM crm_deals d JOIN crm_pipeline_stages s ON s.id = d.stage_id
             WHERE d.owner_user_id = ? AND d.status = 'open'
               AND (COALESCE(d.probability, s.win_probability) >= 60
                    OR (d.expected_close_date IS NOT NULL AND d.expected_close_date <= DATE_ADD(NOW(), INTERVAL 30 DAY)))
             ORDER BY COALESCE(d.probability, s.win_probability) DESC
             LIMIT 50",
            [$ownerUserId]
        );

        $potentialRevenue = 0.0;
        foreach ($likelyWins as $deal) {
            $potentialRevenue += ((float) $deal['value']) * ((float) $deal['probability'] / 100);
        }

        $atRiskDeals = $this->dealService->atRiskDeals($ownerUserId);

        // لا نعرض أي "دقة توقع" وهمية - لو مفيش صفقات مكسوبة سابقة كافية
        // لحساب دورة بيع موثوقة، نُعلمها صراحة بدل رقم مُختلق.
        $hasEnoughHistory = ($stats['won_deals'] + $stats['lost_deals']) >= 5;

        return [
            'basis' => 'estimated', // تصنيف صريح: تقديري، وليس حقيقة مؤكدة (بند 25)
            'expected_pipeline' => [
                'value' => $stats['pipeline_value'],
                'weighted_value' => $stats['weighted_pipeline'],
                'label' => 'Weighted Pipeline (Estimated)',
            ],
            'likely_wins' => array_map(function ($d) {
                return [
                    'deal_id' => (int) $d['id'], 'title' => $d['title'], 'value' => (float) $d['value'],
                    'currency' => $d['currency'], 'probability' => (int) $d['probability'],
                    'stage' => $d['stage_name'], 'expected_close_date' => $d['expected_close_date'],
                    'label' => 'Forecast',
                ];
            }, $likelyWins),
            'at_risk_deals' => $atRiskDeals,
            'potential_revenue' => [
                'value' => round($potentialRevenue, 2),
                'label' => 'Estimated (احتمالية × قيمة كل صفقة قريبة الإغلاق)',
            ],
            'forecast_confidence' => $hasEnoughHistory ? 'moderate' : null,
            'forecast_confidence_note' => $hasEnoughHistory
                ? 'مبني على ' . ($stats['won_deals'] + $stats['lost_deals']) . ' صفقة مغلقة سابقًا'
                : 'لا توجد بيانات كافية (أقل من 5 صفقات مغلقة) لتقدير موثوق - الأرقام أعلاه تقديرية أولية فقط',
        ];
    }
}
