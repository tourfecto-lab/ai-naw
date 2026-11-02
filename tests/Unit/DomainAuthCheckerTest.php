<?php

use PHPUnit\Framework\TestCase;

/** SPF / DKIM / DMARC checker behind the "Check domain" button in the email-marketing settings page (DNS is injected, no network). */
final class DomainAuthCheckerTest extends TestCase
{
    private function checker(array $dns): DomainAuthChecker
    {
        return new DomainAuthChecker(static fn (string $name): array => $dns[$name] ?? []);
    }

    public function testFullyAuthenticatedDomainPasses(): void
    {
        $r = $this->checker([
            'example.com' => ['v=spf1 include:_spf.mailer.test ~all'],
            'default._domainkey.example.com' => ['v=DKIM1; k=rsa; p=MIGfMA0GCSqGSIb3DQEBAQUAA4GNADCBiQ'],
            '_dmarc.example.com' => ['v=DMARC1; p=reject; rua=mailto:d@example.com'],
        ])->check('example.com');
        $this->assertSame('pass', $r['spf']['status']);
        $this->assertSame('pass', $r['dkim']['status']);
        $this->assertSame('pass', $r['dmarc']['status']);
        $this->assertSame(100, $r['score']);
        $this->assertSame([], $r['recommendations']);
    }

    public function testMissingRecordsFailWithFixes(): void
    {
        $r = $this->checker([])->check('example.com');
        $this->assertSame(['fail', 'fail', 'fail'], [$r['spf']['status'], $r['dkim']['status'], $r['dmarc']['status']]);
        $this->assertSame(0, $r['score']);
        $this->assertGreaterThanOrEqual(3, count($r['recommendations']));
    }

    public function testPermissiveSpfAndDuplicateSpfFail(): void
    {
        $open = $this->checker(['example.com' => ['v=spf1 +all']])->checkSpf('example.com');
        $this->assertSame('fail', $open['status']);
        $twice = $this->checker(['example.com' => ['v=spf1 ~all', 'v=spf1 -all']])->checkSpf('example.com');
        $this->assertSame('fail', $twice['status']);
    }

    public function testCustomDkimSelectorIsUsed(): void
    {
        $r = $this->checker(['s9._domainkey.example.com' => ['v=DKIM1; p=ABCDEF']])->check('example.com', ['s9']);
        $this->assertSame('pass', $r['dkim']['status']);
        $this->assertSame('fail', $this->checker(['s9._domainkey.example.com' => ['v=DKIM1; p=ABCDEF']])->check('example.com', ['other'])['dkim']['status']);
    }

    public function testHostingerDkimSelectorIsFoundByDefault(): void
    {
        $r = $this->checker(['hostingermail-a._domainkey.example.com' => ['v=DKIM1; k=rsa; p=MIGfMA0GCSqGSIb3DQEBAQUAA4GNADCBiQ']])->check('example.com');
        $this->assertSame('pass', $r['dkim']['status']);
        $this->assertSame(['hostingermail-a'], $r['dkim']['selectors']);
    }

    public function testInvalidDomainsAreRejected(): void
    {
        $this->assertTrue(DomainAuthChecker::isValidDomain('mail.example.com'));
        foreach (['', 'localhost', 'a b.com', 'http://example.com', '-bad.com', 'example'] as $bad) {
            $this->assertFalse(DomainAuthChecker::isValidDomain($bad), $bad);
        }
        $this->assertArrayHasKey('error', $this->checker([])->check('not a domain'));
    }
}
