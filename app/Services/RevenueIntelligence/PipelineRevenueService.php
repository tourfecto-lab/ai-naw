<?php

/**
 * Tourfecto - Deal & Pipeline Revenue Intelligence Service
 * @version 1.0.0
 *
 * Section 6: DEAL & PIPELINE REVENUE INTELLIGENCE
 *
 * يقرأ فقط من crm_deals/crm_pipeline_stages الموجودة (لا يعيد بناء CRM).
 * كل أرقام هذا القسم Pipeline/Forecast - مفصولة بوضوح عن Actual Revenue
 * (rev_revenue_records) كما ينص section 6 صراحةً؛ "Pipeline Revenue"
 * لا يُعامَل أبدًا كإيراد محقق.
 */
class PipelineRevenueService
{
    /** @var RevenueDataGateway */
    private $gateway;

    public function __construct(?RevenueDataGateway $gateway = null)
    {
        $this->gateway = $gateway ?? new RevenueDataGateway();
    }

    public function getPipelineIntelligence(int $userId): array
    {
        $openDeals = $this->gateway->getDeals($userId, 'open');
        $wonRecent = $this->gateway->getWonDealsByContact(
            $userId,
            (new DateTime('-90 days'))->format('Y-m-d H:i:s'),
            (new DateTime('now'))->format('Y-m-d H:i:s')
        );

        if (empty($openDeals) && empty($wonRecent)) {
            return ['has_data' => false, 'message' => 'Not enough data', 'pipeline' => null];
        }

        $avgWonPerMonth = count($wonRecent) > 0 ? array_sum(array_column($wonRecent, 'value')) / 3 : null;

        return ['has_data' => true, 'pipeline' => self::computePipeline($openDeals, $avgWonPerMonth)];
    }

    /**
     * Pure function قابلة للاختبار.
     * @param array $openDeals صفقات مفتوحة (من getDeals(status='open'))
     * @param float|null $avgActualRevenuePerMonth متوسط إيراد فعلي شهري حديث (للمقارنة/Coverage) - null لو غير متاح
     */
    public static function computePipeline(array $openDeals, ?float $avgActualRevenuePerMonth, ?string $nowStr = null): array
    {
        $now = new DateTime($nowStr ?? 'now');

        $pipelineValue = 0.0;
        $weightedPipeline = 0.0;
        $likelyWins = [];
        $atRiskDeals = [];

        foreach ($openDeals as $deal) {
            $value = (float) ($deal['value'] ?? 0);
            $probability = (int) ($deal['probability'] ?? 0);
            if ($probability <= 0) {
                $probability = (int) ($deal['stage_win_probability'] ?? 0);
            }

            $pipelineValue += $value;
            $weightedPipeline += $value * ($probability / 100);

            $expectedClose = $deal['expected_close_date'] ?? null;
            $isOverdue = $expectedClose !== null && new DateTime($expectedClose) < $now;

            if ($isOverdue) {
                $atRiskDeals[] = [
                    'id' => $deal['id'],
                    'title' => $deal['title'],
                    'value' => round($value, 2),
                    'expected_close_date' => $expectedClose,
                    'days_overdue' => (int) $now->diff(new DateTime($expectedClose))->format('%a'),
                    'stage' => $deal['stage_name'] ?? null,
                    'reason' => 'Past its expected close date and still open - may be stalled.',
                ];
            } elseif ($probability >= 70) {
                $likelyWins[] = [
                    'id' => $deal['id'],
                    'title' => $deal['title'],
                    'value' => round($value, 2),
                    'probability' => $probability,
                    'expected_close_date' => $expectedClose,
                    'stage' => $deal['stage_name'] ?? null,
                ];
            }
        }

        usort($atRiskDeals, static function ($a, $b) {
            return $b['days_overdue'] <=> $a['days_overdue'];
        });
        usort($likelyWins, static function ($a, $b) {
            return $b['value'] <=> $a['value'];
        });

        $coverage = null;
        if ($avgActualRevenuePerMonth !== null && $avgActualRevenuePerMonth > 0) {
            $coverage = round($weightedPipeline / $avgActualRevenuePerMonth, 2);
        }

        return [
            'pipeline_value' => round($pipelineValue, 2),
            'weighted_pipeline' => round($weightedPipeline, 2),
            'expected_revenue_note' => 'This is a forecast derived from open pipeline (value x probability), not actual/recognized revenue.',
            'open_deals_count' => count($openDeals),
            'likely_wins' => $likelyWins,
            'at_risk_deals' => $atRiskDeals,
            'pipeline_coverage' => $coverage,
            'pipeline_coverage_note' => $coverage !== null
                ? 'Weighted pipeline / average actual monthly revenue (last 90 days).'
                : 'Not enough recent actual revenue data to compute coverage.',
        ];
    }
}
