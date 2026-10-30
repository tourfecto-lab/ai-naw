<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/Services/Brain/GrowthAdvisor.php';
require_once __DIR__ . '/../../app/Services/Brain/StrategicDecisions.php';

/** توصيات النمو: أسواق جديدة، باقات، تسعير قدام المنافسين، ومتابعة العملاء المحتملين */
class GrowthAdvisorTest extends TestCase
{
    public function testMarketMixNormalisesCountries(): void
    {
        $mix = GrowthAdvisor::marketMix([['country' => 'Germany', 'c' => 6], ['country' => 'de', 'c' => 2], ['country' => 'UK', 'c' => 4], ['country' => 'Narnia', 'c' => 9]]);
        $this->assertSame([['country' => 'DE', 'contacts' => 8, 'share' => 0.67], ['country' => 'GB', 'contacts' => 4, 'share' => 0.33]], $mix);
    }

    public function testPricingMatchesSimilarTripsInTheSameCurrency(): void
    {
        $products = [['id' => 1, 'name' => 'Abu Simbel Private Day Trip from Aswan', 'price' => 300, 'currency' => 'USD'], ['id' => 2, 'name' => 'Day tour', 'price' => 50, 'currency' => 'USD']];
        $comp = [
            ['product_name' => 'Abu Simbel temples tour from Aswan', 'price' => 200, 'currency' => 'USD', 'detected_at' => '2026-10-01 10:00:00', 'competitor_domain' => 'a.com'],
            ['product_name' => 'Abu Simbel by bus from Aswan', 'price' => 240, 'currency' => 'usd', 'detected_at' => '2026-10-02 10:00:00', 'competitor_domain' => 'b.com'],
            ['product_name' => 'Abu Simbel from Aswan', 'price' => 900, 'currency' => 'EGP', 'detected_at' => '2026-10-02 10:00:00', 'competitor_domain' => 'c.com'],
            ['product_name' => 'Luxor balloon', 'price' => 90, 'currency' => 'USD', 'detected_at' => '2026-10-02 10:00:00', 'competitor_domain' => 'd.com'],
        ];
        $p = GrowthAdvisor::pricing($products, $comp);
        $this->assertCount(1, $p);
        $this->assertSame(220.0, $p[0]['competitor_median']);
        $this->assertSame(36.4, $p[0]['diff_pct']);
        $this->assertCount(2, $p[0]['matches']);
    }

    public function testGrowthDecisions(): void
    {
        $s = ['understanding' => ['markets' => ['countries' => ['GB']]], 'market' => ['destination' => 'EG', 'markets' => ['GB', 'US']],
            'growth' => ['focus_trips' => [['id' => 5, 'reason' => 'rising_demand']]],
            'growth_advice' => ['overdue_leads' => 6,
                'market_mix' => [['country' => 'GB', 'contacts' => 20, 'share' => 0.5], ['country' => 'DE', 'contacts' => 8, 'share' => 0.2], ['country' => 'FR', 'contacts' => 3, 'share' => 0.08]],
                'bundles' => [['a' => 'Luxor', 'b' => 'Aswan', 'customers' => 4, 'price' => 150.0, 'currency' => 'USD']],
                'pricing' => [['product' => 'Abu Simbel', 'product_id' => 1, 'my_price' => 300.0, 'currency' => 'USD', 'competitor_median' => 220.0, 'diff_pct' => 36.4, 'matches' => []],
                    ['product' => 'Nile cruise', 'product_id' => 5, 'my_price' => 100.0, 'currency' => 'USD', 'competitor_median' => 150.0, 'diff_pct' => -33.3, 'matches' => []]]]];
        $d = StrategicDecisions::growthDecisions($s);
        $keys = array_column($d, 'key');
        $this->assertSame('follow_up_leads', $keys[0]);
        $this->assertSame(3, $d[0]['impact']);
        $this->assertContains('new_market_DE', $keys);
        $this->assertSame(false, in_array('new_market_GB', $keys, true)); // مستهدف خلاص
        $this->assertSame(false, in_array('new_market_FR', $keys, true)); // عدد قليل
        $this->assertSame(1, count(array_filter($keys, static fn ($k) => str_starts_with($k, 'bundle_'))));
        $this->assertContains('price_above_1', $keys);
        $this->assertContains('price_room_5', $keys); // أرخص والطلب عالي
        $this->assertSame([], StrategicDecisions::growthDecisions(['growth_advice' => null]));
    }

    public function testProfitabilitySummaryIgnoresProductsWithoutCosts(): void
    {
        $this->assertNull(GrowthAdvisor::profitability(null));
        $this->assertNull(GrowthAdvisor::profitability(['has_data' => false]));
        $r = ['has_data' => true, 'currency' => 'USD', 'coverage_percent' => 40.0, 'totals' => ['margin_percent' => 12.5], 'products' => [
            ['product_id' => 1, 'product_name' => 'Loss', 'revenue' => 1000.0, 'net_profit' => -50.0, 'margin_percent' => -5.0],
            ['product_id' => 2, 'product_name' => 'Thin', 'revenue' => 1000.0, 'net_profit' => 100.0, 'margin_percent' => 10.0],
            ['product_id' => 3, 'product_name' => 'Healthy', 'revenue' => 1000.0, 'net_profit' => 400.0, 'margin_percent' => 40.0],
            ['product_id' => 4, 'product_name' => 'No costs', 'revenue' => 5000.0, 'net_profit' => null, 'margin_percent' => null]]];
        $p = GrowthAdvisor::profitability($r);
        $this->assertSame(['Loss'], array_column($p['loss_makers'], 'product'));
        $this->assertSame(['Thin'], array_column($p['thin_margin'], 'product'));

        $keys = array_column(StrategicDecisions::growthDecisions(['growth_advice' => ['profitability' => $p]]), 'key');
        $this->assertContains('loss_maker_1', $keys);
        $this->assertContains('thin_margin_2', $keys);
        $this->assertContains('record_trip_costs', $keys); // التغطية 40%
        $this->assertNotContains('loss_maker_4', $keys);
    }
}
