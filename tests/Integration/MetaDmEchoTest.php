<?php

use PHPUnit\Framework\TestCase;

/** Replies made outside the platform (Page inbox, Business Suite, Instagram app) join the thread and pause the AI. */
final class MetaDmEchoTest extends TestCase
{
    private ?array $site = null;
    private string $customer = '';

    protected function setUp(): void
    {
        putenv('META_MSG_APP_ID=1003405352022041');
        putenv('META_MSG_APP_SECRET=s');
        $db = Database::getInstance();
        $this->site = $db->query('SELECT id, user_id FROM websites ORDER BY id LIMIT 1')[0] ?? null;
        if (!$this->site) {
            $this->markTestSkipped('no website fixture');
        }
        $this->customer = '88' . random_int(100000000, 999999999);
    }

    protected function tearDown(): void
    {
        putenv('META_MSG_APP_ID');
        putenv('META_MSG_APP_SECRET');
        if ($this->site) {
            $db = Database::getInstance();
            $ids = array_column($db->query('SELECT id FROM ai_conversations WHERE website_id = ? AND channel_thread_id = ?', [(int) $this->site['id'], $this->customer]), 'id');
            foreach ($ids as $id) {
                $db->query('DELETE FROM chat_messages WHERE conversation_id = ?', [(int) $id]);
                $db->query('DELETE FROM ai_conversations WHERE id = ?', [(int) $id]);
            }
        }
    }

    private function store(array $m): string
    {
        return MetaDmEcho::store((int) $this->site['id'], (int) $this->site['user_id'], 'instagram', $this->customer, $m);
    }

    public function testHumanReplyFromTheAppIsStoredAndPausesTheAi(): void
    {
        $r = $this->store(['mid' => 'm_h1', 'text' => 'Hi from the Instagram app', 'app_id' => '263902037430900']);
        $this->assertSame('stored', $r);
        $db = Database::getInstance();
        $row = $db->query('SELECT message_direction, message_text, platform FROM chat_messages WHERE website_id = ? AND platform_message_id = ?', [(int) $this->site['id'], 'm_h1'])[0];
        $this->assertSame('outgoing', $row['message_direction']);
        $this->assertSame('Hi from the Instagram app', $row['message_text']);
        $this->assertSame('other', $row['platform'], 'Instagram is stored as a platform the ENUM accepts');
        $c = $db->query('SELECT ai_status, handoff_reason FROM ai_conversations WHERE website_id = ? AND channel = ? AND channel_thread_id = ?', [(int) $this->site['id'], 'instagram', $this->customer])[0];
        $this->assertSame('human', $c['ai_status']);
        $this->assertSame('replied_from_app', $c['handoff_reason']);
        $this->assertSame('duplicate', $this->store(['mid' => 'm_h1', 'text' => 'Hi from the Instagram app', 'app_id' => '263902037430900']), 'Meta re-delivers');
    }

    public function testEchoesOfOurOwnSendsAreIgnored(): void
    {
        $this->assertSame('own', $this->store(['mid' => 'm_o1', 'text' => 'sent by the bot', 'app_id' => '1003405352022041']));
        $this->assertSame(0, (int) Database::getInstance()->query('SELECT COUNT(*) c FROM ai_conversations WHERE website_id = ? AND channel_thread_id = ?', [(int) $this->site['id'], $this->customer])[0]['c'], 'nothing was created');
    }

    public function testAnEchoWithoutAnAppIdMatchingAJustSavedReplyIsOurs(): void
    {
        $db = Database::getInstance();
        $this->store(['mid' => 'm_a', 'text' => 'first', 'app_id' => '263902037430900']);
        $cid = (int) $db->query('SELECT id FROM ai_conversations WHERE website_id = ? AND channel_thread_id = ?', [(int) $this->site['id'], $this->customer])[0]['id'];
        $db->query("INSERT INTO chat_messages (website_id, conversation_id, user_id, session_id, platform, message_direction, message_text, bot_status, is_auto_pilot, created_at) VALUES (?, ?, ?, 'x', 'other', 'outgoing', 'Our reply', 'sent', 1, NOW())", [(int) $this->site['id'], $cid, (int) $this->site['user_id']]);
        $this->assertSame('own', $this->store(['mid' => 'm_b', 'text' => 'Our reply']));
        $this->assertSame('stored', $this->store(['mid' => 'm_c', 'text' => 'A different human reply']));
    }

    public function testEmptyEchoesAreIgnored(): void
    {
        $this->assertSame('empty', $this->store(['mid' => 'm_e']));
        $this->assertSame('empty', MetaDmEcho::store((int) $this->site['id'], (int) $this->site['user_id'], 'instagram', '', ['text' => 'x']));
    }
}
