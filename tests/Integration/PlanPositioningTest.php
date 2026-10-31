<?php

/**
 * "X% below competitors" pricing guide: defaults, max allowed price maths, validation, saving and the change log.
 */

use PHPUnit\Framework\TestCase;

final class PlanPositioningTest extends TestCase
{
    private ?PDO $pdo = null;
    private ?Database $db = null;
    private ?string $saved = null;

    protected function setUp(): void
    {
        require_once __DIR__ . '/../../app/Services/Subscription/BillingPlanCatalog.php';
        require_once __DIR__ . '/../../app/Services/Subscription/PlanPositioning.php';
        try {
            $this->db = Database::getInstance();
            $this->pdo = $this->db->getConnection();
            $v = $this->pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = '" . PlanPositioning::KEY . "'")->fetchColumn();
            $this->saved = $v === false ? null : (string) $v;
        } catch (\Throwable $e) {
            $this->markTestSkipped('DB not available');
        }
        $this->pdo->exec("DELETE FROM system_settings WHERE setting_key = '" . PlanPositioning::KEY . "'");
        BillingPlanCatalog::ensureAuditTable($this->db);
        $this->pdo->exec("DELETE FROM plan_change_audit WHERE plan_key = '_positioning'");
    }

    protected function tearDown(): void
    {
        if ($this->pdo) {
            $this->pdo->exec("DELETE FROM system_settings WHERE setting_key = '" . PlanPositioning::KEY . "'");
            if ($this->saved !== null) {
                $this->pdo->prepare("INSERT INTO system_settings (setting_key, setting_value, is_secret, category) VALUES (?, ?, 0, 'pricing')")->execute([PlanPositioning::KEY, $this->saved]);
            }
            $this->pdo->exec("DELETE FROM plan_change_audit WHERE plan_key = '_positioning'");
        }
    }

    public function testDefaultsGive20PercentBelowTheCheapestComparableCompetitor(): void
    {
        $cfg = PlanPositioning::config($this->db);
        $this->assertSame(20.0, $cfg['below_pct']);
        $lim = PlanPositioning::limits($cfg);
        $this->assertSame(103.0, $lim['seo-essential']['max_price']); // 129 * 0.8 = 103.2
        $this->assertSame(159.0, $lim['seo-growth']['max_price']);    // 199 * 0.8 = 159.2
        $this->assertSame(239.0, $lim['seo-agency']['max_price']);    // 299 * 0.8 = 239.2
        $this->assertArrayNotHasKey('starter', $lim, 'a plan without a verified reference gets no invented limit');
    }

    public function testSavingChangesThePercentageAndTheReferences(): void
    {
        $cfg = PlanPositioning::save($this->db, ['below_pct' => 25, 'refs' => ['starter' => ['name' => 'Buffer', 'price' => '60'], 'seo-growth' => ['name' => '', 'price' => '']]], 7);
        $this->assertSame(25.0, $cfg['below_pct']);
        $this->assertSame(['starter'], array_keys($cfg['refs']), 'empty price removes the reference');
        $again = PlanPositioning::config($this->db);
        $this->assertSame(45.0, PlanPositioning::limits($again)['starter']['max_price']);
        $rows = $this->pdo->query("SELECT changes_json, actor_id FROM plan_change_audit WHERE plan_key = '_positioning'")->fetchAll(PDO::FETCH_ASSOC);
        $this->assertCount(1, $rows);
        $this->assertSame(7, (int) $rows[0]['actor_id']);
        $diff = json_decode($rows[0]['changes_json'], true);
        $this->assertEquals(['from' => 20, 'to' => 25], $diff['below_pct']);
        $this->assertEquals(['from' => 199, 'to' => null], $diff['ref:seo-growth']);
    }

    public function testInvalidInputIsRejectedAndNothingIsSaved(): void
    {
        foreach ([
            ['below_pct' => 95, 'refs' => []],
            ['below_pct' => 'x', 'refs' => []],
            ['below_pct' => 20, 'refs' => ['Bad Key' => ['name' => 'A', 'price' => 10]]],
            ['below_pct' => 20, 'refs' => ['starter' => ['name' => 'A', 'price' => -5]]],
            ['below_pct' => 20, 'refs' => ['starter' => ['name' => '', 'price' => 50]]],
        ] as $in) {
            try {
                PlanPositioning::save($this->db, $in, null);
                $this->fail('should reject ' . json_encode($in));
            } catch (InvalidArgumentException $e) {
                $this->assertTrue(true);
            }
        }
        $this->assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM system_settings WHERE setting_key = '" . PlanPositioning::KEY . "'")->fetchColumn());
    }
}
