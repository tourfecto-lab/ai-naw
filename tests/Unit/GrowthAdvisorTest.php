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
}
