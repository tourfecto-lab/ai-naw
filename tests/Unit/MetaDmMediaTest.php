<?php

use PHPUnit\Framework\TestCase;

/** Pictures / voice / video / files from the inbox to Messenger and Instagram go through a short-lived public URL. */
final class MetaDmMediaTest extends TestCase
{
    public function testTypesPerChannel(): void
    {
        $this->assertSame(['image', 8, 'jpg'], MetaDmMedia::classify('image/jpeg', 'messenger'));
        $this->assertSame(['audio', 25, 'ogg'], MetaDmMedia::classify('audio/ogg', 'instagram'));
        $this->assertSame(['file', 25, 'pdf'], MetaDmMedia::classify('application/pdf', 'messenger'));
        $this->assertNull(MetaDmMedia::classify('application/pdf', 'instagram'), 'Instagram DMs take no files');
        $this->assertNull(MetaDmMedia::classify('application/x-msdownload', 'messenger'));
        $this->assertNull(MetaDmMedia::classify('text/html', 'messenger'));
    }

    public function testStoredFilesAreServedOnlyByTheirRandomName(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'dm');
        file_put_contents($tmp, 'PNGDATA');
        $name = MetaDmMedia::store($tmp, 'png');
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}\.png$/', (string) $name);
        $p = MetaDmMedia::path((string) $name);
        $this->assertNotNull($p);
        $this->assertSame('image/png', MetaDmMedia::mimeForExt('png'));
        foreach (['../../.env', 'abc.png', str_repeat('a', 32) . '.php', str_repeat('g', 32) . '.png', $name . '/..', ''] as $bad) {
            $this->assertNull(MetaDmMedia::path($bad), "refused: $bad");
        }
        $this->assertNull(MetaDmMedia::store($tmp, '../x'));
        @unlink($tmp);
        @unlink((string) $p);
    }

    public function testOnlyOurOwnUrlsAreAccepted(): void
    {
        $this->assertTrue(MetaDmMedia::isOurs(MetaDmMedia::url(str_repeat('a', 32) . '.jpg')));
        $this->assertFalse(MetaDmMedia::isOurs('https://evil.example/media/out/' . str_repeat('a', 32) . '.jpg'));
        $this->assertFalse(MetaDmMedia::isOurs(MetaDmMedia::url('../x.jpg')));
        $r = MetaDmMedia::send('TOKEN', '29381276351491751', 'image', 'https://evil.example/a.jpg', 'messenger', static fn () => [200, '{}']);
        $this->assertSame('invalid_request', $r['error'], 'a foreign URL is never sent to Meta');
    }

    public function testSendBuildsTheAttachmentRequest(): void
    {
        $seen = [];
        $post = function (string $url, array $payload) use (&$seen): array {
            $seen = [$url, $payload];
            return [200, json_encode(['message_id' => 'm_abc', 'recipient_id' => '1'])];
        };
        $url = MetaDmMedia::url(str_repeat('b', 32) . '.mp4');
        $r = MetaDmMedia::send('PAGE-TOKEN', '29381276351491751', 'video', $url, 'messenger', $post);
        $this->assertSame(['ok' => true, 'id' => 'm_abc', 'error' => ''], $r);
        $this->assertStringContainsString('/me/messages?access_token=PAGE-TOKEN', $seen[0]);
        $this->assertSame('RESPONSE', $seen[1]['messaging_type']);
        $this->assertSame(['type' => 'video', 'payload' => ['url' => $url]], $seen[1]['message']['attachment']);
        MetaDmMedia::send('T', '17841475954414841', 'image', MetaDmMedia::url(str_repeat('c', 32) . '.png'), 'instagram', $post);
        $this->assertArrayNotHasKey('messaging_type', $seen[1], 'Instagram has no messaging_type');
        $err = MetaDmMedia::send('T', '29381276351491751', 'image', MetaDmMedia::url(str_repeat('c', 32) . '.png'), 'messenger', static fn () => [400, json_encode(['error' => ['message' => 'Invalid URL']])]);
        $this->assertSame(['ok' => false, 'id' => '', 'error' => 'Invalid URL'], $err);
    }
}
