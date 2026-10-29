<?php

/**
 * TOURFECTO AI CEO (المرحلة 10): اختبار قبول للدورة كاملة على قاعدة البيانات الحقيقية.
 * الصورة (كل الأقسام من المرحلة 1 لـ 9) ← القرارات ← وضع الموافقة مايعملش حاجة لوحده ← الإيقاف الطارئ بيوقف التلقائي
 * ← فحص الصفحات والحوادث ← التقارير التنفيذية ← قائمة التجهيز. ولو مصدر وقع (API واقف) القسم بيبقى null والباقي يكمل.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/Services/AiCeo/PlatformSnapshot.php';
require_once __DIR__ . '/../../app/Services/AiCeo/AiCeoStore.php';
require_once __DIR__ . '/../../app/Services/AiCeo/AiCeoActivation.php';

final class AiCeoEndToEndIntegrationTest extends TestCase
{
    private ?Database $db = null;

    protected function setUp(): void
    {
        try {
            $app = dirname(__DIR__, 2) . '/app';
            if (!defined('APP_ENV')) foreach (['app.php', 'database.php', 'encryption.php'] as $cfg) if (file_exists($app . '/Config/' . $cfg)) require_once $app . '/Config/' . $cfg;
            if (!class_exists('Database')) require_once $app . '/Core/Database.php';
            $this->db = Database::getInstance();
            new AiCeoStore($this->db);
            new OpsAdvisor($this->db, fn () => ['code' => 200, 'ms' => 1]);
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
        foreach (['aiceo_decisions', 'aiceo_activity', 'aiceo_memory', 'aiceo_health_scores', 'aiceo_ops_checks', 'aiceo_incidents', 'aiceo_dev_backlog'] as $t) $this->db->query("DELETE FROM {$t}");
        $this->db->query("DELETE FROM system_settings WHERE setting_key IN ('aiceo_auto', 'aiceo_paused')");
        if (class_exists('BrainConfig')) BrainConfig::reset();
    }

    public function testFullCycleIsSafeAndResilient(): void
    {
        $store = new AiCeoStore($this->db);
        // مصدر واقع (زي API مش بيرد): القسم null والباقي يكمل
        $t = microtime(true);
        $snap = (new PlatformSnapshot($this->db, ['revenue' => static function () { throw new RuntimeException('provider down'); }]))->collect();
        $this->assertSame(true, microtime(true) - $t < 20, 'snapshot must stay fast');
        $this->assertSame(null, $snap['revenue']);
        foreach (['brand', 'success', 'ops', 'finance', 'executive'] as $k) $this->assertNotNull($snap[$k], $k . ' section must load');
        $this->assertContains('Section revenue', array_column(array_filter((new ExecutiveIntel($this->db))->dataQuality($snap)['checks'], static fn ($c) => !$c['ok']), 'source'));

        // القرارات: وضع الموافقة مفيش حاجة بتتنفذ لوحدها
        $decisions = $store->sync($snap);
        $this->assertSame(true, count($decisions) > 0);
        $this->assertSame(0, $store->autoExecute());
        foreach ($store->list() as $d) $this->assertSame('proposed', $d['status'], $d['key']);
        // AUTO: منخفض الخطر بس، والإيقاف الطارئ بيوقف كله
        $store->setMode('auto', 1);
        $store->setPaused(true, 1, 'acceptance test');
        $this->assertSame(0, $store->autoExecute());
        $store->setPaused(false, 1, '');
        $store->autoExecute();
        foreach ($store->list() as $d) if ($d['status'] === 'approved') $this->assertSame('low', $d['risk'], $d['key']);

        // فحص الصفحات بمزود وهمي: صفحة واقعة ← حادثة ← قرار
        $ops = new OpsAdvisor($this->db, fn (string $u) => ['code' => str_ends_with($u, '/pricing') ? 503 : (preg_match('#/(dashboard|websites|social|crm|bookings|creative-studio|reputation/overview|ads|whatsapp-marketing|admin|api/brain)$#', $u) ? 302 : 200), 'ms' => 50]);
        $r = $ops->cycle([], [], [], []);
        $this->assertSame(1, $r['failed']);
        $this->assertSame(1, $r['opened']);
        $snap2 = (new PlatformSnapshot($this->db))->collect();
        $this->assertContains('ops_pages_down', array_column(PlatformDecisions::decide($snap2), 'key'));

        // التقارير التنفيذية ومؤشر الصحة
        $written = (new ExecutiveIntel($this->db))->writeReports($store, $snap2, $store->list(), strtotime('2026-11-02 09:00:00 UTC'));
        $this->assertSame(['daily', 'weekly'], $written);
        $this->assertSame(1, (int) $this->db->query('SELECT COUNT(*) AS n FROM aiceo_health_scores')[0]['n']);

        // قائمة التجهيز والمرحلة
        $act = (new AiCeoActivation($this->db))->report();
        $this->assertSame(12, count($act['checklist']));
        $this->assertSame(true, $act['stage']['stage'] <= 1, 'the cron has not run in the test database, so stage is at most data-complete');
        $this->assertSame(true, count($act['automation']) >= 3);

        // كل حاجة اتسجلت في سجل المراجعة
        $actions = array_column($store->activity(200), 'action');
        foreach (['mode_changed', 'emergency_stop', 'resumed', 'decision_proposed'] as $a) $this->assertContains($a, $actions);
    }
}
