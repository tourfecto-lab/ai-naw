<?php

use PHPUnit\Framework\TestCase;

/**
 * Messenger / Instagram messages without text (a picture, a voice note, a location, a button tap) used to be dropped,
 * so the conversation looked broken in the inbox. They now arrive as a readable placeholder plus, for media on Meta's
 * own CDN, the https URL to show.
 */
final class MetaWebhookAttachmentsTest extends TestCase
{
    public function testImageOnMetasCdnKeepsItsUrl(): void
    {
        [$text, $media] = MetaWebhookController::describeAttachment(['attachments' => [['type' => 'image', 'payload' => ['url' => 'https://scontent.xx.fbcdn.net/v/t1/a.jpg?x=1']]]]);
        $this->assertSame('[Image]', $text);
        $this->assertSame(['type' => 'image', 'url' => 'https://scontent.xx.fbcdn.net/v/t1/a.jpg?x=1'], $media);
    }

    public function testStickerIsShownAsAnImage(): void
    {
        [$text, $media] = MetaWebhookController::describeAttachment(['attachments' => [['type' => 'sticker', 'payload' => ['url' => 'https://scontent.cdninstagram.com/s.png']]]]);
        $this->assertSame('[Sticker]', $text);
        $this->assertSame('image', $media['type']);
    }

    public function testUrlsOutsideMetasCdnAreNeverKept(): void
    {
        foreach (['http://scontent.xx.fbcdn.net/a.jpg', 'https://evil.example.com/a.jpg', 'https://fbcdn.net.evil.com/a.jpg', 'https://user@scontent.xx.fbcdn.net/a.jpg', 'https://scontent.xx.fbcdn.net:8443/a.jpg', 'javascript:alert(1)', ''] as $url) {
            [$text, $media] = MetaWebhookController::describeAttachment(['attachments' => [['type' => 'image', 'payload' => ['url' => $url]]]]);
            $this->assertSame('[Image]', $text, 'the placeholder text is still delivered');
            $this->assertNull($media, "not kept: $url");
        }
    }

    public function testVoiceVideoFileAndLocation(): void
    {
        $this->assertSame('[Voice message]', MetaWebhookController::describeAttachment(['attachments' => [['type' => 'audio', 'payload' => ['url' => 'https://x.fbcdn.net/a.mp4']]]])[0]);
        $this->assertSame('[Video]', MetaWebhookController::describeAttachment(['attachments' => [['type' => 'video', 'payload' => []]]])[0]);
        $this->assertSame('[File]', MetaWebhookController::describeAttachment(['attachments' => [['type' => 'file', 'payload' => []]]])[0]);
        $this->assertSame('[Location] 25.7,32.6', MetaWebhookController::describeAttachment(['attachments' => [['type' => 'location', 'payload' => ['coordinates' => ['lat' => 25.7, 'long' => 32.6]]]]])[0]);
        $this->assertSame('[Story mention]', MetaWebhookController::describeAttachment(['attachments' => [['type' => 'story_mention', 'payload' => []]]])[0]);
    }

    public function testButtonTapBecomesText(): void
    {
        $this->assertSame(['Book now', null], MetaWebhookController::describeAttachment([], ['postback' => ['title' => 'Book now', 'payload' => 'BOOK']]));
        $this->assertSame(['', null], MetaWebhookController::describeAttachment([], []));
    }

    public function testUnknownTypesAreSanitised(): void
    {
        $this->assertSame('[weirdtype]', MetaWebhookController::describeAttachment(['attachments' => [['type' => 'Weird<Type>!', 'payload' => []]]])[0]);
    }

    /** A message that could not be saved because of an error on our side is un-marked, so Meta's redelivery is processed. */
    public function testAFailedMessageIsRetriedNotLostAsADuplicate(): void
    {
        $db = Database::getInstance();
        $site = $db->query('SELECT id, user_id FROM websites ORDER BY id LIMIT 1')[0] ?? null;
        if (!$site) {
            $this->markTestSkipped('no website fixture');
        }
        $page = 'PAGE' . bin2hex(random_bytes(4));
        $db->query("INSERT INTO platform_connections (website_id, user_id, platform, external_location_id, status, created_at) VALUES (?, ?, 'messenger', ?, 'connected', NOW())", [(int) $site['id'], (int) $site['user_id'], $page]);
        $mid = 'm_' . bin2hex(random_bytes(6));
        $payload = ['object' => 'page', 'entry' => [['id' => $page, 'messaging' => [['sender' => ['id' => 'PSID9'], 'message' => ['mid' => $mid, 'text' => 'hello']]]]]];
        $boom = new class {
            public function processIncomingMessage(array $d): array { throw new RuntimeException('db down'); }
        };
        $seen = [];
        $ok = new class($seen) {
            public function __construct(public array &$seen) {}
            public function processIncomingMessage(array $d): array { $this->seen[] = $d; return ['success' => true]; }
        };
        try {
            $r1 = MetaWebhookController::route($payload, $boom);
            $this->assertSame(1, $r1['retry'], 'asks Meta to retry');
            $r2 = MetaWebhookController::route($payload, $ok);
            $this->assertSame(1, $r2['messages'], 'the redelivery is processed, not dropped as a duplicate');
            $this->assertSame('hello', $seen[0]['message']);
            $r3 = MetaWebhookController::route($payload, $ok);
            $this->assertSame(0, $r3['messages'], 'a genuine duplicate is still ignored');
        } finally {
            $db->query('DELETE FROM platform_connections WHERE external_location_id = ?', [$page]);
            $db->query('DELETE FROM ai_webhook_events WHERE external_event_id = ?', [$mid]);
        }
    }

    /** chat_messages.platform has no 'instagram' value: storing it fails in strict mode and the DM was silently lost. */
    public function testInstagramIsStoredWithAPlatformTheTableAccepts(): void
    {
        foreach (['whatsapp', 'telegram', 'messenger', 'webchat', 'other'] as $p) {
            $this->assertSame($p, ChatManager::storedPlatform($p));
        }
        $this->assertSame('other', ChatManager::storedPlatform('instagram'));
        $this->assertSame('other', ChatManager::storedPlatform(''));
    }

    /** Instagram's webhooks product can send the message as entry.changes[] {field: messages, value: {...}} instead of entry.messaging[]. */
    public function testInstagramChangesFormatIsRouted(): void
    {
        $db = Database::getInstance();
        $site = $db->query('SELECT id, user_id FROM websites ORDER BY id LIMIT 1')[0] ?? null;
        if (!$site) {
            $this->markTestSkipped('no website fixture');
        }
        $ig = '1784' . random_int(100000000, 999999999);
        $db->query(
            "INSERT INTO platform_connections (website_id, user_id, platform, external_location_id, status, created_at) VALUES (?, ?, 'instagram_dm', ?, 'connected', NOW())",
            [(int) $site['id'], (int) $site['user_id'], $ig]
        );
        $mid = 'm_' . bin2hex(random_bytes(6));
        $seen = [];
        $fake = new class($seen) {
            public function __construct(public array &$seen) {}
            public function processIncomingMessage(array $d): array { $this->seen[] = $d; return ['success' => true]; }
        };
        try {
            $payload = ['object' => 'instagram', 'entry' => [['id' => $ig, 'time' => 1, 'changes' => [[
                'field' => 'messages',
                'value' => ['sender' => ['id' => 'IGSID1'], 'recipient' => ['id' => $ig], 'message' => ['mid' => $mid, 'text' => 'hello from instagram']],
            ]]]]];
            $r = MetaWebhookController::route($payload, $fake);
            $this->assertSame(1, $r['messages']);
            $this->assertSame('instagram', $seen[0]['platform']);
            $this->assertSame('hello from instagram', $seen[0]['message']);
            $this->assertSame('IGSID1', $seen[0]['phone_number']);
        } finally {
            $db->query('DELETE FROM platform_connections WHERE external_location_id = ?', [$ig]);
            $db->query('DELETE FROM ai_webhook_events WHERE external_event_id = ?', [$mid]);
        }
    }

    /** End to end through route(): a picture with no text reaches the chat manager as "[Image]" with its CDN URL. */
    public function testRouteDeliversAPictureWithoutText(): void
    {
        $db = Database::getInstance();
        $site = $db->query('SELECT id, user_id FROM websites ORDER BY id LIMIT 1')[0] ?? null;
        if (!$site) {
            $this->markTestSkipped('no website fixture');
        }
        $page = 'PAGE' . bin2hex(random_bytes(4));
        $db->query(
            "INSERT INTO platform_connections (website_id, user_id, platform, external_location_id, status, created_at) VALUES (?, ?, 'messenger', ?, 'connected', NOW())",
            [(int) $site['id'], (int) $site['user_id'], $page]
        );
        $mid = 'm_' . bin2hex(random_bytes(6));
        $seen = [];
        $fake = new class($seen) {
            public function __construct(public array &$seen) {}
            public function processIncomingMessage(array $d): array { $this->seen[] = $d; return ['success' => true]; }
        };
        try {
            $payload = ['object' => 'page', 'entry' => [['id' => $page, 'messaging' => [[
                'sender' => ['id' => 'PSID1'],
                'message' => ['mid' => $mid, 'attachments' => [['type' => 'image', 'payload' => ['url' => 'https://scontent.xx.fbcdn.net/p.jpg']]]],
            ]]]]];
            $r = MetaWebhookController::route($payload, $fake);
            $this->assertSame(1, $r['messages']);
            $this->assertSame('[Image]', $seen[0]['message']);
            $this->assertSame('messenger', $seen[0]['platform']);
            $this->assertSame(['type' => 'image', 'url' => 'https://scontent.xx.fbcdn.net/p.jpg'], $seen[0]['media_ext']);
        } finally {
            $db->query('DELETE FROM platform_connections WHERE external_location_id = ?', [$page]);
            $db->query('DELETE FROM ai_webhook_events WHERE external_event_id = ?', [$mid]);
        }
    }
}
