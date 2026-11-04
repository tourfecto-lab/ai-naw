<?php

use PHPUnit\Framework\TestCase;

/** Per-cycle cost for the admin: what the client was charged, confirmed provider spend, and what is still unsettled. */
final class SeoCycleCostReportTest extends TestCase
{
    private ?Database $db = null;
    private const WID = 999921;
    private const UID = 999921;
    private const TOKEN = 'zzcostreport-token-000000000000000000000000';

    protected function setUp(): void
    {
        try {
            $app = dirname(__DIR__, 2) . '/app';
            if (!defined('APP_ENV')) foreach (['app.php', 'database.php', 'encryption.php'] as $cfg) if (file_exists($app . '/Config/' . $cfg)) require_once $app . '/Config/' . $cfg;
            if (!class_exists('Database')) require_once $app . '/Core/Database.php';
            $this->db = Database::getInstance();
            SeoDataSchema::ensureSchema($this->db);
            if (!$this->db->query("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('seo_expert_runs')")
                || !$this->db->query("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'service_usage_reservations'")) {
                $this->markTestSkipped('SEO Expert tables not present');
            }
        } catch (Throwable $e) {
            $this->markTestSkipped('DB not available: ' . $e->getMessage());
        }
        $this->clean();
    }

    protected function tearDown(): void
    {
        if ($this->db) $this->clean();
    }

    private function clean(): void
    {
        $this->db->query('DELETE FROM dataforseo_calls WHERE website_id = ?', [self::WID]);
        $this->db->query('DELETE FROM seo_expert_runs WHERE website_id = ?', [self::WID]);
        $this->db->query('DELETE FROM service_usage_reservations WHERE token = ?', [self::TOKEN]);
    }

    public function testCycleShowsClientChargeAndSeparatesConfirmedFromUnsettledProviderSpend(): void
    {
        $this->db->query("INSERT INTO service_usage_reservations (token, user_id, owner_user_id, feature_key, source, amount, state, created_at, updated_at) VALUES (?, ?, ?, 'marketing_tool', 'wallet', 1.50, 'completed', UTC_TIMESTAMP(), UTC_TIMESTAMP())", [self::TOKEN, self::UID, self::UID]);
        $this->db->query("INSERT INTO seo_expert_runs (website_id, user_id, request_key, usage_token, status, snapshot_json, created_at, updated_at) VALUES (?, ?, 'zz-cost-report', ?, 'completed', '{}', UTC_TIMESTAMP() - INTERVAL 10 MINUTE, UTC_TIMESTAMP() - INTERVAL 2 MINUTE)", [self::WID, self::UID, self::TOKEN]);
        $runId = (int) $this->db->query('SELECT MAX(id) AS id FROM seo_expert_runs WHERE website_id = ?', [self::WID])[0]['id'];
        $ins = 'INSERT INTO dataforseo_calls (endpoint, cost, reserved_cost, website_id, status, created_at) VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP() - INTERVAL ? MINUTE)';
        $this->db->query($ins, ['serp/google/organic/live/advanced', 0.002, 0.0, self::WID, 'complete', 8]);
        $this->db->query($ins, ['serp/google/organic/live/advanced', 0.0, 0.01, self::WID, 'unknown', 6]);
        $this->db->query($ins, ['serp/google/organic/live/advanced', 0.5, 0.0, self::WID, 'complete', 120]); // outside the cycle window

        $mine = null;
        foreach ((new SeoDataCostReport($this->db))->cycles(100) as $c) {
            if ($c['run_id'] === $runId) $mine = $c;
        }
        $this->assertNotNull($mine, 'the cycle is listed');
        $this->assertSame('SEO-' . $runId, $mine['reference']);
        $this->assertSame(['wallet', 1.5, 'completed'], [$mine['client_charge']['source'], $mine['client_charge']['amount'], $mine['client_charge']['state']]);
        $this->assertSame(2, $mine['provider']['calls']);
        $this->assertSame(0.002, $mine['provider']['confirmed']);
        $this->assertSame(0.01, $mine['provider']['unsettled'], 'an interrupted paid call stays unsettled until reconciled');
    }
}
