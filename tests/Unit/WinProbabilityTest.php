<?php

use PHPUnit\Framework\TestCase;

/**
 * احتمال اشتراك الشركة (تقدير من نشاطها): بيزيد بالتفاعل ويقفل عند الرفض/الرقم الغلط/إلغاء الاشتراك.
 */
final class WinProbabilityTest extends TestCase
{
    public function testColdLeadStartsLowAndScalesWithPriority(): void
    {
        $low = PlatformOutreachService::winProbability(['status' => 'new'], 0);
        $high = PlatformOutreachService::winProbability(['status' => 'new'], 100);
        $this->assertSame(3, $low['pct']);
        $this->assertSame('cold', $low['level']);
        $this->assertSame(15, $high['pct']);
        $this->assertSame('warm', $high['level']);
    }

    public function testEngagementRaisesProbabilityAndExplainsWhy(): void
    {
        $base = PlatformOutreachService::winProbability(['status' => 'contacted'], 50)['pct'];
        $opened = PlatformOutreachService::winProbability(['status' => 'contacted', 'report_views' => 1, 'report_viewed_at' => '2026-11-05 10:00:00'], 50);
        $this->assertSame($base + 12, $opened['pct']);
        $twice = PlatformOutreachService::winProbability(['status' => 'contacted', 'report_views' => 3, 'report_viewed_at' => '2026-11-05 10:00:00', 'whatsapp_at' => '2026-11-05 11:00:00', 'clicked_at' => '2026-11-05 11:00:00'], 50);
        $this->assertSame($base + 12 + 6 + 10 + 8, $twice['pct']);
        $this->assertContains('فتحت التقرير (+12٪)', $twice['why']);
        $replied = PlatformOutreachService::winProbability(['status' => 'contacted', 'desk_action' => 'replied', 'report_views' => 2], 50);
        $interested = PlatformOutreachService::winProbability(['status' => 'contacted', 'desk_action' => 'interested', 'report_views' => 2], 50);
        $trial = PlatformOutreachService::winProbability(['status' => 'contacted', 'desk_action' => 'trial', 'report_views' => 2], 50);
        $this->assertLessThan($interested['pct'], $replied['pct']);
        $this->assertLessThan($trial['pct'], $interested['pct']);
    }

    public function testCapsAndTiers(): void
    {
        $max = PlatformOutreachService::winProbability(['status' => 'contacted', 'desk_action' => 'trial', 'report_views' => 9, 'report_viewed_at' => 'x', 'whatsapp_at' => 'x', 'clicked_at' => 'x'], 100);
        $this->assertSame(90, $max['pct'], 'مفيش حد يوصل 100٪ قبل ما يشترك فعلًا');
        $this->assertSame('strong', $max['level']);
        $hot = PlatformOutreachService::winProbability(['status' => 'contacted', 'desk_action' => 'replied'], 50);
        $this->assertSame('hot', $hot['level']);
    }

    public function testClosedAndWonStates(): void
    {
        $this->assertSame(95, PlatformOutreachService::winProbability(['status' => 'signed_up'], 10)['pct']);
        $this->assertSame(95, PlatformOutreachService::winProbability(['status' => 'contacted', 'signup_user_id' => 5], 10)['pct']);
        $this->assertSame(95, PlatformOutreachService::winProbability(['status' => 'contacted', 'desk_action' => 'subscribed'], 10)['pct']);
        $this->assertSame(1, PlatformOutreachService::winProbability(['status' => 'contacted', 'desk_action' => 'not_interested', 'report_views' => 5], 90)['pct']);
        $this->assertSame(0, PlatformOutreachService::winProbability(['status' => 'unsubscribed'], 90)['pct']);
        $this->assertSame(0, PlatformOutreachService::winProbability(['status' => 'bounced'], 90)['pct']);
        $this->assertSame(0, PlatformOutreachService::winProbability(['status' => 'contacted', 'desk_action' => 'wrong_number'], 90)['pct']);
    }
}
