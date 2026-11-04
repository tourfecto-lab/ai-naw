<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/Services/RuntimeHealthService.php';

/** The worker and the web app must be provably on the same code (a worker on an older copy produced old-behaviour plans). */
final class RuntimeFingerprintTest extends TestCase
{
    public function testFingerprintCoversTheKeySeoFiles(): void
    {
        $f = RuntimeHealthService::fingerprint();
        $this->assertSame(['SeoExpertService', 'SeoPagePriority', 'SeoPagePolicy', 'RuntimeHealthService'], array_keys($f['files']));
        foreach ($f['files'] as $name => $hash) {
            $this->assertMatchesRegularExpression('/^[0-9a-f]{8}$/', (string) $hash, $name . ' is present and hashed');
        }
        $this->assertNotSame('', $f['php']);
    }

    public function testFingerprintIsStableBetweenCalls(): void
    {
        $this->assertSame(RuntimeHealthService::fingerprint(), RuntimeHealthService::fingerprint());
    }
}
