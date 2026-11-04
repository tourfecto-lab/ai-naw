<?php

/**
 * العقل الاستراتيجي: مسودات متابعة العملاء المحتملين
 *   1) بيجهّز مسودة للمتأخرين بس (ميعاد متابعة فات، أو ماحدش كلمهم من 3 أيام)، الأعلى قيمة الأول.
 *   2) نفس العميل مايتكتبلوش مسودة تانية خلال 14 يوم، ودفعة من غير عملاء ماتكتبش حاجة.
 *   3) كل شركة بتشوف وتكتب لعملائها بس.
 *   4) مفيش حاجة بتتبعت: المسودة ملاحظة بس، والقالب مافيهوش أسعار ولا مواعيد.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/Services/Brain/BrainActions.php';

final class BrainLeadFollowupsIntegrationTest extends TestCase
{
    private const USER_ID = 999860;
    private const OTHER_USER_ID = 999861;

    private ?Database $db = null;
    /** @var array<int,array{0:int,1:int,2:string}> */
    private array $notes = [];

    private function service(): BrainLeadFollowups
    {
        return new BrainLeadFollowups($this->db, function (int $uid, int $leadId, string $body) {
            $this->notes[] = [$uid, $leadId, $body];
            // نفس اللي CrmNoteService بيكتبه، من غير الموديلات
            $this->db->query("INSERT INTO crm_notes (user_id, author_user_id, related_type, related_id, body, pinned) VALUES (?, ?, 'lead', ?, ?, 1)", [$uid, $uid, $leadId, $body]);
        });
    }

    protected function setUp(): void
    {
        try {
            $app = dirname(__DIR__, 2) . '/app';
            if (!defined('APP_ENV')) foreach (['app.php', 'database.php', 'encryption.php'] as $cfg) if (file_exists($app . '/Config/' . $cfg)) require_once $app . '/Config/' . $cfg;
            if (!class_exists('Database')) require_once $app . '/Core/Database.php';
            $this->db = Database::getInstance();
            foreach ([self::USER_ID, self::OTHER_USER_ID] as $uid) {
                $this->db->query("INSERT INTO users (id, email, password, company_name, created_at) VALUES (?, ?, 'x', 'Followup Test', NOW()) ON DUPLICATE KEY UPDATE email = email", [$uid, 'brain-fu-' . $uid . '@example.test']);
            }
            $this->db->query('SELECT 1 FROM crm_leads LIMIT 1');
            $this->db->query('SELECT 1 FROM crm_notes LIMIT 1');
        } catch (Throwable $e) {
            $this->markTestSkipped('DB/CRM غير متاحة: ' . $e->getMessage());
        }
        $this->clean();
    }

    protected function tearDown(): void
    {
        if ($this->db) $this->clean();
    }

    private function clean(): void
    {
        $in = [self::USER_ID, self::OTHER_USER_ID];
        $this->db->query('DELETE FROM crm_notes WHERE user_id IN (?, ?)', $in);
        $this->db->query('DELETE FROM crm_leads WHERE contact_id IN (SELECT id FROM crm_contacts WHERE user_id IN (?, ?))', $in);
        $this->db->query('DELETE FROM crm_contacts WHERE user_id IN (?, ?)', $in);
    }

    private function lead(int $uid, string $name, string $status, ?string $followUp, string $lastEngagement, ?float $value = null, ?string $interest = null): int
    {
        $this->db->query('INSERT INTO crm_contacts (user_id, name) VALUES (?, ?)', [$uid, $name]);
        $cid = (int) $this->db->getConnection()->lastInsertId();
        $this->db->query('INSERT INTO crm_leads (contact_id, interest, value, status, next_follow_up_at, last_engagement_at) VALUES (?, ?, ?, ?, ?, ?)', [$cid, $interest, $value, $status, $followUp, $lastEngagement]);
        return (int) $this->db->getConnection()->lastInsertId();
    }

    private function ago(int $days): string
    {
        return date('Y-m-d H:i:s', time() - $days * 86400);
    }

    public function testOnlyOverdueLeadsGetDraftsHighestValueFirst(): void
    {
        $cheap = $this->lead(self::USER_ID, 'Cheap Overdue', 'new', $this->ago(2), $this->ago(9), 100);
        $rich = $this->lead(self::USER_ID, 'Rich Silent', 'qualified', null, $this->ago(6), 5000);
        $this->lead(self::USER_ID, 'Fresh', 'new', date('Y-m-d H:i:s', time() + 86400), $this->ago(1), 9999);
        $this->lead(self::USER_ID, 'Converted', 'converted', $this->ago(2), $this->ago(9), 9999);
        $this->lead(self::USER_ID, 'Recently talked', 'nurturing', null, $this->ago(1), 9999);

        $p = $this->service()->preview(self::USER_ID);
        $this->assertSame(2, $p['will_draft']);
        $this->assertSame(0, $p['ai_calls_max']);
        $this->assertFalse($p['publishes']);

        $r = $this->service()->run(self::USER_ID, 5);
        $this->assertSame(2, $r['drafted']);
        $this->assertSame([$rich, $cheap], array_column($this->notes, 1));
        $this->assertSame(0, $r['without_draft']);
        $this->assertSame(2, $r['ready_drafts']);
    }

    public function testSameLeadIsNotDraftedTwice(): void
    {
        $this->lead(self::USER_ID, 'Ahmed', 'new', $this->ago(3), $this->ago(8), 10);
        $this->assertSame(1, $this->service()->run(self::USER_ID)['drafted']);
        $this->assertSame(0, $this->service()->run(self::USER_ID)['drafted']);
        $this->assertCount(1, $this->notes);
        // بعد 14 يوم بيتكتبله مسودة جديدة
        $this->db->query('UPDATE crm_notes SET created_at = DATE_SUB(NOW(), INTERVAL 15 DAY) WHERE user_id = ?', [self::USER_ID]);
        $this->assertSame(1, $this->service()->run(self::USER_ID)['drafted']);
    }

    public function testCompaniesAreIsolated(): void
    {
        $this->lead(self::OTHER_USER_ID, 'Other Company Lead', 'new', $this->ago(3), $this->ago(8), 10);
        $this->assertSame(0, $this->service()->preview(self::USER_ID)['will_draft']);
        $this->assertSame(0, $this->service()->run(self::USER_ID)['drafted']);
        $this->assertSame([], $this->notes);
        $this->assertSame(1, $this->service()->preview(self::OTHER_USER_ID)['will_draft']);
    }

    public function testBatchIsCapped(): void
    {
        for ($i = 0; $i < 13; $i++) $this->lead(self::USER_ID, 'L' . $i, 'new', $this->ago(3), $this->ago(8), $i);
        $this->assertSame(BrainLeadFollowups::MAX_DRAFTS, $this->service()->preview(self::USER_ID)['will_draft']);
        $this->assertSame(3, $this->service()->run(self::USER_ID, 3)['drafted']);
        $this->assertCount(3, $this->notes);
    }

    public function testActionIsWiredToTheDecision(): void
    {
        $this->assertSame('lead_followups', BrainActions::forDecision('follow_up_leads'));
        $this->lead(self::USER_ID, 'Wired', 'new', $this->ago(3), $this->ago(8), 1);
        $actions = new BrainActions($this->db, null, null, function (int $uid, int $leadId, string $body) { $this->notes[] = [$uid, $leadId, $body]; });
        $this->assertSame(1, $actions->preview(self::USER_ID, 'lead_followups')['will_draft']);
        $this->assertSame(1, $actions->run(self::USER_ID, 'lead_followups')['drafted']);
        $this->expectException(InvalidArgumentException::class);
        $actions->preview(self::USER_ID, 'send_everything');
    }

    public function testTemplateIsBilingualHonestAndMakesNoPromises(): void
    {
        $ar = BrainLeadFollowups::template('أحمد', 'رحلة الأقصر');
        $this->assertStringStartsWith(BrainLeadFollowups::MARK_AR, $ar);
        $this->assertStringContainsString('أحمد', $ar);
        $this->assertStringContainsString('رحلة الأقصر', $ar);
        $en = BrainLeadFollowups::template('John', 'Luxor day trip');
        $this->assertStringStartsWith(BrainLeadFollowups::MARK_EN, $en);
        $this->assertStringContainsString('Hi John,', $en);
        $this->assertSame(1, preg_match('/^' . preg_quote(BrainLeadFollowups::MARK_EN, '/') . '\nHi,/', BrainLeadFollowups::template('', null)));
        foreach ([$ar, $en] as $text) $this->assertDoesNotMatchRegularExpression('/\d+\s*(%|\$|USD|EGP|جنيه|دولار)|discount|خصم/iu', $text);
    }
}
