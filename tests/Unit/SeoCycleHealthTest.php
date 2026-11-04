<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/Services/SeoExpert/SeoCycleHealth.php';

/** A stalled cycle is resumable only when no job exists and nothing paid or planned yet happened. */
final class SeoCycleHealthTest extends TestCase
{
    private function eval(string $phase, array $jobs = [], bool $worker = true, int $tasks = 0, int $ageSeconds = 3600): array
    {
        $now = 1_800_000_000;
        $run = ['id' => 7, 'status' => $phase, 'updated_at' => gmdate('Y-m-d H:i:s', $now - $ageSeconds)];
        return SeoCycleHealth::evaluate($run, $jobs, true, $worker, true, 'reserved', $tasks, $now);
    }

    public function testScannedRunWithoutJobIsRecoverableAndTraceable(): void
    {
        $h = $this->eval('scanned');
        $this->assertTrue($h['recoverable']);
        $this->assertSame('missing_continuation_job', $h['state']);
        $this->assertSame('SEO-7', $h['reference']);
        $this->assertTrue($h['no_new_cycle_charge']);
    }

    public function testScanningRunWithoutJobIsRecoverable(): void
    {
        $this->assertSame('missing_crawl_job', $this->eval('scanning')['state']);
    }

    public function testNeverRecoverableWhileJobExistsOrRecentOrPlanned(): void
    {
        $this->assertFalse($this->eval('scanned', [['status' => 'pending', 'attempts' => 0]])['recoverable']);
        $this->assertFalse($this->eval('scanned', [['status' => 'processing', 'attempts' => 1]])['recoverable']);
        $this->assertFalse($this->eval('scanned', [], true, 0, 60)['recoverable']);
        $this->assertFalse($this->eval('scanned', [], true, 3)['recoverable']);
        $this->assertFalse($this->eval('planning')['recoverable']);
        $this->assertFalse($this->eval('scanned', [], false)['recoverable']);
    }
}
