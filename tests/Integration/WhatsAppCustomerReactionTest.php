<?php

use PHPUnit\Framework\TestCase;

/**
 * A customer's reaction on one of our messages arrives as a webhook message of type "reaction". It must be attached
 * to the targeted bubble (our outgoing message, or the AI reply kept on a customer's row) instead of being dropped.
 */
final class WhatsAppCustomerReactionTest extends TestCase
{
    private static int $wid = 0;
    private static int $uid = 0;
    /** @var int[] */
    private array $rows = [];

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
        foreach ($this->rows as $id) {
            Database::getInstance()->query('DELETE FROM chat_messages WHERE id = ?', [$id]);
        }
        $this->rows = [];
    }

    private function message(string $direction, ?string $wamid, array $raw = []): int
    {
        $id = (int) Database::getInstance()->query(
            "INSERT INTO chat_messages (website_id, user_id, session_id, platform, platform_message_id, customer_name, message_direction, message_text, webhook_raw_data, bot_status, is_auto_pilot, created_at)
             VALUES (?, ?, 'sess_react', 'whatsapp', ?, 'T', ?, 'hello', ?, 'sent', 0, NOW())",
            [self::$wid, self::$uid, $wamid, $direction, $raw ? json_encode($raw) : null]
        );
        $this->rows[] = $id;
        return $id;
    }

    private function apply(array $reaction): bool
    {
        $m = new ReflectionMethod(WhatsAppCloudController::class, 'applyCustomerReaction');
        $m->setAccessible(true);
        return $m->invoke(null, self::$wid, $reaction);
    }

    private function raw(int $id): array
    {
        $r = Database::getInstance()->query('SELECT webhook_raw_data FROM chat_messages WHERE id = ?', [$id])[0]['webhook_raw_data'] ?? null;
        return $r ? (json_decode($r, true) ?: []) : [];
    }

    public function testReactionIsStoredOnOurOutgoingMessage(): void
    {
        $w = 'wamid.OUT' . bin2hex(random_bytes(6));
        $id = $this->message('outgoing', $w, ['quote' => ['id' => 1, 'text' => 'x', 'who' => 'customer']]);

        $this->assertTrue($this->apply(['message_id' => $w, 'emoji' => '❤️']));
        $raw = $this->raw($id);
        $this->assertSame('❤️', $raw['customer_reaction']);
        $this->assertSame('x', $raw['quote']['text'], 'existing raw data is kept');
    }

    public function testEmptyEmojiRemovesTheReaction(): void
    {
        $w = 'wamid.OUT' . bin2hex(random_bytes(6));
        $id = $this->message('outgoing', $w, ['customer_reaction' => '👍']);

        $this->assertTrue($this->apply(['message_id' => $w, 'emoji' => '']));
        $this->assertArrayNotHasKey('customer_reaction', $this->raw($id));
    }

    public function testReactionToAnAiReplyLandsOnTheCustomerRow(): void
    {
        $reply = 'wamid.AIR' . bin2hex(random_bytes(6));
        $id = $this->message('incoming', 'wamid.IN' . bin2hex(random_bytes(6)), ['reply_wamid' => $reply]);

        $this->assertTrue($this->apply(['message_id' => $reply, 'emoji' => '😂']));
        $this->assertSame('😂', $this->raw($id)['customer_reaction_reply'] ?? null);
    }

    public function testUnknownTargetsAndBadValuesAreRejected(): void
    {
        $this->assertFalse($this->apply(['message_id' => 'wamid.DOESNOTEXIST12345', 'emoji' => '👍']));
        $this->assertFalse($this->apply(['message_id' => 'x', 'emoji' => '👍']));
        $w = 'wamid.OUT' . bin2hex(random_bytes(6));
        $id = $this->message('outgoing', $w);
        $this->assertFalse($this->apply(['message_id' => $w, 'emoji' => '<script>']));
        $this->assertArrayNotHasKey('customer_reaction', $this->raw($id));
    }
}
