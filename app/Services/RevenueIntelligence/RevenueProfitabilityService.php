<?php

/**
 * Tourfecto - Revenue Profitability (Net margin per product/trip)
 * @version 2.0.0
 *
 * الإيراد لوحده ممكن يخدع: رحلة بتجيب أكبر إيراد ممكن تكون أقل ربح بعد
 * تكلفة الفندق/المرشد/المواصلات وعمولة الـ OTA. الخدمة دي بتحسب لكل منتج:
 *
 *   gross revenue  - تكاليف مباشرة (لكل حجز / لكل مسافر / نسبة من الإيراد)
 *                  - عمولة القناة (من إعدادات العمولات)
 *                  = صافي الربح + هامش الربح %
 *
 * القاعدة: المنتج اللي مالوش تكاليف مسجّلة بيظهر "has_costs=false" وهامشه
 * null - مش بنفترض تكلفة صفر (= لا أرقام مخترعة).
 */
class RevenueProfitabilityService
{
    /** @var RevenueDataGateway */
    private $gateway;

    public function __construct(?RevenueDataGateway $gateway = null)
    {
        $this->gateway = $gateway ?? new RevenueDataGateway();
    }

    public function getProfitability(int $userId, string $periodType = 'monthly'): array
    {
        $days = RevenueOverviewService::periodToDays($periodType);
        $now = new DateTimeImmutable('now');
        $bookings = $this->gateway->getBookings($userId, $now->modify("-{$days} days")->format('Y-m-d H:i:s'), $now->modify('+1 second')->format('Y-m-d H:i:s'));
        $result = self::compute(
            $bookings,
            $this->gateway->getProductCosts($userId),
            RevenueSettingsService::commissions($userId),
            RevenueSettingsService::baseCurrency($userId),
            FxRateService::latestRates($userId)
        );
        $result['period_type'] = $periodType;
        $result['costs'] = $this->gateway->getProductCosts($userId);
        $result['products_catalog'] = $this->gateway->getProducts($userId);
        return $result;
    }

    /** Pure. */
    public static function compute(array $bookings, array $costs, array $commissions, string $base, array $rates): array
    {
        // فهرسة التكاليف حسب المنتج
        $costIndex = [];
        foreach ($costs as $c) {
            $key = !empty($c['product_id']) ? 'p' . (int) $c['product_id'] : 'n' . mb_strtolower(trim((string) ($c['product_name'] ?? '')));
            $costIndex[$key][] = $c;
        }

        $products = [];
        $unconverted = [];
        foreach ($bookings as $b) {
            if (!in_array($b['status'] ?? '', ['confirmed', 'completed', 'no_show'], true)) {
                continue;
            }
            $cur = strtoupper((string) ($b['currency'] ?: 'USD'));
            $rev = FxRateService::convert((float) $b['total_amount'], $cur, $base, $rates);
            if ($rev === null) {
                $unconverted[$cur] = true;
                continue;
            }
            $pax = max(0, (int) ($b['adults_count'] ?? 0)) + max(0, (int) ($b['children_count'] ?? 0));
            $pk = !empty($b['product_id']) ? 'p' . (int) $b['product_id'] : 'n' . mb_strtolower(trim((string) ($b['product_name'] ?? '')));
            if (!isset($products[$pk])) {
                $products[$pk] = [
                    'product_id' => !empty($b['product_id']) ? (int) $b['product_id'] : null,
                    'product_name' => (string) ($b['product_name'] ?? '—'),
                    'bookings' => 0, 'travelers' => 0, 'revenue' => 0.0,
                    'direct_costs' => 0.0, 'commission' => 0.0,
                    'has_costs' => isset($costIndex[$pk]), 'cost_conversion_failed' => false,
                ];
            }
            $p = &$products[$pk];
            $p['bookings']++;
            $p['travelers'] += $pax;
            $p['revenue'] += $rev;

            foreach ($costIndex[$pk] ?? [] as $c) {
                $amt = (float) $c['amount'];
                if ($c['cost_type'] === 'percent_of_revenue') {
                    $p['direct_costs'] += $rev * $amt / 100;
                    continue;
                }
                $conv = FxRateService::convert($amt, strtoupper((string) ($c['currency'] ?: $base)), $base, $rates);
                if ($conv === null) {
                    $p['cost_conversion_failed'] = true;
                    continue;
                }
                $p['direct_costs'] += $c['cost_type'] === 'per_booking' ? $conv : $conv * max(1, $pax);
            }

            $pct = TourismKpiService::commissionFor(TourismKpiService::channelKey($b['source'] ?? null), $commissions);
            if ($pct !== null) {
                $p['commission'] += $rev * $pct / 100;
            }
            unset($p);
        }

        $totals = ['revenue' => 0.0, 'direct_costs' => 0.0, 'commission' => 0.0, 'profit' => 0.0, 'revenue_with_costs' => 0.0];
        foreach ($products as $k => $p) {
            $profit = $p['revenue'] - $p['direct_costs'] - $p['commission'];
            $complete = $p['has_costs'] && !$p['cost_conversion_failed'];
            $products[$k]['revenue'] = round($p['revenue'], 2);
            $products[$k]['direct_costs'] = round($p['direct_costs'], 2);
            $products[$k]['commission'] = round($p['commission'], 2);
            $products[$k]['net_profit'] = $complete ? round($profit, 2) : null;
            $products[$k]['margin_percent'] = $complete && $p['revenue'] > 0 ? round($profit / $p['revenue'] * 100, 1) : null;
            $products[$k]['profit_per_traveler'] = $complete && $p['travelers'] > 0 ? round($profit / $p['travelers'], 2) : null;
            $totals['revenue'] += $p['revenue'];
            $totals['commission'] += $p['commission'];
            if ($complete) {
                $totals['direct_costs'] += $p['direct_costs'];
                $totals['profit'] += $profit;
                $totals['revenue_with_costs'] += $p['revenue'];
            }
        }
        usort($products, static function ($a, $b) {
            return [($b['net_profit'] ?? -INF), $b['revenue']] <=> [($a['net_profit'] ?? -INF), $a['revenue']];
        });

        $withCosts = array_values(array_filter($products, static function ($p) {
            return $p['net_profit'] !== null;
        }));
        $lossMakers = array_values(array_filter($withCosts, static function ($p) {
            return $p['net_profit'] < 0;
        }));

        return [
            'has_data' => !empty($products),
            'currency' => $base,
            'products' => array_values($products),
            'coverage_percent' => $totals['revenue'] > 0 ? round($totals['revenue_with_costs'] / $totals['revenue'] * 100, 1) : null,
            'totals' => [
                'revenue' => round($totals['revenue'], 2),
                'direct_costs' => round($totals['direct_costs'], 2),
                'commission' => round($totals['commission'], 2),
                'net_profit' => $totals['revenue_with_costs'] > 0 ? round($totals['profit'], 2) : null,
                'margin_percent' => $totals['revenue_with_costs'] > 0 ? round($totals['profit'] / $totals['revenue_with_costs'] * 100, 1) : null,
            ],
            'most_profitable' => $withCosts[0] ?? null,
            'loss_makers' => $lossMakers,
            'unconverted_currencies' => array_keys($unconverted),
            'note' => 'Margins are shown only for products with recorded costs. Products without costs are never assumed to be cost-free.',
        ];
    }

    /** Insights: منتج بيخسر = مخاطرة، منتج بهامش عالي = فرصة. Pure. */
    public static function toInsights(array $result): array
    {
        $out = [];
        foreach (array_slice($result['loss_makers'] ?? [], 0, 3) as $p) {
            $out[] = [
                'type' => 'risk', 'category' => 'loss_making_product',
                'title' => 'Product sold at a loss: ' . $p['product_name'],
                'finding' => "{$p['product_name']} generated {$p['revenue']} {$result['currency']} but net profit was {$p['net_profit']} after costs and commissions.",
                'evidence' => ['product' => $p], 'reasoning_summary' => 'Revenue minus recorded direct costs minus channel commission.',
                'confidence' => 'high', 'severity' => 'high', 'estimated_impact' => abs((float) $p['net_profit']),
                'affected_area' => 'profitability',
                'recommended_action' => 'Raise the price, renegotiate supplier costs, or stop selling it through high-commission channels.',
            ];
        }
        $best = $result['most_profitable'] ?? null;
        if ($best && ($best['margin_percent'] ?? 0) >= 30) {
            $out[] = [
                'type' => 'opportunity', 'category' => 'high_margin_product',
                'title' => 'Push your highest-margin product: ' . $best['product_name'],
                'finding' => "{$best['product_name']} has a {$best['margin_percent']}% net margin ({$best['profit_per_traveler']} {$result['currency']} profit per traveler).",
                'evidence' => ['product' => $best], 'reasoning_summary' => 'Highest net profit among products with recorded costs.',
                'confidence' => 'high', 'severity' => null, 'estimated_impact' => $best['net_profit'],
                'affected_area' => 'profitability',
                'recommended_action' => 'Give this product more ad budget and feature it on your website and WhatsApp offers.',
            ];
        }
        return $out;
    }
}
