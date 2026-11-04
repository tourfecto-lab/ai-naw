<?php

use PHPUnit\Framework\TestCase;

/** Retrying the same cycle must not charge or refund the client twice: a reservation settles exactly once. */
final class SeoUsageSettlementOnceTest extends TestCase
{
    private ?Database $db = null;
    private const UID = 999951;
    private const TOKEN = 'zzsettleonce-0000000000000000000000001';

    protected function setUp(): void
    {
        try {
            $app = dirname(__DIR__, 2) . '/app';
            if (!defined('APP_ENV')) foreach (['app.php', 'database.php', 'encryption.php'] as $cfg) if (file_exists($app . '/Config/' . $cfg)) require_once $app . '/Config/' . $cfg;
            if (!class_exists('Database')) require_once $app . '/Core/Database.php';
            $this->db = Database::getInstance();
            if (!$this->db->query("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'service_usage_reservations'")) {
                $this->markTestSkipped('service_usage_reservations not present');
            }
            $this->db->query("INSERT INTO users (id, email, password, company_name, created_at) VALUES (?, 'settle-once@tourfecto.test', 'x', 'T', NOW()) ON DUPLICATE KEY UPDATE email = email", [self::UID]);
        } catch (Throwable $e) {
            $this->markTestSkipped('DB not available: ' . $e->getMessage());
        }
        $this->clean();
        $this->db->query("INSERT INTO service_usage_reservations (token, user_id, owner_user_id, feature_key, source, amount, usage_event_id, state, created_at, updated_at) VALUES (?, ?, ?, 'marketing_tool', 'plan', 0, NULL, 'reserved', NOW(), NOW())", [self::TOKEN, self::UID, self::UID]);
    }

    protected function tearDown(): void
    {
        if ($this->db) {
            $this->clean();
            $this->db->query('DELETE FROM users WHERE id = ?', [self::UID]);
        }
    }

    private function clean(): void
    {
        $this->db->query('DELETE FROM service_usage_reservations WHERE token = ?', [self::TOKEN]);
    }

    private function state(): string
    {
        return (string) $this->db->query('SELECT state FROM service_usage_reservations WHERE token = ?', [self::TOKEN])[0]['state'];
    }

    public function testCompletingTwiceIsHarmlessAndReleasingAfterCompletionIsRefused(): void
    {
        $this->assertTrue(ServiceUsageReservation::settle(self::TOKEN, 'completed'));
        $this->assertTrue(ServiceUsageReservation::settle(self::TOKEN, 'completed'), 'a retried worker settles the same way, no second effect');
        $this->assertFalse(ServiceUsageReservation::settle(self::TOKEN, 'released'), 'a completed cycle can no longer be refunded or re-released');
        $this->assertSame('completed', $this->state());
    }

    public function testAnUncertainOutcomeCanBeResolvedOnceAndOnlyOnce(): void
    {
        $this->assertTrue(ServiceUsageReservation::settle(self::TOKEN, 'uncertain'));
        $this->assertSame('uncertain', $this->state());
        $this->assertTrue(ServiceUsageReservation::settle(self::TOKEN, 'completed'), 'reconciliation can confirm the charge');
        $this->assertFalse(ServiceUsageReservation::settle(self::TOKEN, 'uncertain'), 'but cannot move it back');
        $this->assertSame('completed', $this->state());
    }

    public function testUnknownTokenSettlesNothing(): void
    {
        $this->assertFalse(ServiceUsageReservation::settle('no-such-token', 'completed'));
    }
}
