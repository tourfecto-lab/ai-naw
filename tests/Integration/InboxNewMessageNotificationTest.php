<?php

use PHPUnit\Framework\TestCase;

/** A customer's first unread message sends the agent a bell + device push; the rest of a burst stays quiet. */
final class InboxNewMessageNotificationTest extends TestCase
{
    private static int $wid = 0;
    private static int $uid = 0;
    private int $convId = 0;
    /** @var int[] */
    private array $msgIds = [];

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
        foreach ($this->msgIds as $id) {
            $db->query('DELETE FROM chat_messages WHERE id = ?', [$id]);
        }
        if ($this->convId) {
            $db->query('DELETE FROM ai_conversations WHERE id = ?', [$this->convId]);
        }
        $db->query("DELETE FROM notifications WHERE user_id = ? AND type = 'ai_chat_new_message'", [self::$uid]);
    }

    private function incoming(): int
    {
        $id = (int) Database::getInstance()->query(
            "INSERT INTO chat_messages (website_id, user_id, session_id, platform, customer_name, message_direction, message_text, bot_status, is_auto_pilot, created_at)
             VALUES (?, ?, 'sess_notif', 'whatsapp', 'N', 'incoming', 'hello', 'pending_approval', 0, NOW())",
            [self::$wid, self::$uid]
        );
        $this->msgIds[] = $id;
        return $id;
    }

    private function pushes(): int
    {
        return (int) Database::getInstance()->query("SELECT COUNT(*) c FROM notifications WHERE user_id = ? AND type = 'ai_chat_new_message'", [self::$uid])[0]['c'];
    }

    public function testOnlyTheFirstUnreadMessageNotifies(): void
    {
        $svc = new UnifiedInboxService();
        $conv = $svc->findOrCreateConversation(self::$wid, self::$uid, 'whatsapp', 'notif-' . bin2hex(random_bytes(4)), ['name' => 'Notif Test', 'phone' => '201000000077', 'email' => null]);
        $this->convId = (int) $conv->getAttribute('id');
        Database::getInstance()->query("DELETE FROM notifications WHERE user_id = ? AND type = 'ai_chat_new_message'", [self::$uid]);

        $svc->linkMessage($this->incoming(), $this->convId, true);
        $this->assertSame(1, $this->pushes(), 'the first unread message notifies');

        $svc->linkMessage($this->incoming(), $this->convId, true);
        $svc->linkMessage($this->incoming(), $this->convId, true);
        $this->assertSame(1, $this->pushes(), 'the rest of the burst is quiet');

        Database::getInstance()->query('UPDATE ai_conversations SET unread_count = 0 WHERE id = ?', [$this->convId]);
        $svc->linkMessage($this->incoming(), $this->convId, true);
        $this->assertSame(2, $this->pushes(), 'after the agent reads it, the next message notifies again');
    }

    public function testOurOwnMessagesNeverNotify(): void
    {
        $svc = new UnifiedInboxService();
        $conv = $svc->findOrCreateConversation(self::$wid, self::$uid, 'whatsapp', 'notif-' . bin2hex(random_bytes(4)), ['name' => 'Own Test', 'phone' => '201000000078', 'email' => null]);
        $this->convId = (int) $conv->getAttribute('id');
        Database::getInstance()->query("DELETE FROM notifications WHERE user_id = ? AND type = 'ai_chat_new_message'", [self::$uid]);

        $svc->linkMessage($this->incoming(), $this->convId, false);
        $this->assertSame(0, $this->pushes());
    }
}
