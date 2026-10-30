<?php

/** AI Voice & Sales Agent (Phase 5 additions): call lists, retry gap, daily limit. No DB. */

use PHPUnit\Framework\TestCase;

foreach (['VoiceTimeWindows', 'VoiceCompliance', 'VoiceCapacity', 'VoiceNumberRules', 'VoiceMarkets', 'VoiceConsentService', 'VoiceSettingsService', 'VoiceCampaignService'] as $c) {
    require_once __DIR__ . '/../../app/Services/VoiceAgent/' . $c . '.php';
}

final class VoiceCampaignsTest extends TestCase
{
    private function noon(): int
    {
        return (new DateTimeImmutable('2026-11-04 12:00', new DateTimeZone('Europe/London')))->getTimestamp();
    }

    public function testListsAreCleanedDeduplicatedAcrossFormatsAndBounded(): void
    {
        $r = VoiceCampaignService::normalizeRecipients([
            ['to' => '+20 100 123 4567', 'contact_id' => 9, 'context' => ['first_name' => 'Sara'], 'evil' => 'x'],
            '+201001234567',               // same person, other spelling
            ['phone' => '+44 20 7123 4567'],
            '0100 123 4567',               // local format: refused, never guessed
            'abc',
            '',
        ]);
        $this->assertSame(['+201001234567', '+442071234567'], array_column($r['valid'], 'to'));
        $this->assertSame(9, $r['valid'][0]['contact_id']);
        $this->assertTrue(!isset($r['valid'][0]['evil']));
        $reasons = array_column($r['rejected'], 'reason');
        $this->assertSame(['duplicate_in_list', 'invalid_number', 'invalid_number', 'invalid_number'], $reasons);
    }

    public function testBatchSizeIsLimited(): void
    {
        $rows = [];
        for ($i = 0; $i < 520; $i++) {
            $rows[] = '+2010' . str_pad((string) (1000000 + $i), 7, '0', STR_PAD_LEFT);
        }
        $r = VoiceCampaignService::normalizeRecipients($rows);
        $this->assertCount(VoiceCampaignService::MAX_BATCH, $r['valid']);
        $this->assertCount(20, $r['rejected']);
        $this->assertSame('batch_limit_500', $r['rejected'][0]['reason']);
    }

    public function testCampaignSummaryAndAnswerRate(): void
    {
        $s = VoiceCampaignService::summarise([
            ['status' => 'completed', 'last_result' => 'answered', 'n' => 6, 'attempts' => 8],
            ['status' => 'completed', 'last_result' => 'voicemail', 'n' => 2, 'attempts' => 3],
            ['status' => 'exhausted', 'last_result' => 'no-answer', 'n' => 2, 'attempts' => 6],
            ['status' => 'blocked', 'last_result' => null, 'n' => 1, 'attempts' => 0],
            ['status' => 'scheduled', 'last_result' => null, 'n' => 4, 'attempts' => 0],
        ]);
        $this->assertSame(15, $s['total']);
        $this->assertSame(17, $s['attempts']);
        $this->assertSame(6, $s['by_result']['answered']);
        $this->assertSame(0.545, $s['answer_rate']); // 6 answered of 11 finished
        $this->assertNull(VoiceCampaignService::summarise([])['answer_rate']);
    }

    public function testRetryGapIsChosenByTheCompanyButNeverBelowOneHour(): void
    {
        $this->assertSame(4 * 3600, VoiceCompliance::gapSeconds(null));
        $this->assertSame(3600, VoiceCompliance::gapSeconds(1));
        $this->assertSame(6 * 3600, VoiceCompliance::gapSeconds('6'));
        $this->assertSame(48 * 3600, VoiceCompliance::gapSeconds(500));
        $this->assertSame(4 * 3600, VoiceCompliance::gapSeconds(0));
        $job = ['purpose' => 'trip_reminder', 'country' => 'GB', 'tz' => null, 'attempts' => 1, 'max_attempts' => 3];
        $facts = ['has_booking' => true, 'last_attempt_ts' => $this->noon() - 2 * 3600];
        $this->assertSame('frequency_limit', VoiceCompliance::evaluate($job, $facts, $this->noon())['reason']);              // default 4 h gap
        $this->assertSame('dial', VoiceCompliance::evaluate($job + ['min_gap_hours' => 1], $facts, $this->noon())['action']); // company chose 1 h
        $this->assertSame('wait', VoiceCompliance::evaluate($job + ['min_gap_hours' => 6], $facts, $this->noon())['action']);
    }

    public function testDailyCompanyLimit(): void
    {
        $this->assertFalse(VoiceCapacity::dailyCapReached(99, 100));
        $this->assertTrue(VoiceCapacity::dailyCapReached(100, 100));
        $this->assertTrue(VoiceCapacity::dailyCapReached(1, 0)); // a cap of 0 is treated as 1
        $this->assertSame(['daily_outbound_cap' => 250], VoiceSettingsService::sanitizeSettings(['daily_outbound_cap' => '250']));
        foreach ([0, 10001, 'x'] as $bad) {
            try {
                VoiceSettingsService::sanitizeSettings(['daily_outbound_cap' => $bad]);
                $this->fail('accepted');
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('1 and 10000', $e->getMessage());
            }
        }
    }

    public function testEveryListRecipientGoesThroughTheSameComplianceAsASingleCall(): void
    {
        $root = dirname(__DIR__, 2);
        $svc = file_get_contents($root . '/app/Services/VoiceAgent/VoiceCampaignService.php');
        $this->assertStringContainsString('$this->jobs->enqueue(', $svc);          // no shortcut around VoiceCompliance
        $this->assertStringNotContainsString('INSERT INTO voice_outbound_jobs', $svc);
        $out = file_get_contents($root . '/app/Services/VoiceAgent/VoiceOutboundService.php');
        $this->assertStringContainsString("campaign_cancelled", $out);              // pause/cancel are honoured at dial time
        $this->assertStringContainsString("\$c === 'paused'", $out);
        $this->assertStringContainsString('VoiceCapacity::dailyCapReached(', $out);
        $routes = file_get_contents($root . '/app/routes/voice_agent.php');
        foreach (['campaigns', 'campaigns/{id}/recipients', 'campaigns/{id}/status'] as $r) {
            $this->assertStringContainsString('/api/voice-agent/outbound/' . $r, $routes);
        }
        $mig = file_get_contents($root . '/database/migrations/2026_10_31_000010_voice_outbound.sql');
        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS voice_campaigns', $mig);
        $this->assertStringContainsString('min_gap_hours TINYINT UNSIGNED NULL', $mig);
    }
}
