<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/Services/Indexing/IndexNowService.php';

/** IndexNow: key file and page checks run on a fake reader, and no real HTTP call is made. */
class IndexNowServiceTest extends TestCase
{
    private const KEY = 'abcdef1234567890';

    private function reader(array $pages): object
    {
        return new class($pages) {
            public function __construct(private array $pages) {}
            public function fetch(string $url): array
            {
                return $this->pages[$url] ?? ['success' => false];
            }
        };
    }

    private function page(string $url, string $html = '<html><head></head><body>ok</body></html>', int $status = 200): array
    {
        return ['success' => true, 'http_status' => $status, 'url' => $url, 'html' => $html];
    }

    private function keyPage(): array
    {
        return $this->page('https://example.com/' . self::KEY . '.txt', self::KEY);
    }

    private function service(array $pages, array &$sent): IndexNowService
    {
        $transport = function (array $req) use (&$sent) {
            $sent[] = $req;
            return ['status' => 200];
        };
        return new IndexNowService($this->reader($pages), $transport);
    }

    public function testKeyResponseOnlyForMatchingPath(): void
    {
        $this->assertSame(self::KEY, IndexNowService::keyResponse('/' . self::KEY . '.txt', self::KEY));
        $this->assertNull(IndexNowService::keyResponse('/other.txt', self::KEY));
        $this->assertNull(IndexNowService::keyResponse('/short.txt', 'short'));
        $this->assertNull(IndexNowService::keyResponse('/../x.txt', '../x'));
    }

    public function testGenerateKeyIsValidAndUnique(): void
    {
        $s = new IndexNowService($this->reader([]));
        $a = $s->generateKey();
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $a);
        $this->assertNotSame($a, $s->generateKey());
    }

    public function testSubmitsOnlyVerifiedLiveUrls(): void
    {
        $sent = [];
        $u1 = 'https://example.com/a';
        $u2 = 'https://example.com/noindex';
        $pages = [
            'https://example.com/' . self::KEY . '.txt' => $this->keyPage(),
            $u1 => $this->page($u1),
            $u2 => $this->page($u2, '<html><head><meta name="robots" content="noindex"></head><body>x</body></html>'),
        ];
        $r = $this->service($pages, $sent)->submitUrls('example.com', self::KEY, [$u1, $u2]);
        $this->assertTrue($r['success']);
        $this->assertSame(1, $r['submitted']);
        $this->assertSame([$u2], $r['skipped']);
        $payload = json_decode($sent[0]['body'], true);
        $this->assertSame([$u1], $payload['urlList']);
    }

    public function testCanonicalPointingElsewhereIsSkipped(): void
    {
        $sent = [];
        $u1 = 'https://example.com/a';
        $u2 = 'https://example.com/b';
        $pages = [
            'https://example.com/' . self::KEY . '.txt' => $this->keyPage(),
            $u1 => $this->page($u1),
            $u2 => $this->page($u2, '<html><head><link rel="canonical" href="https://example.com/a"></head><body>x</body></html>'),
        ];
        $r = $this->service($pages, $sent)->submitUrls('example.com', self::KEY, [$u1, $u2]);
        $this->assertSame(1, $r['submitted']);
        $this->assertSame([$u2], $r['skipped']);
    }

    public function testRejectsUrlsOnAnotherHost(): void
    {
        $sent = [];
        $r = $this->service([], $sent)->submitUrls('example.com', self::KEY, ['https://evil.com/a']);
        $this->assertFalse($r['success']);
        $this->assertSame([], $sent);
    }

    public function testRejectsHttpPortAndCredentials(): void
    {
        foreach (['http://example.com/a', 'https://example.com:8443/a', 'https://u:p@example.com/a'] as $bad) {
            $sent = [];
            $r = $this->service([], $sent)->submitUrls('example.com', self::KEY, [$bad]);
            $this->assertFalse($r['success'], $bad);
            $this->assertSame([], $sent);
        }
    }

    public function testRejectsEmptyAndOversizedLists(): void
    {
        $sent = [];
        $s = $this->service([], $sent);
        $this->assertFalse($s->submitUrls('example.com', self::KEY, [])['success']);
        $many = array_map(fn($i) => "https://example.com/p$i", range(1, 101));
        $this->assertFalse($s->submitUrls('example.com', self::KEY, $many)['success']);
    }

    public function testFailsWhenKeyFileNotLive(): void
    {
        $sent = [];
        $u1 = 'https://example.com/a';
        $r = $this->service([$u1 => $this->page($u1)], $sent)->submitUrls('example.com', self::KEY, [$u1]);
        $this->assertFalse($r['success']);
        $this->assertStringContainsString('key file', $r['error']);
        $this->assertSame([], $sent);
    }

    public function testFailsWhenKeyFileContentDiffers(): void
    {
        $sent = [];
        $u1 = 'https://example.com/a';
        $kpUrl = 'https://example.com/' . self::KEY . '.txt';
        $r = $this->service([$kpUrl => $this->page($kpUrl, 'wrong-key-content'), $u1 => $this->page($u1)], $sent)
            ->submitUrls('example.com', self::KEY, [$u1]);
        $this->assertFalse($r['success']);
    }

    public function testNon200EngineResponseIsFailure(): void
    {
        $u1 = 'https://example.com/a';
        $pages = ['https://example.com/' . self::KEY . '.txt' => $this->keyPage(), $u1 => $this->page($u1)];
        $s = new IndexNowService($this->reader($pages), fn() => ['status' => 429]);
        $r = $s->submitUrls('example.com', self::KEY, [$u1]);
        $this->assertFalse($r['success']);
        $this->assertSame(0, $r['submitted']);
    }

    public function testSubmitToAllEnginesPostsOncePerEngine(): void
    {
        $sent = [];
        $u1 = 'https://example.com/a';
        $pages = ['https://example.com/' . self::KEY . '.txt' => $this->keyPage(), $u1 => $this->page($u1)];
        $r = $this->service($pages, $sent)->submitToAllEngines('example.com', self::KEY, [$u1]);
        $this->assertCount(4, $r);
        $this->assertCount(4, $sent);
    }
}
