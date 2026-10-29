<?php

use PHPUnit\Framework\TestCase;

/** A dead Facebook token marks the connection and tells the owner once, instead of failing silently forever. */
final class MetaChannelHealthTest extends TestCase
{
    public function testWhichGraphCodesMeanADeadToken(): void
    {
        foreach ([190, 102, 463, 467] as $c) {
            $this->assertTrue(MetaChannelHealth::isAuthError($c), (string) $c);
        }
        foreach ([0, 100, 613, 200, 551] as $c) {
            $this->assertFalse(MetaChannelHealth::isAuthError($c), (string) $c);
        }
    }

    public function testTheConnectionIsMarkedOnceAndAnythingElseIsIgnored(): void
    {
        $db = Database::getInstance();
        $site = $db->query('SELECT id, user_id FROM websites ORDER BY id LIMIT 1')[0] ?? null;
        if (!$site) {
            $this->markTestSkipped('no website fixture');
        }
        $wid = (int) $site['id'];
        $page = 'HP' . bin2hex(random_bytes(4));
        $db->query("INSERT INTO platform_connections (website_id, user_id, platform, external_location_id, status, created_at) VALUES (?, ?, 'messenger', ?, 'connected', NOW())", [$wid, (int) $site['user_id'], $page]);
        try {
            $this->assertFalse(MetaChannelHealth::note($wid, 'messenger', 100, 'Invalid parameter'), 'an ordinary error changes nothing');
            $this->assertFalse(MetaChannelHealth::note($wid, 'whatsapp', 190, 'x'), 'only Messenger / Instagram');
            $this->assertSame('connected', $db->query('SELECT status FROM platform_connections WHERE external_location_id = ?', [$page])[0]['status']);
            $this->assertTrue(MetaChannelHealth::note($wid, 'messenger', 190, 'Error validating access token'));
            $row = $db->query('SELECT status, last_error FROM platform_connections WHERE external_location_id = ?', [$page])[0];
            $this->assertSame('token_expired', $row['status']);
            $this->assertSame('Error validating access token', $row['last_error']);
            $this->assertFalse(MetaChannelHealth::note($wid, 'messenger', 190, 'again'), 'the owner is told once');
            $health = (new MetaChatPages($db))->health($wid);
            $this->assertContains('messenger', array_column($health, 'channel'), 'the inbox shows a reconnect bar');
        } finally {
            $db->query('DELETE FROM platform_connections WHERE external_location_id = ?', [$page]);
        }
    }
}
