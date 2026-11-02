<?php

use PHPUnit\Framework\TestCase;

/** Backlink outreach inputs: only real domains, real emails and http(s) links are stored; every outgoing email carries who sends it and how to opt out. */
final class OutreachInputGuardsTest extends TestCase
{
    public function testDomainsAreNormalisedAndGarbageRejected(): void
    {
        $this->assertSame('example.com', OutreachController::normalizeDomain('  HTTPS://www.Example.com/path?x=1#y '));
        $this->assertSame('blog.example.co.uk', OutreachController::normalizeDomain('blog.example.co.uk'));
        $this->assertSame('example.com', OutreachController::normalizeDomain('example.com.'));
        foreach (['', 'not a domain', 'javascript:alert(1)', 'localhost', '-bad.example.com', 'exa mple.com', 'example', '<script>.com', str_repeat('a', 70) . '.com', '1.2.3.4'] as $bad) {
            $this->assertNull(OutreachController::normalizeDomain($bad), "should reject: $bad");
        }
    }

    public function testOnlyPlainHttpLinksAreAccepted(): void
    {
        $this->assertTrue(OutreachController::isHttpUrl('https://blog.example.com/best-egypt-tours?ref=1'));
        $this->assertTrue(OutreachController::isHttpUrl('http://example.com/a'));
        foreach (['', 'ftp://example.com', 'javascript:alert(1)', 'example.com/page', 'https://exa mple.com', "https://example.com/a\"onmouseover=\"x", str_repeat('https://a.co/', 60)] as $bad) {
            $this->assertFalse(OutreachController::isHttpUrl($bad), "should reject: $bad");
        }
    }

    public function testFooterNamesTheSenderAndTheOptOutInTheEmailLanguage(): void
    {
        $en = OutreachController::emailFooter('Tourfecto Travel', 'https://tourfecto.com', false);
        $this->assertStringContainsString('Tourfecto Travel', $en);
        $this->assertStringContainsString('https://tourfecto.com', $en);
        $this->assertStringContainsString('unsubscribe', $en);
        $ar = OutreachController::emailFooter('', 'https://tourfecto.com', true);
        $this->assertStringContainsString('https://tourfecto.com', $ar);
        $this->assertStringContainsString('إلغاء', $ar);
        $this->assertStringContainsString('--', $ar);
    }
}
