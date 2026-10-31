<?php

use PHPUnit\Framework\TestCase;

/** الرد العلني على تعليق مايتبعتش مرتين (ضغطتين سريعتين أو إعادة محاولة) */
final class SocialInboxReplyGuardTest extends TestCase
{
    private function insertComment(PDO $pdo, string $status): int
    {
        $pdo->prepare("INSERT INTO social_comments (user_id, platform_connection_id, platform, external_comment_id, message, status) VALUES (987001, 987001, 'facebook', ?, 'hello', ?)")
            ->execute(['t_' . bin2hex(random_bytes(6)), $status]);
        return (int) $pdo->lastInsertId();
    }

    private function pdo(): PDO
    {
        try {
            $pdo = Database::getInstance()->getConnection();
            $pdo->query('SELECT 1 FROM social_comments LIMIT 1');
            return $pdo;
        } catch (Throwable $e) {
            $this->markTestSkipped('قاعدة الاختبار مش متاحة: ' . $e->getMessage());
        }
    }

    /** @dataProvider blockedStatuses */
    public function testReplyIsRejectedWhenAlreadyRepliedOrInFlight(string $status): void
    {
        $pdo = $this->pdo();
        $id = $this->insertComment($pdo, $status);
        try {
            (new SocialCommentInbox())->reply(987001, $id, 'شكرًا لك');
            $this->fail('كان المفروض يترفض');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('اتردّ', $e->getMessage());
        } finally {
            $pdo->exec('DELETE FROM social_comments WHERE id = ' . $id);
        }
    }

    public static function blockedStatuses(): array
    {
        return ['already replied' => ['replied'], 'reply in flight' => ['replying']];
    }

    public function testFailedSendRestoresPreviousStatus(): void
    {
        $pdo = $this->pdo();
        $id = $this->insertComment($pdo, 'new');
        try {
            // مفيش platform_connections بالـid ده: الإرسال هيفشل بعد الحجز، والحالة لازم ترجع «new»
            try {
                (new SocialCommentInbox())->reply(987001, $id, 'شكرًا لك');
                $this->fail('كان المفروض يفشل');
            } catch (Throwable $e) {
                $this->assertNotInstanceOf(InvalidArgumentException::class, $e, 'الحجز لازم ينجح قبل الفشل');
            }
            $this->assertSame('new', $pdo->query('SELECT status FROM social_comments WHERE id = ' . $id)->fetchColumn());
        } finally {
            $pdo->exec('DELETE FROM social_comments WHERE id = ' . $id);
        }
    }
}
