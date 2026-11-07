<?php

use PHPUnit\Framework\TestCase;

/**
 * رسالة واتساب مخصّصة لكل شركة بالذكاء الاصطناعي: بتتبني من حالتها الفعلية، بتتخزّن، بتظهر في قايمة المكتب،
 * وبتتنظّف (بدون إيموجي، لينك التقرير موجود)؛ ولو الذكاء الاصطناعي وقع القالب العادي يفضل.
 */
final class OutreachPersonalizedMessageTest extends TestCase
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
            $this->svc->deskLeads(['country' => 'EG', 'view' => 'all']); // بيضمن إن الأعمدة الجديدة اتعملت
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
        $this->db->query("DELETE FROM platform_leads WHERE source = 'phpunit-pz'");
    }

    private function lead(bool $audited = true): int
    {
        $token = bin2hex(random_bytes(16));
        $data = $audited ? json_encode(['audit' => ['score' => ['total' => 41], 'gaps' => [['id' => 'no_booking', 'vars' => []]]]]) : null;
        $this->db->query("INSERT INTO platform_leads (source, company, email, domain, phone, country, language, category, city, rating, reviews, status, step, token, data_json) VALUES ('phpunit-pz', 'Blue Hole Divers', ?, ?, '01099998877', 'EG', 'ar', 'Diving Center', 'Dahab', 4.9, 320, 'new', 0, ?, ?)", ['info@' . substr($token, 0, 8) . '.test', substr($token, 0, 8) . '.test', $token, $data]);
        return (int) ($this->db->query('SELECT id FROM platform_leads WHERE token = ?', [$token])[0]['id'] ?? 0);
    }

    private function goodText(): string
    {
        return str_repeat('السادة الأفاضل في *Blue Hole Divers*، أنا {SENDER} من فريق Tourfecto. تقييمكم 4.9 من 320 تقييم ممتاز، لكن لا يوجد حجز مباشر على موقعكم. ', 4) . "\nالتقرير: {REPORT_URL}";
    }

    public function testPersonalizeStoresAndFillsPlaceholders(): void
    {
        $id = $this->lead();
        $seen = '';
        $r = $this->svc->deskPersonalize($id, ['name' => 'Salma'], false, function (string $p) use (&$seen) {
            $seen = $p;
            return ['success' => true, 'data' => "```\n" . $this->goodText() . " 🌟\n```"];
        });
        $this->assertTrue($r['ok']);
        $this->assertStringContainsString('Blue Hole Divers', $seen, 'البرومبت لازم يحتوي حقايق الشركة نفسها');
        $this->assertStringContainsString('Dahab', $seen);
        $this->assertStringContainsString('Salma', $r['message']);
        $this->assertStringContainsString('/p/r/', $r['message']);
        $this->assertStringNotContainsString('{SENDER}', $r['message']);
        $this->assertStringNotContainsString('{REPORT_URL}', $r['message']);
        $this->assertStringNotContainsString('🌟', $r['message']);
        $this->assertStringStartsWith('https://wa.me/', $r['wa_url']);

        // تاني مرة من الكاش من غير ما يكلّم الذكاء الاصطناعي
        $again = $this->svc->deskPersonalize($id, ['name' => 'Salma'], false, function () { $this->fail('مفروض من الكاش'); });
        $this->assertTrue($again['cached']);
        $this->assertSame($r['message'], $again['message']);

        // وبتظهر في قايمة المكتب بدل القالب
        $rows = $this->svc->deskLeads(['country' => 'EG', 'view' => 'all', 'q' => 'Blue Hole Divers'], ['name' => 'Salma', 'user_id' => 1, 'all' => true])['rows'];
        $this->assertCount(1, $rows);
        $this->assertTrue($rows[0]['custom']);
        $this->assertSame($r['message'], $rows[0]['message']);
    }

    public function testBadAiOutputIsRejectedAndTemplateRemains(): void
    {
        $id = $this->lead();
        $short = $this->svc->deskPersonalize($id, ['name' => 'Salma'], false, fn () => ['success' => true, 'data' => 'قصيرة جدًا {SENDER}']);
        $this->assertFalse($short['ok']);
        $down = $this->svc->deskPersonalize($id, ['name' => 'Salma'], false, fn () => ['success' => false, 'error' => 'quota']);
        $this->assertFalse($down['ok']);
        $rows = $this->svc->deskLeads(['country' => 'EG', 'view' => 'all', 'q' => 'Blue Hole Divers'], ['name' => 'Salma', 'user_id' => 1, 'all' => true])['rows'];
        $this->assertFalse($rows[0]['custom']);
        $this->assertNotSame('', $rows[0]['message']);
    }

    public function testForceRegeneratesAndUnauditedIsRejected(): void
    {
        $id = $this->lead();
        $this->svc->deskPersonalize($id, ['name' => 'Salma'], false, fn () => ['success' => true, 'data' => $this->goodText()]);
        $n = 0;
        $r = $this->svc->deskPersonalize($id, ['name' => 'Salma'], true, function () use (&$n) { $n++; return ['success' => true, 'data' => $this->goodText()]; });
        $this->assertSame(1, $n);
        $this->assertFalse($r['cached']);

        $this->expectException(InvalidArgumentException::class);
        $this->svc->deskPersonalize($this->lead(false), ['name' => 'Salma'], false, fn () => ['success' => true, 'data' => $this->goodText()]);
    }

    public function testCleanCustomMessageAddsMissingReportLink(): void
    {
        $t = PlatformOutreachService::cleanCustomMessage(str_repeat('مرحبا {SENDER} ', 40), 'ar');
        $this->assertStringContainsString('{REPORT_URL}', $t);
        $this->assertSame('', PlatformOutreachService::cleanCustomMessage(str_repeat('x ', 300), 'en'), 'من غير {SENDER} مرفوض');
    }
}
