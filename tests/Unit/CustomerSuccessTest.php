<?php

/** TOURFECTO AI CEO (المرحلة 6): خطوات الإعداد، الرسائل المقترحة، مواضيع الدعم، اقتراحات الأسعار، وتوقع الإيراد */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/Services/AiCeo/CustomerSuccess.php';

final class CustomerSuccessTest extends TestCase
{
    public function testProgressFindsNextStep(): void
    {
        $this->assertSame('website', CustomerSuccess::progress(['sites' => 0])['next']);
        $this->assertSame('connected', CustomerSuccess::progress(['sites' => 1, 'connected' => 0])['next']);
        $this->assertSame('used', CustomerSuccess::progress(['sites' => 1, 'connected' => 2, 'ai_calls' => 0])['next']);
        $this->assertSame('paying', CustomerSuccess::progress(['sites' => 1, 'connected' => 1, 'ai_calls' => 5, 'trials' => 1, 'paying' => 0])['next']);
        $this->assertSame(null, CustomerSuccess::progress(['sites' => 1, 'connected' => 1, 'ai_calls' => 5, 'paying' => 1])['next']);
    }

    public function testNudgeIsBilingualAndNamed(): void
    {
        $m = CustomerSuccess::nudge('website', 'Nile Tours');
        $this->assertStringContainsString('Nile Tours', $m['ar']);
        $this->assertStringContainsString('add your website', $m['en']);
        $this->assertSame(['ar' => '', 'en' => ''], CustomerSuccess::nudge('nope', ''));
    }

    public function testThemes(): void
    {
        $t = CustomerSuccess::themes(['I cannot login, password reset broken', 'مش عارف أربط الواتساب', 'WhatsApp not working', 'الدفع بالكارت فشل', '', null]);
        $this->assertSame(2, $t['whatsapp']);
        $this->assertSame(1, $t['login']);
        $this->assertSame(1, $t['payment']);
        $this->assertSame(2, $t['bug'] ?? 0, 'broken + not working');
    }

    public function testPricingSuggestions(): void
    {
        $s = CustomerSuccess::pricingSuggestions([['plan' => 'Starter', 'customers' => 4, 'price' => 29, 'margin_pct' => 35.0], ['plan' => 'Agency', 'customers' => 0, 'price' => 199, 'margin_pct' => null], ['plan' => 'Pro', 'customers' => 3, 'price' => 79, 'margin_pct' => 90.0]], 120.0);
        $types = array_map(static fn ($x) => $x['plan'] . ':' . $x['type'], $s);
        $this->assertSame(['Starter:low_margin', 'Starter:below_market', 'Agency:unused'], $types);
        $this->assertSame([], CustomerSuccess::pricingSuggestions([['plan' => 'Pro', 'customers' => 3, 'price' => 79, 'margin_pct' => 90.0]]));
    }

    public function testForecastRanges(): void
    {
        $f = CustomerSuccess::forecast(1000, 10, 100, [1, 3], 4);
        // متوقع: 1000×0.9+100 = 1000 كل شهر
        $this->assertSame(1000.0, $f['cases']['expected'][1]);
        $this->assertSame(1000.0, $f['cases']['expected'][3]);
        // متحفظ: 1000×0.85+50 = 900
        $this->assertSame(900.0, $f['cases']['conservative'][1]);
        // متفائل: 1000×0.95+150 = 1100
        $this->assertSame(1100.0, $f['cases']['optimistic'][1]);
        $this->assertSame(true, $f['cases']['conservative'][3] < $f['cases']['expected'][3] && $f['cases']['expected'][3] < $f['cases']['optimistic'][3]);
        $this->assertSame('low', $f['confidence']);
        $this->assertSame(0.0, CustomerSuccess::forecast(0, 0, 0, [3])['cases']['expected'][3]);
    }
}
