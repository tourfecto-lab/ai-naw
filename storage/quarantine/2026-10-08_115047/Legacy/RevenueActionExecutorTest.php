<?php

/**
 * Tourfecto - Revenue Action Executor Test (طبقة التنفيذ) v1.0.0
 * اختبارات منطق تحويل توصيات الإيرادات إلى إجراءات فعلية - offline بالكامل
 * (من غير قاعدة بيانات حقيقية ولا CRM/Notification) باستخدام FakeDatabase
 * وحقن دوال taskCreator/notifier وهمية.
 *
 * التشغيل: php tests/Unit/RevenueActionExecutorTest.php
 * @version 1.0.0
 * @date 2026-08-20
 */

require_once __DIR__ . '/../../app/Core/Database.php';
require_once __DIR__ . '/../../app/Services/RevenueIntelligence/RevenueActionExecutor.php';

/**
 * نسخة وهمية من Database بترجع بيانات ثابتة حسب اسم الجدول،
 * وبتقبل exec() بصمت - من غير أي اتصال بـ PDO.
 */
class FakeDbExecutor extends Database
{
    public $rows = [];
    public $execs = [];

    public function __construct()
    {
        // من غير parent::__construct() - مفيش اتصال حقيقي
    }

    public function query(string $sql, array $params = [], int $fetchMode = PDO::FETCH_ASSOC)
    {
        foreach (array_keys($this->rows) as $table) {
            if (stripos($sql, $table) !== false) {
                return $this->rows[$table];
            }
        }
        return [];
    }

    public function exec(string $sql, array $params = []): bool
    {
        $this->execs[] = [$sql, $params];
        return true;
    }
}

class RevenueActionExecutorTest
{
    private $passed = 0;
    private $failed = 0;

    public function runAll(): void
    {
        echo "\nRevenue Action Executor Tests\n";
        echo "=============================\n";

        $this->testPlanHighSeverityCustomerRisk();
        $this->testPlanOpportunityNoSeverity();
        $this->testPlanAnomalyWithPeriod();
        $this->testPlanLowSeverityRisk();
        $this->testPlanActionsCount();
        $this->testAlreadyExecutedTrue();
        $this->testAlreadyExecutedFalse();
        $this->testExecuteCreatesTaskAndNotifies();
        $this->testExecuteSkipsDuplicate();
        $this->testExecuteDryRunWritesNothing();

        $this->printSummary();
    }

    private function testPlanHighSeverityCustomerRisk(): void
    {
        $executor = new RevenueActionExecutor(new FakeDbExecutor());
        $intent = $executor->planOne([
            'action' => 'Re-engage Customer',
            'source_type' => 'risk',
            'source_category' => 'customer_inactivity',
            'affected_area' => 'customer:12',
            'severity' => 'high',
            'confidence' => 'high',
            'reason' => 'عميل غير نشط 45 يومًا',
            'recommended_action' => 'تواصل شخصي للاستعادة',
        ]);

        $this->assertTrue($intent['action_key'] === 'risk:customer_inactivity:customer:12', 'action_key = type:category:area');
        $this->assertTrue($intent['priority'] === 'high', 'high severity => priority high');
        $this->assertTrue(strtotime($intent['due_date']) <= strtotime('+2 days'), 'high severity => due within 2 days');
        $this->assertTrue($intent['notify'] === true, 'high severity => notify');
        $this->assertTrue($intent['task']['related_type'] === 'crm_contacts', 'customer area => crm_contacts task');
        $this->assertTrue($intent['task']['related_id'] === 12, 'customer area => related_id = 12');
        $this->assertTrue(strpos($intent['task']['title'], 'إجراء إيرادات') !== false, 'task title has revenue prefix');
        $this->assertTrue(strpos($intent['task']['description'], 'تواصل شخصي') !== false, 'description contains recommended_action');
    }

    private function testPlanOpportunityNoSeverity(): void
    {
        $executor = new RevenueActionExecutor(new FakeDbExecutor());
        $intent = $executor->planOne([
            'action' => 'Upsell',
            'source_type' => 'opportunity',
            'source_category' => 'upsell_growing_customer',
            'affected_area' => 'customer:7',
            'confidence' => 'medium',
            'reason' => 'نمو قوي',
        ]);

        $this->assertTrue($intent['notify'] === false, 'opportunity (no severity) => no notify');
        $this->assertTrue($intent['priority'] === 'medium', 'medium confidence => priority medium');
        $this->assertTrue($intent['task']['related_type'] === 'crm_contacts', 'still linked to contact');
    }

    private function testPlanAnomalyWithPeriod(): void
    {
        $executor = new RevenueActionExecutor(new FakeDbExecutor());
        $a = $executor->planOne([
            'action' => 'Investigate Revenue Drop',
            'source_type' => 'anomaly',
            'source_category' => 'sudden_drop',
            'affected_area' => 'daily_revenue',
            'period' => '2026-08-19',
            'severity' => 'medium',
            'confidence' => 'medium',
            'reason' => 'Revenue on 2026-08-19 was 180, notably below expected.',
            'recommended_action' => 'استعراض التحويلات',
        ]);
        $b = $executor->planOne([
            'action' => 'Investigate Revenue Drop',
            'source_type' => 'anomaly',
            'source_category' => 'sudden_drop',
            'affected_area' => 'daily_revenue',
            'period' => '2026-08-18',
            'severity' => 'medium',
            'confidence' => 'medium',
            'reason' => 'Revenue on 2026-08-18 was 180, notably below expected.',
            'recommended_action' => 'استعراض التحويلات',
        ]);
        $c = $executor->planOne([
            'action' => 'Investigate Revenue Drop',
            'source_type' => 'anomaly',
            'source_category' => 'sudden_drop',
            'affected_area' => 'daily_revenue',
            'severity' => 'medium',
            'confidence' => 'medium',
            'reason' => 'بدون فترة',
            'recommended_action' => 'x',
        ]);

        $this->assertTrue($a['action_key'] === 'anomaly:sudden_drop:daily_revenue:2026-08-19', 'period appended to fingerprint');
        $this->assertTrue($a['action_key'] !== $b['action_key'], 'different periods => different fingerprints');
        $this->assertTrue($c['action_key'] === 'anomaly:sudden_drop:daily_revenue', 'no period => no suffix');
    }

    private function testPlanLowSeverityRisk(): void
    {
        $executor = new RevenueActionExecutor(new FakeDbExecutor());
        $intent = $executor->planOne([
            'action' => 'Follow-up',
            'source_type' => 'risk',
            'source_category' => 'stalled_deals',
            'affected_area' => 'pipeline',
            'severity' => 'low',
            'confidence' => 'medium',
            'reason' => 'صفقات متوقفة',
        ]);

        $this->assertTrue($intent['priority'] === 'low', 'low severity => priority low');
        $this->assertTrue($intent['task']['related_type'] === null, 'pipeline area => generic task');
        $this->assertTrue($intent['task']['related_id'] === null, 'no related id');
    }

    private function testPlanActionsCount(): void
    {
        $executor = new RevenueActionExecutor(new FakeDbExecutor());
        $intents = $executor->planActions([['action' => 'A'], ['action' => 'B'], ['action' => 'C']]);
        $this->assertTrue(count($intents) === 3, 'planActions maps every action');
    }

    private function testAlreadyExecutedTrue(): void
    {
        $db = new FakeDbExecutor();
        $db->rows = ['revai_action_executions' => [['id' => 1]]];
        $executor = new RevenueActionExecutor($db);
        $this->assertTrue($executor->alreadyExecuted(1, 'risk:x:all', 7) === true, 'existing row => already executed');
    }

    private function testAlreadyExecutedFalse(): void
    {
        $db = new FakeDbExecutor();
        $db->rows = ['revai_action_executions' => []];
        $executor = new RevenueActionExecutor($db);
        $this->assertTrue($executor->alreadyExecuted(1, 'risk:x:all', 7) === false, 'empty table => not executed');
    }

    private function testExecuteCreatesTaskAndNotifies(): void
    {
        $db = new FakeDbExecutor();
        $db->rows = ['revai_action_executions' => []];

        $createdTasks = [];
        $notified = [];
        $executor = new RevenueActionExecutor(
            $db,
            function ($userId, $payload) use (&$createdTasks) {
                $createdTasks[] = [$userId, $payload];
            },
            function ($userId, $intent) use (&$notified) {
                $notified[] = [$userId, $intent];
            }
        );

        $summary = $executor->executeActions(1, [[
            'action' => 'Re-engage Customer',
            'source_type' => 'risk',
            'source_category' => 'customer_inactivity',
            'affected_area' => 'customer:12',
            'severity' => 'high',
            'confidence' => 'high',
            'reason' => 'خطر',
            'recommended_action' => 'تواصل',
        ]]);

        $this->assertTrue($summary['planned'] === 1, 'planned = 1');
        $this->assertTrue($summary['executed'] === 1, 'executed = 1');
        $this->assertTrue($summary['tasks_created'] === 1, 'one task created');
        $this->assertTrue($summary['notifications_sent'] === 1, 'high severity => one notification');
        $this->assertTrue(count($createdTasks) === 1, 'taskCreator called once');
        $this->assertTrue($createdTasks[0][1]['related_type'] === 'crm_contacts', 'task related to contact');
        $this->assertTrue($createdTasks[0][1]['related_id'] === 12, 'task related_id = 12');
        $this->assertTrue($createdTasks[0][1]['priority'] === 'high', 'task priority high');
        $this->assertTrue(count($notified) === 1, 'notifier called once');
        $this->assertTrue(count($db->execs) === 2, 'recordExecution + markInsightActioned execs');
    }

    private function testExecuteSkipsDuplicate(): void
    {
        $db = new FakeDbExecutor();
        $db->rows = ['revai_action_executions' => [['id' => 99]]];

        $createdTasks = [];
        $executor = new RevenueActionExecutor(
            $db,
            function ($userId, $payload) use (&$createdTasks) {
                $createdTasks[] = $payload;
            }
        );

        $summary = $executor->executeActions(1, [[
            'action' => 'Re-engage Customer',
            'source_type' => 'risk',
            'source_category' => 'customer_inactivity',
            'affected_area' => 'customer:12',
            'severity' => 'high',
            'confidence' => 'high',
        ]]);

        $this->assertTrue($summary['skipped'] === 1, 'duplicate action skipped');
        $this->assertTrue($summary['executed'] === 0, 'nothing executed');
        $this->assertTrue(count($createdTasks) === 0, 'no task created for duplicate');
    }

    private function testExecuteDryRunWritesNothing(): void
    {
        $db = new FakeDbExecutor();
        $db->rows = ['revai_action_executions' => []];

        $createdTasks = [];
        $executor = new RevenueActionExecutor(
            $db,
            function ($userId, $payload) use (&$createdTasks) {
                $createdTasks[] = $payload;
            }
        );

        $summary = $executor->executeActions(1, [[
            'action' => 'Re-engage Customer',
            'source_type' => 'risk',
            'source_category' => 'customer_inactivity',
            'affected_area' => 'customer:12',
            'severity' => 'high',
            'confidence' => 'high',
        ]], ['dry_run' => true]);

        $this->assertTrue($summary['executed'] === 1, 'dry_run counts planned execution');
        $this->assertTrue(count($createdTasks) === 0, 'dry_run creates no task');
        $this->assertTrue(count($db->execs) === 0, 'dry_run writes nothing');
    }

    private function assertTrue(bool $condition, string $message): void
    {
        if ($condition) {
            echo "    [PASS] {$message}\n";
            $this->passed++;
        } else {
            echo "    [FAIL] {$message}\n";
            $this->failed++;
        }
    }

    private function printSummary(): void
    {
        $total = $this->passed + $this->failed;
        $percentage = $total > 0 ? round(($this->passed / $total) * 100, 2) : 0;

        echo "\n" . str_repeat('=', 50) . "\n";
        echo "Revenue Action Executor Summary\n";
        echo str_repeat('=', 50) . "\n";
        echo "  Passed: {$this->passed}\n";
        echo "  Failed: {$this->failed}\n";
        echo "  Total: {$total}\n";
        echo "  Success Rate: {$percentage}%\n";
        echo str_repeat('=', 50) . "\n\n";
    }
}

if (basename(__FILE__) === basename($_SERVER['PHP_SELF'] ?? '')) {
    $test = new RevenueActionExecutorTest();
    $test->runAll();
}
