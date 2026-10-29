<?php

/**
 * TOURFECTO AI CEO: القرارات والموافقات والأوضاع وزرار الطوارئ، والعزل عن عقل العملاء
 *   1) APPROVAL MODE مابيوافقش على حاجة لوحده.
 *   2) AUTO MODE بيوافق على منخفض الخطر بس؛ أي تواصل بره المنصة يفضل مستني.
 *   3) زرار الطوارئ بيوقف التنفيذ التلقائي.
 *   4) القرار اللي سببه اختفى بيتقفل "اتحل"، وكل حاجة بتتسجل.
 *   5) مفيش أي كتابة في brain_decisions بتاعة العملاء.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/Services/AiCeo/AiCeoStore.php';
if (!class_exists('PlatformDecisionsTest', false)) require_once __DIR__ . '/../Unit/PlatformDecisionsTest.php';

final class AiCeoStoreIntegrationTest extends TestCase
{
    private ?Database $db = null;

    protected function setUp(): void
    {
        try {
            $app = dirname(__DIR__, 2) . '/app';
            if (!defined('APP_ENV')) foreach (['app.php', 'database.php', 'encryption.php'] as $cfg) if (file_exists($app . '/Config/' . $cfg)) require_once $app . '/Config/' . $cfg;
            if (!class_exists('Database')) require_once $app . '/Core/Database.php';
            $this->db = Database::getInstance();
            BrainSchema::ensure($this->db);
        } catch (Throwable $e) {
            $this->markTestSkipped('DB غير متاحة: ' . $e->getMessage());
        }
        $this->clean();
    }

    protected function tearDown(): void
    {
        if ($this->db) $this->clean();
    }

    private function clean(): void
    {
        foreach (['aiceo_decisions', 'aiceo_memory', 'aiceo_activity'] as $t) $this->db->query("DELETE FROM {$t}");
        $this->db->query("DELETE FROM system_settings WHERE setting_key IN ('aiceo_auto', 'aiceo_paused')");
        BrainConfig::reset();
    }

    private function statuses(array $list): array
    {
        $out = [];
        foreach ($list as $d) $out[$d['key']] = $d['status'];
        return $out;
    }

    public function testApprovalModeNeverActsAlone(): void
    {
        $store = new AiCeoStore($this->db);
        $this->assertSame(['mode' => 'approval', 'paused' => false], $store->settings());
        $store->sync(PlatformDecisionsTest::snapshot());
        $this->assertSame(0, $store->autoExecute());
        foreach ($store->list() as $d) $this->assertSame('proposed', $d['status']);
    }

    public function testAutoModeApprovesLowRiskOnly(): void
    {
        $store = new AiCeoStore($this->db);
        $store->setMode('auto', 1);
        $store->sync(PlatformDecisionsTest::snapshot());
        $n = $store->autoExecute();
        $this->assertSame(true, $n > 0 && $n <= AiCeoStore::AUTO_PER_CYCLE);
        foreach ($store->list() as $d) {
            if ($d['status'] === 'approved') $this->assertSame('low', $d['risk'], $d['key']);
            if ($d['risk'] !== 'low') $this->assertSame('proposed', $d['status'], $d['key']);
        }
        $actions = array_column($store->activity(200), 'action');
        $this->assertContains('decision_auto_approved', $actions);
        $this->assertContains('mode_changed', $actions);
    }

    public function testEmergencyStopBlocksAutomaticActions(): void
    {
        $store = new AiCeoStore($this->db);
        $store->setMode('auto', 1);
        $store->setPaused(true, 1, 'test');
        $store->sync(PlatformDecisionsTest::snapshot());
        $this->assertSame(0, $store->autoExecute());
        $this->assertSame(true, $store->settings()['paused']);
        $this->assertContains('emergency_stop', array_column($store->activity(50), 'action'));
        $store->setPaused(false, 1);
        $this->assertSame(false, $store->settings()['paused']);
    }

    public function testOwnerDecisionsAreRememberedAndGoneCausesResolve(): void
    {
        $store = new AiCeoStore($this->db);
        $list = $store->sync(PlatformDecisionsTest::snapshot());
        $trials = array_values(array_filter($list, static fn ($d) => $d['key'] === 'trials_ending'))[0];
        $store->setStatus($trials['id'], 'approved', 7, 'call them today');
        $this->assertSame('approved', $this->statuses($store->list())['trials_ending']);
        $this->assertSame('Approved: Free trials end within 24 hours', $store->memory(5, 'decision')[0]['title']);

        // الصورة الجاية: التجارب مش هتخلص خلال 24 ساعة ← القرار "اتحل"
        $snap = PlatformDecisionsTest::snapshot(['trials' => ['active' => 4, 'ending_24h' => 0, 'ended_unconverted' => 3, 'signed_up_no_trial' => 1]]);
        $this->assertSame('resolved', $this->statuses($store->sync($snap))['trials_ending']);

        $this->expectException(InvalidArgumentException::class);
        $store->setStatus($trials['id'], 'published', 7);
    }

    public function testCustomerBrainTablesAreNotTouched(): void
    {
        $before = (int) ($this->db->query('SELECT COUNT(*) AS c FROM brain_decisions')[0]['c'] ?? 0);
        $store = new AiCeoStore($this->db);
        $store->setMode('auto', 1);
        $store->sync(PlatformDecisionsTest::snapshot());
        $store->autoExecute();
        $this->assertSame($before, (int) ($this->db->query('SELECT COUNT(*) AS c FROM brain_decisions')[0]['c'] ?? 0));
    }
}
