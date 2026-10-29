<?php

/**
 * العقل جوه الخدمات (B2+): مسودات ردود التقييمات السلبية
 *   1) المعاينة بتقول هيتكتب كام رد وبيكلّف لحد كام طلب ذكاء اصطناعي، ومابتغيّرش حاجة.
 *   2) التنفيذ بيحط المسودات في طابور الموافقة (reply_status = pending) ومابينشرش حاجة.
 *   3) الرد الجاهز من قالب (الذكاء الاصطناعي مش متاح) مابيتحفظش كمسودة.
 *   4) تقييمات شركة تانية، والتقييمات اللي اتردّ عليها، والإيجابية، مابتتلمسش.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/Services/Brain/BrainActions.php';

final class BrainActionsIntegrationTest extends TestCase
{
    private const USER_ID = 999840;
    private const OTHER_USER_ID = 999841;

    private ?Database $db = null;
    private array $sites = [];

    protected function setUp(): void
    {
        try {
            $app = dirname(__DIR__, 2) . '/app';
            if (!defined('APP_ENV')) foreach (['app.php', 'database.php', 'encryption.php'] as $cfg) if (file_exists($app . '/Config/' . $cfg)) require_once $app . '/Config/' . $cfg;
            if (!class_exists('Database')) require_once $app . '/Core/Database.php';
            $this->db = Database::getInstance();
            foreach ([self::USER_ID, self::OTHER_USER_ID] as $uid) {
                $this->db->query("INSERT INTO users (id, email, password, company_name, created_at) VALUES (?, ?, 'x', 'Brain Actions Test', NOW()) ON DUPLICATE KEY UPDATE email = email", [$uid, 'brain-act-' . $uid . '@tourfecto.test']);
            }
        } catch (Throwable $e) {
            $this->markTestSkipped('DB غير متاحة: ' . $e->getMessage());
        }
        $this->clean();
        foreach ([self::USER_ID, self::OTHER_USER_ID] as $uid) {
            $this->db->query("INSERT INTO websites (user_id, main_url, company_name) VALUES (?, 'https://brain-actions.test', 'Brain Actions Test')", [$uid]);
            $this->sites[$uid] = (int) ($this->db->query('SELECT MAX(id) AS id FROM websites WHERE user_id = ?', [$uid])[0]['id'] ?? 0);
        }
    }

    protected function tearDown(): void
    {
        if ($this->db) $this->clean();
    }

    private function clean(): void
    {
        $this->db->query('DELETE FROM reviews WHERE user_id IN (?, ?)', [self::USER_ID, self::OTHER_USER_ID]);
        $this->db->query('DELETE FROM websites WHERE user_id IN (?, ?)', [self::USER_ID, self::OTHER_USER_ID]);
    }

    private function review(int $uid, string $text, float $rating, bool $replied = false): int
    {
        $this->db->query("INSERT INTO reviews (website_id, user_id, source_platform, platform, external_review_id, reviewer_name, review_text, rating, review_date, reply_sent_at)
            VALUES (?, ?, 'google_business', 'google_business', ?, 'Anna', ?, ?, NOW(), " . ($replied ? 'NOW()' : 'NULL') . ')', [$this->sites[$uid], $uid, uniqid('ext', true), $text, $rating]);
        return (int) ($this->db->query('SELECT MAX(id) AS id FROM reviews WHERE user_id = ?', [$uid])[0]['id'] ?? 0);
    }

    private function row(int $id): array
    {
        return $this->db->query('SELECT ai_generated_reply, reply_status, reply_sent_at FROM reviews WHERE id = ?', [$id])[0] ?? [];
    }

    public function testOnlyTheReviewDecisionHasAnInServiceAction(): void
    {
        $this->assertSame('review_drafts', BrainActions::forDecision('reply_negative_reviews'));
        $this->assertNull(BrainActions::forDecision('confirm_profile'));
    }

    public function testPreviewThenDraftIntoTheApprovalQueueWithoutPublishing(): void
    {
        $bad = $this->review(self::USER_ID, 'The driver was late and rude', 1);
        $meh = $this->review(self::USER_ID, 'Average trip, lunch was cold', 3);
        $good = $this->review(self::USER_ID, 'Wonderful guide', 5);
        $answered = $this->review(self::USER_ID, 'Bad pickup', 2, true);
        $other = $this->review(self::OTHER_USER_ID, 'Terrible', 1);

        $calls = [];
        $svc = new BrainActions($this->db, function (array $r, int $uid) use (&$calls, $meh) {
            $calls[] = [(int) $r['id'], $uid];
            return (int) $r['id'] === $meh ? null : 'Sorry Anna, we fixed the pickup times.'; // null = رد قالب/الذكاء الاصطناعي مش متاح
        });

        $p = $svc->preview(self::USER_ID, 'review_drafts');
        $this->assertSame(2, $p['without_draft']);
        $this->assertSame(2, $p['will_draft']);
        $this->assertSame(2 * BrainActions::AI_CALLS_PER_DRAFT, $p['ai_calls_max']);
        $this->assertSame(false, $p['publishes']);
        $this->assertSame([], $calls, 'المعاينة مابتكتبش حاجة');

        $r = $svc->run(self::USER_ID, 'review_drafts');
        $this->assertSame(1, $r['drafted']);
        $this->assertSame(1, $r['skipped']);
        $this->assertSame(1, $r['ready_drafts']);
        $this->assertCount(2, $calls);
        foreach ($calls as [, $uid]) $this->assertSame(self::USER_ID, $uid);

        $this->assertSame('Sorry Anna, we fixed the pickup times.', $this->row($bad)['ai_generated_reply']);
        $this->assertSame('pending', $this->row($bad)['reply_status']);
        $this->assertNull($this->row($bad)['reply_sent_at'], 'مفيش نشر');
        $this->assertSame('', (string) $this->row($meh)['ai_generated_reply']);
        $this->assertSame('', (string) $this->row($good)['ai_generated_reply']);
        $this->assertSame('', (string) $this->row($answered)['ai_generated_reply']);
        $this->assertSame('', (string) $this->row($other)['ai_generated_reply'], 'شركة تانية مابتتلمسش');

        // مرة تانية: المسودة الجاهزة مابتتكتبش تاني
        $calls = [];
        $svc->run(self::USER_ID, 'review_drafts');
        $this->assertSame([[$meh, self::USER_ID]], $calls);
    }

    public function testUnknownActionIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new BrainActions($this->db))->preview(self::USER_ID, 'publish_everything');
    }
}
