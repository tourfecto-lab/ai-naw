<?php

use PHPUnit\Framework\TestCase;

/**
 * The inbox search used to look only at name / phone / email / summary. From 3 characters it now also finds a
 * conversation by the text of its messages, and a typed % or _ is literal.
 */
final class InboxMessageSearchTest extends TestCase
{
    private static int $wid = 0;
    private static int $uid = 0;
    /** @var int[] */
    private array $convIds = [];

    public static function setUpBeforeClass(): void
    {
        $row = Database::getInstance()->query('SELECT id, user_id FROM websites ORDER BY id LIMIT 1')[0] ?? null;
        if (!$row) {
            self::markTestSkipped('no website fixture');
        }
        self::$wid = (int) $row['id'];
        self::$uid = (int) $row['user_id'];
    }

    protected function tearDown(): void
    {
        $db = Database::getInstance();
        foreach ($this->convIds as $id) {
            $db->query('DELETE FROM chat_messages WHERE conversation_id = ?', [$id]);
            $db->query('DELETE FROM ai_conversations WHERE id = ?', [$id]);
        }
        $this->convIds = [];
    }

    private function conversation(string $name, string $phone, string $messageText): int
    {
        $db = Database::getInstance();
        $svc = new UnifiedInboxService();
        $conv = $svc->findOrCreateConversation(self::$wid, self::$uid, 'whatsapp', 'test-' . bin2hex(random_bytes(4)), ['name' => $name, 'phone' => $phone, 'email' => null]);
        $id = (int) $conv->getAttribute('id');
        $this->convIds[] = $id;
        $db->query(
            "INSERT INTO chat_messages (website_id, conversation_id, user_id, session_id, platform, customer_name, message_direction, message_text, bot_status, is_auto_pilot, created_at)
             VALUES (?, ?, ?, ?, 'whatsapp', ?, 'incoming', ?, 'pending_approval', 0, NOW())",
            [self::$wid, $id, self::$uid, 'sess_' . $id, $name, $messageText]
        );
        return $id;
    }

    /** @return int[] */
    private function ids(string $q): array
    {
        $found = (new UnifiedInboxService())->search(self::$wid, ['search' => $q], 100, 0);
        return array_map(static fn ($c) => (int) $c->getAttribute('id'), $found);
    }

    public function testFindsAConversationByMessageText(): void
    {
        $a = $this->conversation('Alice Tester', '201000000001', 'Do you have the sunrise balloon tour on Friday?');
        $b = $this->conversation('Bob Tester', '201000000002', 'Where is the pickup point?');

        $hits = $this->ids('sunrise balloon');
        $this->assertContains($a, $hits);
        $this->assertNotContains($b, $hits);
    }

    public function testStillFindsByNameAndShortTermsDoNotSearchMessages(): void
    {
        $a = $this->conversation('Zainab Unique', '201000000003', 'zzqq-secret-phrase');
        $this->assertContains($a, $this->ids('Zainab'));
        $this->assertNotContains($a, $this->ids('zz'), 'two characters are matched against names only');
        $this->assertContains($a, $this->ids('zzqq-secret'));
    }

    public function testWildcardsAreLiteral(): void
    {
        $a = $this->conversation('Percent Test', '201000000004', 'discount 50% today');
        $b = $this->conversation('Other Test', '201000000005', 'discount 500 today');

        $hits = $this->ids('50%');
        $this->assertContains($a, $hits);
        $this->assertNotContains($b, $hits, '% must not match "0 t"');
        $this->assertSame([], array_values(array_intersect([$a, $b], $this->ids('%%%'))), 'a bare wildcard matches nothing');
    }
}
