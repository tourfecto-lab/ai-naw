<?php

/**
 * حجوزات DataForSEO المعلقة: طلب انقطع (unknown) أو وقف في النص (pending) كان بيحجز السقف كله طول الشهر.
 * بعد ساعة بيتسجل بمتوسط التكلفة الفعلية لنفس الخدمة، والجديد أو اللي مالوش تاريخ بيفضل محجوز.
 */

use PHPUnit\Framework\TestCase;

final class SeoDataLedgerReconcileTest extends TestCase
{
    private ?Database $db = null;
    private const EP = 'serp/google/organic/live/advanced';

    protected function setUp(): void
    {
        try {
            $app = dirname(__DIR__, 2) . '/app';
            if (!defined('APP_ENV')) foreach (['app.php', 'database.php', 'encryption.php'] as $cfg) if (file_exists($app . '/Config/' . $cfg)) require_once $app . '/Config/' . $cfg;
            if (!class_exists('Database')) require_once $app . '/Core/Database.php';
            $this->db = Database::getInstance();
            SeoDataSchema::ensureSchema($this->db);
        } catch (Throwable $e) {
            $this->markTestSkipped('DB غير متاحة: ' . $e->getMessage());
        }
        $this->db->query('DELETE FROM dataforseo_calls WHERE website_id = 999901');
    }

    protected function tearDown(): void
    {
        if ($this->db) $this->db->query('DELETE FROM dataforseo_calls WHERE website_id = 999901');
    }

    private function row(string $ep, float $cost, float $reserved, string $status, int $minutesAgo): int
    {
        $this->db->query('INSERT INTO dataforseo_calls (endpoint, cost, reserved_cost, website_id, status, created_at) VALUES (?, ?, ?, 999901, ?, UTC_TIMESTAMP() - INTERVAL ? MINUTE)', [$ep, $cost, $reserved, $status, $minutesAgo]);
        return (int) $this->db->query('SELECT MAX(id) AS id FROM dataforseo_calls')[0]['id'];
    }

    private function get(int $id): array
    {
        return $this->db->query('SELECT cost, reserved_cost, status FROM dataforseo_calls WHERE id = ?', [$id])[0];
    }

    public function testStaleHoldsSettleAtTheServiceAverage(): void
    {
        $this->row(self::EP, 0.006, 0, 'complete', 300);
        $this->row(self::EP, 0.010, 0, 'complete', 200);
        $stale = $this->row(self::EP, 0, 0.10, 'unknown', 120);
        $stuck = $this->row(self::EP, 0, 0.10, 'pending', 90);
        $fresh = $this->row(self::EP, 0, 0.10, 'unknown', 10);
        $noHistory = $this->row('backlinks/referring_domains/live', 0, 0.20, 'unknown', 300);

        $n = (new SeoDataLedger($this->db))->reconcileStale(60);
        $this->assertSame(true, $n >= 2);
        foreach ([$stale, $stuck] as $id) {
            $r = $this->get($id);
            $this->assertSame('estimated', $r['status']);
            $this->assertSame(0.0, (float) $r['reserved_cost']);
            $this->assertSame(true, (float) $r['cost'] > 0.005 && (float) $r['cost'] < 0.011, 'average of real costs, not the 0.10 ceiling');
        }
        $this->assertSame('unknown', $this->get($fresh)['status'], 'a recent call keeps its hold');
        $this->assertSame(0.1, (float) $this->get($fresh)['reserved_cost']);
        $this->assertSame('unknown', $this->get($noHistory)['status'], 'no history: the hold stays (conservative)');
        $this->assertSame(0, (new SeoDataLedger($this->db))->reconcileStale(60) >= 1 && $this->get($stale)['status'] !== 'estimated' ? 1 : 0);
    }
}
