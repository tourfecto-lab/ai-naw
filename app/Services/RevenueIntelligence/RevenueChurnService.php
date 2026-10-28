<?php

/**
 * Tourfecto - Revenue Churn Analytics
 * @version 1.0.0
 *
 * v1.5.0 (section C): Churn analytics بما فيه تصنيف أسباب التوقف.
 * يعتمد على pure functions في RevenueBenchmarkService::classifyChurnReason
 * + حارس عدم تخمين الأسباب.
 */
class RevenueChurnService
{
    /** @var RevenueDataGateway */
    private $gateway;

    public function __construct(?RevenueDataGateway $gateway = null)
    {
        $this->gateway = $gateway ?? new RevenueDataGateway();
    }

    /**
     * تحليل التوقف الكامل لمستخدم: أسباب + عدد خاسر/ملغي + مدة العضوية
     * عند توفر بيانات كافية فقط.
     */
    public function getChurnAnalytics(int $userId): array
    {
        $deals = $this->gateway->getDealsWithRep($userId);
        $events = $this->gateway->hasBizSubscriptionTables()
            ? $this->gateway->getBizSubscriptionEvents($userId)
            : [];

        $churnedDeals = array_filter($deals, static function ($d) {
            return in_array($d['status'] ?? '', ['lost', 'cancelled', 'expired'], true);
        });
        $hasDealData = count($churnedDeals) > 0 || count(array_filter($events, static function ($e) {
            return ($e['event_type'] ?? '') === 'churn';
        })) > 0;

        if (!$hasDealData) {
            return ['has_data' => false, 'reason' => 'Not enough data: no lost/cancelled deals or churn events recorded yet. Connect churn data (lost deals or biz_subscription_events event_type=churn) to enable churn analytics.'];
        }

        $agg = RevenueBenchmarkService::aggregateChurnReasons(array_values($churnedDeals), $events);

        return [
            'has_data' => true,
            'reason' => null,
            'total_churned' => $agg['total_churned'],
            'by_reason' => $agg['by_reason'],
            'top_reason' => $agg['top_reason'],
            'note' => 'Churn reasons are only inferred from real data: explicit lost_reason on deals, churn_reason on subscription events, or (low confidence) lost/cancelled status without reason. No reasons are invented.',
        ];
    }
}
