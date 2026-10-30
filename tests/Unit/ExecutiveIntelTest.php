<?php

/** TOURFECTO AI CEO (المرحلة 9): مكونات مؤشر صحة الشركة، "غير مكتمل" بدل درجة مخترعة، التغيرات، بصمة الصفحة، مقارنة الأسعار */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/Services/AiCeo/ExecutiveIntel.php';

final class ExecutiveIntelTest extends TestCase
{
    private function snap(): array
    {
        return ['revenue' => ['mrr' => ['now' => 110.0, 'prev' => 100.0], 'paying' => ['now' => 10, 'prev' => 10], 'logo_churn' => ['now' => 4.0, 'prev' => 2.0]],
            'signups' => ['last_7d' => 10, 'prev_7d' => 10], 'platform' => ['counts' => ['fail' => 1, 'warn' => 2]],
            'ops' => ['last_check_at' => '2026-10-29 10:00:00', 'pages_failed' => 0, 'pages_slow' => 1, 'incidents_open' => ['high' => 1], 'abuse_ips' => 0, 'abuse_users' => 1], 'success' => ['support_unanswered' => 2]];
    }

    public function testWeightsSumTo100(): void
    {
        $this->assertSame(100, array_sum(ExecutiveIntel::WEIGHTS));
        $this->assertSame(array_keys(ExecutiveIntel::WEIGHTS), array_keys(ExecutiveIntel::METHOD));
    }

    public function testComponents(): void
    {
        $c = ExecutiveIntel::components($this->snap(), ['fixed_entered' => true, 'margin_pct' => 20.0, 'revenue_30d' => 100, 'ai_cost_30d' => 10, 'brand_reviews' => 13, 'brand_rating' => 4.5, 'auth_incidents' => 0]);
        // النمو: MRR +10% → 80، التسجيلات زي ما هي → 50، المتوسط 65
        $this->assertSame(65, $c['growth']);
        $this->assertSame(70, $c['profitability']);
        $this->assertSame(88, $c['satisfaction']);
        $this->assertSame(80, $c['retention']);
        $this->assertSame(91, $c['quality']);
        $this->assertSame(69, $c['stability']);
        $this->assertSame(95, $c['security']);
        $this->assertSame(100, $c['efficiency']);
        $s = ExecutiveIntel::score($c);
        $this->assertSame(true, $s['complete']);
        $this->assertSame(80, $s['score']);
    }

    public function testGrowthPartsAreCappedBeforeAveraging(): void
    {
        // MRR وقع لصفر (0) + تسجيلات قفزت (100) = 50، مش 80
        $c = ExecutiveIntel::components(['revenue' => ['mrr' => ['now' => 0.0, 'prev' => 573.17], 'paying' => ['now' => 0, 'prev' => 2], 'logo_churn' => ['now' => 100.0]], 'signups' => ['last_7d' => 5, 'prev_7d' => 0]], []);
        $this->assertSame(50, $c['growth']);
        $this->assertSame(0, $c['retention']);
    }

    public function testIncompleteDataGivesNoScore(): void
    {
        $c = ExecutiveIntel::components($this->snap(), ['fixed_entered' => false, 'revenue_30d' => 0, 'ai_cost_30d' => 0, 'brand_reviews' => 0, 'brand_rating' => null]);
        $this->assertSame(null, $c['profitability']);
        $this->assertSame(null, $c['satisfaction']);
        $this->assertSame(null, $c['efficiency']);
        $s = ExecutiveIntel::score($c);
        $this->assertSame(null, $s['score']);
        $this->assertSame(65, $s['coverage']);
        $this->assertSame(['profitability', 'satisfaction', 'efficiency'], $s['missing']);
        $this->assertSame(true, $s['partial_score'] > 0);
        $this->assertSame(['score' => null, 'partial_score' => null, 'coverage' => 0, 'complete' => false, 'missing' => array_keys(ExecutiveIntel::WEIGHTS)], ExecutiveIntel::score(array_fill_keys(array_keys(ExecutiveIntel::WEIGHTS), null)));
        // إيراد صفر وتكلفة ذكاء اصطناعي = كفاءة صفر (بيانات حقيقية مش ناقصة)
        $this->assertSame(0, ExecutiveIntel::components([], ['revenue_30d' => 0, 'ai_cost_30d' => 5])['efficiency']);
    }

    public function testChanges(): void
    {
        $r = ExecutiveIntel::changes([['label' => 'Growth', 'value' => 70, 'previous' => 60], ['label' => 'Security', 'value' => 80, 'previous' => 95], ['label' => 'Quality', 'value' => 50, 'previous' => 49], ['label' => 'X', 'value' => null, 'previous' => 10]]);
        $this->assertSame(['Growth +10'], $r['improved']);
        $this->assertSame(['Security -15'], $r['declined']);
    }

    public function testFingerprintIgnoresScriptsAndClock(): void
    {
        $a = ExecutiveIntel::pageFingerprint('<title>Rival — Tours</title><script>var t=1</script><p>Plans from $49</p><p>Updated 2026-10-29 10:15</p>');
        $b = ExecutiveIntel::pageFingerprint('<title>Rival — Tours</title><script>var t=2</script><p>Plans from $49</p><p>Updated 2026-10-30 11:20</p>');
        $c = ExecutiveIntel::pageFingerprint('<title>Rival — Tours</title><p>Plans from $59</p>');
        $this->assertSame('Rival — Tours', $a['title']);
        $this->assertSame($a['hash'], $b['hash']);
        $this->assertSame(false, $a['hash'] === $c['hash']);
    }

    public function testPriceComparison(): void
    {
        $p = ExecutiveIntel::priceComparison([['starting_price_usd' => 30.0], ['starting_price_usd' => 50.0], ['starting_price_usd' => null], ['starting_price_usd' => 99.0], ['starting_price_usd' => 70.0]], [['name' => 'Starter', 'price' => 29.0, 'cycle' => 'monthly']]);
        $this->assertSame(60.0, $p['competitor_median']);
        $this->assertSame(4, $p['competitors_priced']);
        $this->assertSame('below market', $p['position']);
        $this->assertSame(null, ExecutiveIntel::priceComparison([], [])['position']);
    }

    public function testReportText(): void
    {
        $t = ExecutiveIntel::reportText(['happened' => ['3 new sign-ups'], 'improved' => [], 'declined' => ['Security -15'], 'problem' => 'Platform pages are down', 'priorities' => ['A'], 'needs_approval' => [], 'results' => [], 'next_step' => 'Fix it',
            'health' => ['score' => null, 'partial_score' => 70, 'coverage' => 65]]);
        foreach (['incomplete (70 on 65% of the weight)', "What happened:\n- 3 new sign-ups", "Improved:\n- none", 'Main problem: Platform pages are down', 'Next step: Fix it'] as $n) $this->assertStringContainsString($n, $t);
    }
}
