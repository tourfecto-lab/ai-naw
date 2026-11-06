<?php

use PHPUnit\Framework\TestCase;

/**
 * مكتب التواصل: بحث موحد برقم التليفون بأي شكل (من غير الدولة)، وسجل كامل للشركة بالأحدث أولًا.
 */
final class OutreachDeskHistoryAndSearchTest extends TestCase
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
        $ids = array_map('intval', array_column($this->db->query("SELECT id FROM platform_leads WHERE source = 'phpunit-hist'") ?: [], 'id'));
        if ($ids) {
            $in = implode(',', $ids);
            foreach (['platform_lead_messages', 'platform_desk_actions', 'platform_assistant_chat'] as $t) $this->db->query("DELETE FROM {$t} WHERE lead_id IN ({$in})");
        }
        $this->db->query("DELETE FROM platform_leads WHERE source = 'phpunit-hist'");
    }

    private function lead(string $name, string $country, string $phone): int
    {
        $token = bin2hex(random_bytes(16));
        $this->db->query("INSERT INTO platform_leads (source, company, email, domain, phone, country, status, step, token) VALUES ('phpunit-hist', ?, ?, ?, ?, ?, 'new', 0, ?)", [$name, substr($token, 0, 8) . '@hist.test', substr($token, 0, 8) . '.test', $phone, $country, $token]);
        return (int) ($this->db->query("SELECT MAX(id) AS id FROM platform_leads WHERE source = 'phpunit-hist'")[0]['id'] ?? 0);
    }

    public function testSearchFindsPhoneInAnyFormatAcrossCountries(): void
    {
        $eg = $this->lead('Hist Egypt Tours', 'EG', '+20 10 98765432');
        $tr = $this->lead('Hist Turkey Tours', 'TR', '+90 555 123 4567');
        $staff = ['name' => 'T', 'user_id' => 1, 'all' => true];
        $find = function (string $q) use ($staff): array {
            $r = $this->svc->deskLeads(['country' => '', 'view' => 'all', 'q' => $q, 'page' => 1], $staff);
            return array_column($r['rows'], 'id');
        };
        $this->assertContains($eg, $find('01098765432'), 'رقم محلي بالصفر');
        $this->assertContains($eg, $find('+20 10 9876'), 'رقم دولي بمسافات');
        $this->assertContains($eg, $find('10-98765'), 'بشرطة');
        $this->assertContains($tr, $find('555 123'), 'دولة تانية في نفس البحث');
        $this->assertContains($eg, $find('Hist Egypt'), 'الاسم لسه شغال');
        $this->assertNotContains($tr, $find('01098765432'));
    }

    public function testTimelineMergesAllEventsNewestFirst(): void
    {
        $id = $this->lead('Hist Timeline Co', 'EG', '+20 10 11112222');
        $this->db->query("INSERT INTO platform_lead_messages (lead_id, step, subject, status, sent_at, clicked_at) VALUES (?, 1, 'First email', 'sent', '2026-11-01 10:00:00', '2026-11-01 11:00:00')", [$id]);
        $this->db->query("INSERT INTO platform_desk_actions (lead_id, user_id, user_name, action, note, created_at) VALUES (?, 7, 'Saloma', 'contacted', 'بعتّ الرسالة', '2026-11-02 09:00:00'), (?, 7, 'Saloma', 'replied', 'قالوا بعد يومين', '2026-11-03 09:00:00')", [$id, $id]);
        $this->db->query("UPDATE platform_leads SET report_viewed_at = '2026-11-02 12:00:00', report_last_at = '2026-11-04 08:00:00', report_views = 3 WHERE id = ?", [$id]);
        $this->db->query("INSERT INTO platform_assistant_chat (lead_id, user_id, role, text, created_at) VALUES (?, 7, 'assistant', 'اقتراح رد', '2026-11-03 09:30:00')", [$id]);

        $ev = $this->svc->leadTimeline($id);
        $texts = array_column($ev, 'text');
        $this->assertSame('فتحت التقرير تاني (إجمالي 3 مرات)', $ev[0]['text'], 'الأحدث أولًا');
        $this->assertContains('ردّت: قالوا بعد يومين', $texts);
        $this->assertContains('اتبعتلها واتساب: بعتّ الرسالة', $texts);
        $this->assertContains('فتحت التقرير لأول مرة', $texts);
        $this->assertContains('ضغطت على اللينك في إيميل رقم 1', $texts);
        $this->assertContains('إيميل رقم 1 اتبعت: First email', $texts);
        $this->assertContains('المساعد: اقتراح رد', $texts);
        $this->assertSame('Saloma', array_values(array_filter($ev, static fn ($e) => str_starts_with($e['text'], 'ردّت')))[0]['by']);
        $times = array_column($ev, 'at');
        $sorted = $times;
        rsort($sorted);
        $this->assertSame($sorted, $times);
        $this->assertSame([], $this->svc->leadTimeline(999999999));
    }
}
