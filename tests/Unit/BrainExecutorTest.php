<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/Services/Execution/ActionExecutor.php';
require_once __DIR__ . '/../../app/Services/Brain/BrainExecutor.php';

/** تحويل قرار العقل لإجراء التنفيذ الموحّد */
class BrainExecutorTest extends TestCase
{
    private array $decision = ['key' => 'promote_peak_7', 'area' => 'social', 'impact' => 2, 'title' => ['ar' => 'روّج', 'en' => 'Promote'],
        'why' => ['ar' => 'لأن', 'en' => 'Because'], 'evidence' => [['ar' => '1 حجز', 'en' => '1 booking']], 'action_url' => '/social#plan'];

    public function testActionCarriesDedupKeyWebsiteAndSeverity(): void
    {
        $a = BrainExecutor::actionFor($this->decision, 33, 'en');
        $this->assertSame('Promote', $a['action']);
        $this->assertSame("Because\n• 1 booking", $a['reason']);
        $this->assertSame('website:33', $a['affected_area']);
        $this->assertSame('promote_peak_7', $a['dedup_key']);
        $this->assertSame('medium', $a['severity']);
        $this->assertSame('brain', $a['source_type']);
        $this->assertSame('روّج', BrainExecutor::actionFor($this->decision, 33, 'ar')['action']);
    }

    public function testRisksAndOpportunities(): void
    {
        $this->assertSame(true, BrainExecutor::isRisk(['key' => 'travel_advice_EG']));
        $this->assertSame(true, BrainExecutor::isRisk(['key' => 'reply_negative_reviews']));
        $this->assertSame(false, BrainExecutor::isRisk(['key' => 'promote_peak_7']));
        $this->assertSame(false, BrainExecutor::isRisk(['key' => 'holiday_20261225']));
    }
}
