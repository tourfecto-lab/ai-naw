<?php

use PHPUnit\Framework\TestCase;

/**
 * مقارنة الفئات: التصنيف من بطاقة الفحص، وحساب (اتكلمنا / فتحوا / ردّوا / سجّلوا) لكل فئة، وعلامة العينة الصغيرة.
 */
final class OutreachSegmentReportTest extends TestCase
{
    private ?Database $db = null;
    private PlatformOutreachService $svc;

    protected function setUp(): void
    {
        try {
            $app = dirname(__DIR__, 2) . '/app';
            if (!defined('APP_ENV')) foreach (['app.php', 'database.php', 'encryption.php'] as $cfg) if (file_exists($app . '/Config/' . $cfg)) require_once $app . '/Config/' . $cfg;
            if (!class_exists('Database')) require_once $app . '/Core/Database.php';
            $this->db = Database::getInstance();
            $this->svc = new PlatformOutreachService();
        } catch (Throwable $e) {
            $this->markTestSkipped('DB غير متاحة: ' . $e->getMessage());
        }
        $this->clean();
    }

    protected function tearDown(): void
    {
        if ($this->db) $this->clean();
    }

    private function clean(): void
    {
        $this->db->query("DELETE FROM platform_leads WHERE source = 'phpunit-seg'");
    }

    private function lead(string $name, array $v): void
    {
        $v += ['website' => 'https://x.test', 'reviews' => 200, 'rating' => 4.6, 'maps' => 'https://www.google.com/maps/place/x', 'site_ok' => true, 'step' => 0, 'action' => null, 'viewed' => null, 'status' => 'new', 'phone' => '+20 10 5555 5555'];
        $data = ['site' => ['ok' => $v['site_ok'], 'social' => [], 'social_v' => 2]];
        $token = bin2hex(random_bytes(16));
        $this->db->query("INSERT INTO platform_leads (source, company, email, domain, website, phone, country, status, step, token, rating, reviews, maps_url, data_json, desk_action, report_viewed_at)
            VALUES ('phpunit-seg', ?, ?, ?, ?, ?, 'EG', ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [$name, substr($token, 0, 8) . '@seg.test', substr($token, 0, 8) . '.test', $v['website'], $v['phone'], $v['status'], $v['step'], $token, $v['rating'], $v['reviews'], $v['maps'], json_encode($data), $v['action'], $v['viewed']]);
    }

    public function testBucketsAndKeys(): void
    {
        $this->assertSame('unknown', PlatformOutreachService::reviewBucket(null));
        $this->assertSame('<100', PlatformOutreachService::reviewBucket(99));
        $this->assertSame('100-499', PlatformOutreachService::reviewBucket(100));
        $this->assertSame('100-499', PlatformOutreachService::reviewBucket(499));
        $this->assertSame('500+', PlatformOutreachService::reviewBucket(500));

        $a = PlatformOutreachService::segmentKeys(['google' => ['status' => 'found', 'reviews' => 238], 'tripadvisor' => ['status' => 'missing'], 'website' => ['status' => 'ok']]);
        $this->assertContains('test:A', $a);
        $this->assertContains('size:100-499', $a);
        $this->assertNotContains('test:B', $a);
        $b = PlatformOutreachService::segmentKeys(['google' => ['status' => 'missing'], 'tripadvisor' => ['status' => 'linked'], 'website' => ['status' => 'down']]);
        $this->assertContains('test:B', $b);
        $this->assertContains('size:unknown', $b, 'جوجل مش موجود = مفيش بيانات حجم');
        $c = PlatformOutreachService::segmentKeys(['google' => ['status' => 'found', 'reviews' => 700], 'tripadvisor' => ['status' => 'found'], 'website' => ['status' => 'none']]);
        $this->assertContains('test:C', $c);
        $this->assertContains('size:500+', $c);
        $this->assertNotContains('test:A', $c);
    }

    public function testReportCountsContactedOpenedRepliedSignedAndWaiting(): void
    {
        $before = $this->index($this->svc->segmentReport());
        // فئة A: جوجل (maps) + موقع شغّال + 238 تقييم
        $this->lead('Seg A contacted opened', ['reviews' => 238, 'step' => 1, 'viewed' => '2026-11-05 10:00:00']);
        $this->lead('Seg A replied', ['reviews' => 238, 'action' => 'replied', 'viewed' => '2026-11-05 10:00:00']);
        $this->lead('Seg A signed', ['reviews' => 238, 'status' => 'signed_up', 'step' => 1, 'viewed' => '2026-11-05 10:00:00']);
        $this->lead('Seg A waiting', ['reviews' => 238]);
        $this->lead('Seg A no phone', ['reviews' => 238, 'phone' => '']);
        // مش فئة A: تقييماتها 700
        $this->lead('Seg big', ['reviews' => 700, 'step' => 1]);

        $after = $this->index($this->svc->segmentReport());
        $d = static fn (string $k, string $f): int => $after[$k][$f] - $before[$k][$f];
        $this->assertSame(5, $d('test:A', 'leads'));
        $this->assertSame(3, $d('test:A', 'contacted'));
        $this->assertSame(3, $d('test:A', 'opened'));
        $this->assertSame(2, $d('test:A', 'replied'), 'اللي ردّت + اللي سجّلت');
        $this->assertSame(1, $d('test:A', 'signed'));
        $this->assertSame(1, $d('test:A', 'waiting'), 'ليها رقم ولسه ماتكلمناش (من غير رقم مش بتتحسب)');
        $this->assertSame(1, $d('size:500+', 'contacted'));
        $this->assertSame(0, $d('test:A', 'leads') - 5);
        $this->assertTrue($after['test:A']['small_sample'], 'أقل من 30 = عينة صغيرة');
    }

    /** @return array<string,array<string,mixed>> */
    private function index(array $r): array
    {
        $out = [];
        foreach (array_merge($r['tests'], $r['groups']) as $g) {
            $out[$g['key']] = $g;
        }
        return $out;
    }
}
