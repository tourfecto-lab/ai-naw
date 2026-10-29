<?php

use PHPUnit\Framework\TestCase;

/**
 * Messenger / Instagram customers arrive as a bare id; the inbox now shows their name and picture.
 */
final class MetaContactProfileTest extends TestCase
{
    public function testMessengerAndInstagramProfilesAreParsed(): void
    {
        $m = MetaContactProfile::parse('messenger', ['first_name' => 'Omar', 'last_name' => 'Ashraf', 'profile_pic' => 'https://scontent.xx.fbcdn.net/p.jpg']);
        $this->assertSame(['name' => 'Omar Ashraf', 'pic' => 'https://scontent.xx.fbcdn.net/p.jpg'], $m);
        $i = MetaContactProfile::parse('instagram', ['username' => 'tourfecto', 'profile_pic' => 'https://scontent.cdninstagram.com/p.jpg']);
        $this->assertSame('@tourfecto', $i['name'], 'no display name: the handle is shown');
        $this->assertSame('Sara', MetaContactProfile::parse('instagram', ['name' => 'Sara', 'username' => 's'])['name']);
    }

    public function testPicturesOutsideMetasCdnAreDropped(): void
    {
        foreach (['http://scontent.xx.fbcdn.net/p.jpg', 'https://evil.example.com/p.jpg', 'https://169.254.169.254/p.jpg', ''] as $u) {
            $this->assertSame('', MetaContactProfile::parse('messenger', ['first_name' => 'A', 'profile_pic' => $u])['pic'], $u);
        }
    }

    public function testFetchSignsTheCallAndFallsBackToUnsigned(): void
    {
        $urls = [];
        $get = function (string $url) use (&$urls): array {
            $urls[] = $url;
            // the first signature (wrong secret) is refused, the unsigned call is accepted
            return strpos($url, 'appsecret_proof') !== false
                ? [400, json_encode(['error' => ['message' => 'Invalid appsecret_proof']])]
                : [200, json_encode(['first_name' => 'Omar', 'last_name' => '', 'profile_pic' => ''])];
        };
        $p = MetaContactProfile::fetch('messenger', '29381276351491751', 'PAGE-TOKEN', ['wrong-secret'], $get);
        $this->assertSame('Omar', $p['name']);
        $this->assertCount(2, $urls);
        $this->assertStringContainsString('appsecret_proof=' . hash_hmac('sha256', 'PAGE-TOKEN', 'wrong-secret'), $urls[0]);
        $this->assertStringContainsString('fields=first_name%2Clast_name%2Cprofile_pic', $urls[0]);
        $this->assertNull(MetaContactProfile::fetch('messenger', 'not-an-id', 'T', [], $get), 'ids are validated before any call');
        $this->assertNull(MetaContactProfile::fetch('messenger', '29381276351491751', '', [], $get));
    }

    public function testEnrichFillsTheNameAndOnlyWhenMissing(): void
    {
        $db = Database::getInstance();
        $site = $db->query('SELECT id, user_id FROM websites ORDER BY id LIMIT 1')[0] ?? null;
        if (!$site) {
            $this->markTestSkipped('no website fixture');
        }
        [$wid, $uid] = [(int) $site['id'], (int) $site['user_id']];
        $thread = '77' . random_int(100000000, 999999999);
        $db->query("INSERT INTO ai_conversations (website_id, user_id, channel, channel_thread_id, status, ai_status, created_at) VALUES (?, ?, 'messenger', ?, 'open', 'ai', NOW())", [$wid, $uid, $thread]);
        $cid = (int) $db->query('SELECT id FROM ai_conversations WHERE website_id = ? AND channel = ? AND channel_thread_id = ?', [$wid, 'messenger', $thread])[0]['id'];
        $calls = 0;
        $get = function (string $url) use (&$calls): array {
            $calls++;
            return [200, json_encode(['first_name' => 'Nour', 'last_name' => 'Hassan', 'profile_pic' => ''])];
        };
        try {
            MetaContactProfile::enrich($db, $wid, 'messenger', $thread, 'TOKEN', [], $get);
            $this->assertSame('Nour Hassan', $db->query('SELECT customer_name FROM ai_conversations WHERE id = ?', [$cid])[0]['customer_name']);
            $this->assertSame(1, $calls);
        } finally {
            $db->query('DELETE FROM ai_conversations WHERE id = ?', [$cid]);
            ChatContactPhotos::remove($wid, $cid);
        }
    }
}
