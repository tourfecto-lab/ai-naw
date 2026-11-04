<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/Services/SeoExpert/SeoLinkHealth.php';

/** A failed read is not proof that a link is broken: only 404/410 count, and only after a second read. */
final class SeoLinkHealthTest extends TestCase
{
    public function testOnlyNotFoundAndGoneAreBroken(): void
    {
        foreach ([404, 410] as $code) {
            $this->assertSame('gone', SeoLinkHealth::classify(['ok' => false, 'http_status' => $code])['state']);
        }
    }

    public function testTransientFailuresNeverCountAsBroken(): void
    {
        $this->assertSame('rate_limited', SeoLinkHealth::classify(['ok' => false, 'http_status' => 429, 'retry_after' => '120'])['reason']);
        $this->assertSame(120, SeoLinkHealth::classify(['ok' => false, 'http_status' => 429, 'retry_after' => '120'])['retry_after']);
        $this->assertSame('blocked_forbidden', SeoLinkHealth::classify(['ok' => false, 'http_status' => 403])['reason']);
        $this->assertSame('server_error', SeoLinkHealth::classify(['ok' => false, 'http_status' => 502])['reason']);
        $this->assertSame('timeout', SeoLinkHealth::classify(['ok' => false, 'http_status' => 0, 'error_detail' => 'Operation timed out after 10001 ms'])['reason']);
        $this->assertSame('dns_failure', SeoLinkHealth::classify(['ok' => false, 'http_status' => 0, 'error_detail' => 'Could not resolve host: x.test'])['reason']);
        $this->assertSame('maintenance_page', SeoLinkHealth::classify(['ok' => true, 'coming_soon' => true, 'http_status' => 200])['reason']);
        foreach ([429, 403, 502, 503, 0] as $code) {
            $this->assertNotSame('gone', SeoLinkHealth::classify(['ok' => false, 'http_status' => $code])['state']);
        }
    }

    public function testConfirmGoneNeedsTwoConsecutiveReads(): void
    {
        $reader = new class([['ok' => false, 'http_status' => 404], ['ok' => true, 'http_status' => 200]]) {
            public function __construct(private array $seq) {}
            public function fresh(string $u): array { return array_shift($this->seq); }
        };
        $this->assertSame('ok', SeoLinkHealth::confirmGone($reader, 'https://x.test/a')['state']);

        $reader2 = new class([['ok' => false, 'http_status' => 404], ['ok' => false, 'http_status' => 410]]) {
            public function __construct(private array $seq) {}
            public function fresh(string $u): array { return array_shift($this->seq); }
        };
        $this->assertSame('gone', SeoLinkHealth::confirmGone($reader2, 'https://x.test/a')['state']);
    }

    public function testMetadataTaskStoppedByConnectorOrCacheCanBeRetriedWithoutANewCycle(): void
    {
        require_once __DIR__ . '/../../app/Services/SeoExpert/SeoTaskFollowUp.php';
        $stopped = ['kind' => 'metadata', 'status' => 'needs_client', 'evidence_json' => json_encode(['reason' => 'The connector is installed, but your site cache still serves an older copy of this page.'])];
        $this->assertTrue(SeoTaskFollowUp::retryableMetadata($stopped));
        $this->assertFalse(SeoTaskFollowUp::retryableMetadata(['kind' => 'metadata', 'status' => 'needs_client', 'evidence_json' => json_encode(['reason' => 'The page changed after planning.'])]));
        $this->assertFalse(SeoTaskFollowUp::retryableMetadata(['kind' => 'content', 'status' => 'needs_client', 'evidence_json' => json_encode(['reason' => 'connector'])]));
    }
}
