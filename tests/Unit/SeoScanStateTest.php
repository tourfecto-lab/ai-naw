<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/Services/SeoExpert/SeoScanState.php';

final class SeoScanStateTest extends TestCase
{
    private function s(string $status, array $snap, int $tasks = 0, array $health = []): string
    {
        return SeoScanState::classify(['status' => $status], $snap, $tasks, $health)['state'];
    }

    public function testStatesAreDistinguished(): void
    {
        $this->assertSame('waiting_worker', $this->s('queued', [], 0, ['state' => 'waiting_worker']));
        $this->assertSame('running', $this->s('scanning', ['coverage' => ['audited' => 3]], 0, ['state' => 'processing']));
        $this->assertSame('partial', $this->s('completed', ['coverage' => ['audited' => 10], 'partial' => true], 2));
        $this->assertSame('no_pages_readable', $this->s('completed', ['coverage' => ['audited' => 0, 'failed' => 4]]));
        $this->assertSame('no_pages_readable', $this->s('failed', []));
        $this->assertSame('completed_no_recommendations', $this->s('completed', ['coverage' => ['audited' => 12]], 0));
        $this->assertSame('completed', $this->s('completed', ['coverage' => ['audited' => 12]], 5));
    }

    public function testCountsComeFromCoverage(): void
    {
        $r = SeoScanState::classify(['status' => 'completed'], ['coverage' => ['audited' => 7, 'failed' => 2, 'queued' => 1], 'excluded_routes' => ['a' => 1, 'b' => 2]], 1);
        $this->assertSame([7, 2, 2, 1], [$r['pages_audited'], $r['pages_failed'], $r['pages_excluded'], $r['pages_queued']]);
    }
}
