<?php

use PHPUnit\Framework\TestCase;

final class PostStillUsableTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../app/Services/Publishing/WordPressPublisher.php';
    }

    private function publisher(string $body, int $code): WordPressPublisher
    {
        return new WordPressPublisher(static fn (array $req): array => ['body' => $body, 'http_code' => $code, 'error' => null]);
    }

    public function testTrashedPostIsNotUsable(): void
    {
        $this->assertFalse($this->publisher(json_encode(['id' => 5, 'status' => 'trash']), 200)->postStillUsable('https://s.test', 'u', 'p', 5));
    }

    public function testDeletedPostIsNotUsable(): void
    {
        $this->assertFalse($this->publisher(json_encode(['code' => 'rest_post_invalid_id', 'message' => 'Invalid post ID.']), 404)->postStillUsable('https://s.test', 'u', 'p', 5));
    }

    public function testDraftAndPublishedPostsAreUsable(): void
    {
        $this->assertTrue($this->publisher(json_encode(['id' => 5, 'status' => 'draft']), 200)->postStillUsable('https://s.test', 'u', 'p', 5));
        $this->assertTrue($this->publisher(json_encode(['id' => 5, 'status' => 'publish']), 200)->postStillUsable('https://s.test', 'u', 'p', 5));
    }

    public function testTemporaryErrorDoesNotCreateADuplicate(): void
    {
        $this->assertTrue($this->publisher('oops', 500)->postStillUsable('https://s.test', 'u', 'p', 5));
        $this->assertTrue($this->publisher('{}', 403)->postStillUsable('https://s.test', 'u', 'p', 5));
    }

    public function testUpdateSendsRequestedStatus(): void
    {
        $sent = [];
        $p = new WordPressPublisher(static function (array $req) use (&$sent): array {
            $sent = json_decode((string) $req['body'], true);
            return ['body' => json_encode(['id' => 5, 'link' => 'https://s.test/x']), 'http_code' => 200, 'error' => null];
        });
        $p->updatePost('https://s.test', 'u', 'p', 5, 'T', '<p>x</p>', '', null, 'publish');
        $this->assertSame('publish', $sent['status']);
        $p->updatePost('https://s.test', 'u', 'p', 5, 'T', '<p>x</p>');
        $this->assertArrayNotHasKey('status', $sent);
    }
}
