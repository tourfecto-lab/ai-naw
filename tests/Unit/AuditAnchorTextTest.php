<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/Services/Seo/AuditChecksService.php';

/** The generic-anchor check used to read the link text from the opening tag only, so it never fired. */
final class AuditAnchorTextTest extends TestCase
{
    private function anchorFinding(string $html): array
    {
        $svc = new AuditChecksService();
        $svc->setDeadline(microtime(true) - 1); // skip every network request
        $r = $svc->run(['url' => 'http://t.invalid/', 'origin' => 'http://t.invalid', 'host' => 't.invalid', 'html' => $html, 'headers' => [], 'schema_types' => [], 'body_text' => 'x']);
        foreach ($r['findings'] as $f) {
            if ($f['check_key'] === 'anchor_text') {
                return $f;
            }
        }
        $this->fail('anchor_text finding missing');
    }

    public function testGenericAnchorsAreFlagged(): void
    {
        $f = $this->anchorFinding('<body><a href="/a">اضغط هنا</a> <a href="/b">Read more</a></body>');
        $this->assertSame('warn', $f['status']);
        $this->assertStringContainsString('2', $f['message']);
    }

    public function testDescriptiveAnchorsPass(): void
    {
        $f = $this->anchorFinding('<body><a href="/a">Pyramids private day tour</a></body>');
        $this->assertSame('pass', $f['status']);
    }
}
