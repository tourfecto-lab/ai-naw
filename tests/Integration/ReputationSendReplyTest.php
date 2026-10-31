<?php

/**
 * Reputation: a reply must never be marked "sent" unless it was really posted to the platform.
 * Sources without a programmatic reply API (Booking, Expedia, Trustpilot, custom, TripAdvisor) and Google
 * reviews without an external id are kept as drafts with a manual-action response, and no credit is consumed.
 */

use PHPUnit\Framework\TestCase;

final class ReputationSendReplyTest extends TestCase
{
    private const UID = 999031;
    private const WID = 999031;
    private const PLAN = 999031;

    private ?PDO $pdo = null;

    protected function setUp(): void
    {
        try {
            $db = Database::getInstance();
            $ref = new ReflectionProperty(Database::class, 'connection');
            $ref->setAccessible(true);
            $this->pdo = $ref->getValue($db);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DB not available');
        }
        if (!$this->pdo instanceof PDO) {
            $this->markTestSkipped('DB not available');
        }
        $this->cleanup();
        $this->pdo->exec("INSERT INTO users (id, email, password, company_name, created_at) VALUES (" . self::UID . ", 'rep-send@tourfecto.test', 'x', 'T', NOW())");
        $this->pdo->exec("INSERT INTO websites (id, user_id, main_url, company_name, industry, is_verified, created_at) VALUES (" . self::WID . ", " . self::UID . ", 'https://rep-send.test', 'T', 'tourism', 1, NOW())");
        $this->pdo->exec("INSERT INTO subscription_plans (id, plan_code, name, price, currency, billing_cycle, ai_analysis_limit, ai_message_limit, review_auto_reply_limit, features_json, is_active, sort_order) VALUES (" . self::PLAN . ", 'repsend_monthly', 'RepSend', 10, 'USD', 'monthly', 10, 10, 50, '{}', 1, 999)");
        $this->pdo->exec("INSERT INTO subscriptions (user_id, plan_id, status, price, currency, usage_review_reply_count, current_period_start, current_period_end) VALUES (" . self::UID . ", " . self::PLAN . ", 'active', 10, 'USD', 0, NOW(), DATE_ADD(NOW(), INTERVAL 30 DAY))");
    }

    protected function tearDown(): void
    {
        if ($this->pdo) {
            $this->cleanup();
        }
    }

    private function cleanup(): void
    {
        $this->pdo->exec("DELETE FROM reviews WHERE user_id = " . self::UID);
        $this->pdo->exec("DELETE FROM billing_usage_reservations WHERE user_id = " . self::UID);
        $this->pdo->exec("DELETE FROM subscriptions WHERE user_id = " . self::UID);
        $this->pdo->exec("DELETE FROM subscription_plans WHERE id = " . self::PLAN);
        $this->pdo->exec("DELETE FROM websites WHERE id = " . self::WID);
        $this->pdo->exec("DELETE FROM users WHERE id = " . self::UID);
    }

    private function review(string $platform, ?string $externalId): int
    {
        $st = $this->pdo->prepare("INSERT INTO reviews (website_id, user_id, source_platform, external_review_id, reviewer_name, review_text, rating, review_date, reply_status) VALUES (?, ?, ?, ?, 'Guest', 'Great trip', 5, NOW(), NULL)");
        $st->execute([self::WID, self::UID, $platform, $externalId]);
        return (int) $this->pdo->lastInsertId();
    }

    private function send(int $reviewId): array
    {
        $c = new ReputationController();
        $set = function (string $prop, $value) use ($c) {
            $r = new ReflectionProperty(Controller::class, $prop);
            $r->setAccessible(true);
            $r->setValue($c, $value);
        };
        $set('authenticated', true);
        $set('user', ['id' => self::UID]);
        $set('data', ['reply' => 'Thank you for your review!']);
        return $c->sendReply(['id' => $reviewId]);
    }

    /** @dataProvider manualPlatforms */
    public function testReplyWithoutProgrammaticApiIsKeptAsDraftNotMarkedSent(string $platform, ?string $externalId): void
    {
        $id = $this->review($platform, $externalId);
        $res = $this->send($id);

        $this->assertFalse($res['success'] ?? true);
        $this->assertSame(422, $res['code'] ?? $res['status'] ?? 422);

        $row = $this->pdo->query("SELECT reply_status, reply_sent_at, ai_generated_reply FROM reviews WHERE id = $id")->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('pending', $row['reply_status']);
        $this->assertNull($row['reply_sent_at']);
        $this->assertSame('Thank you for your review!', $row['ai_generated_reply']);

        $used = (int) $this->pdo->query("SELECT usage_review_reply_count FROM subscriptions WHERE user_id = " . self::UID)->fetchColumn();
        $this->assertSame(0, $used, 'No credit may be consumed when nothing was posted');
    }

    public static function manualPlatforms(): array
    {
        return [
            'booking' => ['booking', 'b-1'],
            'expedia' => ['expedia', 'e-1'],
            'trustpilot' => ['trustpilot', 't-1'],
            'custom' => ['custom', 'c-1'],
            'tripadvisor' => ['tripadvisor', 'ta-1'],
            'google without external id' => ['google_business', null],
        ];
    }
}
