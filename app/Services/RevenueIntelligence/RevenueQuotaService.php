<?php

/**
 * Tourfecto - Revenue Quota / Goals Service
 * @version 1.0.0
 *
 * G7 (التحليل التنافسي Revenue Intelligence): أهداف/حصص المبيعات
 * (Quotas) — كانت المنصة تملك جدول `crm_sales_goals` (أهداف شهرية
 * لكل حساب) وتقرير إنجاز في موديول CRM، لكن موديول ذكاء الإيرادات
 * لا يقرأه إطلاقًا. هذا الكلاس يقرأ نفس الجدول الحقيقي (عزل تينانت
 * بـ user_id) ويقدّم زاوية إيراد متكاملة لكل شهر:
 *   - الإنجاز الفعلي من الإيراد المسجَّل (rev_revenue_records) + إشارة
 *     منفصلة لإيراد الصفقات المكسوبة (crm_deals).
 *   - التنبؤ بالتحقيق من الصفقات المفتوحة المقررة في الشهر نفسه
 *     (weighted pipeline).
 *   - الفجوة للهدف + نسبة الإنجاز الحالية والمتوقعة + حالة واضحة.
 *
 * الفلسفة نفسها: لا أرقام مخترعة. لو مفيش هدف مسجّل → "Not enough
 * data" صريحة بدل ما نفتعل هدفًا صفريًا.
 */
class RevenueQuotaService
{
    /** @var Database */
    private $db;
    /** @var RevenueDataGateway */
    private $gateway;

    public function __construct(?RevenueDataGateway $gateway = null)
    {
        $this->db = Database::getInstance();
        $this->gateway = $gateway ?? new RevenueDataGateway();
    }

    /**
     * تقرير الأهداف/الحصص مع الإنجاز والتنبؤ لكل شهر.
     *
     * @return array{has_data:bool, message?:string, quotas:array<int,array>, note?:string}
     */
    public function getQuotas(int $userId, ?string $period = null): array
    {
        $goals = (new CrmSalesGoal())->allForUser($userId);
        if (empty($goals)) {
            return [
                'has_data' => false,
                'message' => 'Not enough data for Quotas — set a monthly sales goal first (CRM > Reports > Sales Goals).',
            ];
        }

        $targets = [];
        foreach ($goals as $goal) {
            $p = (string) ($goal['period'] ?? '');
            if (!preg_match('/^\d{4}-\d{2}$/', $p)) {
                continue;
            }
            $targets[$p] = (float) ($goal['target_value'] ?? 0);
        }
        if (empty($targets)) {
            return ['has_data' => false, 'message' => 'Not enough data for Quotas — no valid goal periods recorded.'];
        }

        // فلترة بشهر واحد لو اتنقل له (الواجهة بتمرر ?period=YYYY-MM)
        if ($period !== null && isset($targets[$period])) {
            $targets = [$period => $targets[$period]];
        }

        krsort($targets);
        $currentMonth = gmdate('Y-m');
        $quotas = [];

        foreach ($targets as $p => $target) {
            if(!$this->gateway->hasComparableCurrency($userId))return ['has_data'=>false,'message'=>'Multiple or unknown currencies; quota forecasts are unavailable until currency is unified.'];
            $achieved = $this->gateway->getRevenueSumForMonth($userId, $p);
            $wonDeals = $this->gateway->getWonDealsForMonth($userId, $p);
            $openDeals = $this->gateway->getOpenDealsForMonth($userId, $p);

            $forecast = 0.0;
            $openDealCount = 0;
            foreach ($openDeals as $deal) {
                $prob = (float) ($deal['effective_probability'] ?? $deal['probability'] ?? 0);
                if ($prob <= 0) {
                    continue;
                }
                $forecast += (float) ($deal['value'] ?? 0) * ($prob / 100);
                $openDealCount++;
            }

            $projected = $achieved + $forecast;
            $progress = $target > 0 ? round(($achieved / $target) * 100, 1) : null;
            $projectedProgress = $target > 0 ? round(($projected / $target) * 100, 1) : null;
            $isCurrentOrFuture = $p >= $currentMonth;

            $quotas[] = [
                'period' => $p,
                'target_value' => round($target, 2),
                'achieved_value' => round($achieved, 2),
                'won_deals_value' => round($wonDeals, 2),
                'forecast_value' => round($forecast, 2),
                'open_deal_count' => $openDealCount,
                'projected_value' => round($projected, 2),
                'progress_percent' => $progress,
                'projected_progress_percent' => $projectedProgress,
                'gap_to_target' => round($target - $achieved, 2),
                'status' => $this->statusFor($progress, $projectedProgress, $isCurrentOrFuture),
            ];
        }

        return [
            'has_data' => true,
            'quotas' => $quotas,
            'note' => 'Achieved = recorded revenue (rev_revenue_records); Won deals = CRM reference; Forecast = weighted open pipeline scheduled in the period.',
        ];
    }

    /**
     * حالة الشهر بناءً على الإنجاز الحالي والمتوقع من الخط.
     * الشهور الماضية تحكمها النتيجة النهائية فقط (ahead/behind).
     */
    private function statusFor(?float $progress, ?float $projectedProgress, bool $isCurrentOrFuture): string
    {
        if ($progress !== null && $progress >= 100) {
            return 'ahead';
        }
        if (!$isCurrentOrFuture) {
            return 'behind';
        }
        if ($projectedProgress === null) {
            return 'behind';
        }
        if ($projectedProgress >= 100) {
            return 'on_track';
        }
        if ($projectedProgress >= 60) {
            return 'at_risk';
        }
        return 'behind';
    }
}
