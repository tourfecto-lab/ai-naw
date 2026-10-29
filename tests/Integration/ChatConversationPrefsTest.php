<?php

use PHPUnit\Framework\TestCase;

/** Per-agent pin / archive / unread marks (chat_conversation_prefs, from an optional migration). */
final class ChatConversationPrefsTest extends TestCase
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
        ChatConversationPrefs::reset();
        if (!ChatConversationPrefs::ready()) {
            self::markTestSkipped('chat_conversation_prefs migration not applied in this database');
        }
    }

    protected function tearDown(): void
    {
        $db = Database::getInstance();
        foreach ($this->convIds as $id) {
            $db->query('DELETE FROM chat_conversation_prefs WHERE conversation_id = ?', [$id]);
            $db->query('DELETE FROM ai_conversations WHERE id = ?', [$id]);
        }
        $this->convIds = [];
    }

    private function conversation(): int
    {
        $c = (new UnifiedInboxService())->findOrCreateConversation(self::$wid, self::$uid, 'whatsapp', 'prefs-' . bin2hex(random_bytes(4)), ['name' => 'Prefs Test', 'phone' => '201000000099', 'email' => null]);
        $id = (int) $c->getAttribute('id');
        $this->convIds[] = $id;
        return $id;
    }

    public function testSetAndReadBack(): void
    {
        $id = $this->conversation();
        $state = ChatConversationPrefs::set(self::$uid, $id, ['pinned' => true]);
        $this->assertSame(['pinned' => true, 'archived' => false, 'unread' => false], $state);
        $this->assertSame(['pinned' => true, 'archived' => false, 'unread' => false], ChatConversationPrefs::forUser(self::$uid, self::$wid)[$id]);
    }

    public function testFlagsMergeAndTheRowIsRemovedWhenEmpty(): void
    {
        $id = $this->conversation();
        ChatConversationPrefs::set(self::$uid, $id, ['pinned' => true]);
        $state = ChatConversationPrefs::set(self::$uid, $id, ['archived' => true, 'unread' => true]);
        $this->assertSame(['pinned' => true, 'archived' => true, 'unread' => true], $state, 'a later call keeps the flags it did not mention');

        ChatConversationPrefs::set(self::$uid, $id, ['pinned' => false, 'archived' => false, 'unread' => false]);
        $this->assertArrayNotHasKey($id, ChatConversationPrefs::forUser(self::$uid, self::$wid));
        $n = (int) Database::getInstance()->query('SELECT COUNT(*) c FROM chat_conversation_prefs WHERE conversation_id = ?', [$id])[0]['c'];
        $this->assertSame(0, $n);
    }

    public function testPrefsArePersonalToTheAgent(): void
    {
        $id = $this->conversation();
        ChatConversationPrefs::set(self::$uid, $id, ['pinned' => true]);
        $this->assertArrayNotHasKey($id, ChatConversationPrefs::forUser(self::$uid + 100000, self::$wid));
    }

    public function testRoutesAndHandlersExist(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/app/routes/api.php');
        $this->assertStringContainsString("/conversation-prefs', 'ChatInboxController', 'prefs', ['AuthMiddleware']", $src);
        $this->assertStringContainsString("/conversations/{conversationId}/prefs', 'ChatInboxController', 'setPrefs', ['AuthMiddleware']", $src);
        $this->assertTrue(method_exists(ChatInboxController::class, 'prefs'));
        $this->assertTrue(method_exists(ChatInboxController::class, 'setPrefs'));
    }
}
