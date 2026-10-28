<?php

/**
 * Tourfecto - Action Center Executor Test (المنفّذ الموحّد) v1.0.0
 * اختبارات منطق تحويل توصيات Action Center (Competitor + CEO + Marketing)
 * لإجراءات فعلية - offline بالكامل (من غير قاعدة بيانات حقيقية) باستخدام
 * FakeDatabase وحقن دوال taskCreator/notifier وهمية.
 *
 * التشغيل: php tests/Unit/ActionCenterExecutorTest.php
 * @version 1.0.0
 * @date 2026-08-20
 */

require_once __DIR__ . '/../../app/Core/Database.php';
require_once __DIR__ . '/../../app/Services/Execution/ActionExecutor.php';
require_once __DIR__ . '/../../app/Services/ActionCenter/ActionCenterService.php';
require_once __DIR__ . '/../../app/Services/ActionCenter/ActionCenterExecutor.php';
require_once __DIR__ . '/../../app/Services/ActionCenter/ActionCenterExecutionService.php';

/**
 * نسخة وهمية من Database بترجع بيانات ثابتة حسب اسم الجدول،
 * وبتقبل exec() بصمت - من غير أي اتصال بـ PDO.
 */
class FakeDbAcExecutor extends Database
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

/** نسخة من ActionCenterService بتفّلتر getActionItems لإرجاع بيانات ثابتة. */
class FakeActionCenterService extends ActionCenterService
{
    public $items = [];

    public function getActionItems(Database $db, int $userId, ?int $websiteId = null): array
    {
        return $this->items;
    }
}

class ActionCenterExecutorTest
{
    private $passed = 0;
    private $failed = 0;

    public function runAll(): void
    {
        $this->testMissingTablesSafeEmpty();
        $this->testMappingCompetitorThreat();
        $this->testMappingCeoAdvisorHigh();
        $this->testMappingMarketingMedium();
        $this->testFiltersNonExecutableSources();
        $this->testExecuteCreatesTasksAndNotifiesHigh();
        $this->testExecuteSkipsDuplicate();
        $this->testExecuteDryRunWritesNothing();
        $this->testHistoryReadsActionExecutions();
        $this->printSummary();
    }

    private function map(array $item): array
    {
        $ref = new ReflectionMethod(ActionCenterExecutionService::class, 'mapItemToAction');
        $ref->setAccessible(true);
        return $ref->invoke(new ActionCenterExecutionService(), $item);
    }

    private function testMissingTablesSafeEmpty(): void
    {
        $fake = new FakeActionCenterService();
        $fake->items = [];
        $service = new ActionCenterExecutionService($fake);
        $actions = $service->getNextBestActions(new FakeDbAcExecutor(), 1);
        $this->assertTrue(is_array($actions) && $actions === [], 'no items => safe empty list');
    }

    private function testMappingCompetitorThreat(): void
    {
        $action = $this->map([
            'source' => 'competitor', 'id' => 7, 'title' => 'منافس أطلق عرض جديد',
            'description' => 'المنافس X أطلق خصم 30%', 'category' => 'threat',
            'priority' => 'high', 'status' => 'new', 'created_at' => '2026-08-20 10:00:00',
            'action_type' => 'competitor_insight', 'action_hint' => null,
        ]);

        $this->assertTrue($action['source_type'] === 'competitor', 'source_type = competitor');
        $this->assertTrue($action['source_category'] === 'threat', 'source_category = threat');
        $this->assertTrue($action['affected_area'] === 'competitor:7', 'affected_area = competitor:7');
        $this->assertTrue($action['affected_area_id'] === 7, 'affected_area_id carries insight id');
        $this->assertTrue($action['severity'] === 'high', 'high priority => severity high');
        $this->assertTrue($action['period'] === '2026-08-20', 'period from created_at');
    }

    private function testMappingCeoAdvisorHigh(): void
    {
        $action = $this->map([
            'source' => 'ceo_advisor', 'id' => 3, 'title' => 'خطر سيولة نقدية',
            'description' => 'التدفق النقدي أقل من الحد الآمن', 'category' => 'risk',
            'priority' => 'critical', 'status' => 'open', 'created_at' => '2026-08-19 08:00:00',
            'action_type' => 'risk_alert', 'action_hint' => 'POST /api/executive/alerts/3/read',
        ]);

        $this->assertTrue($action['severity'] === 'high', 'critical => severity high');
        $this->assertTrue($action['recommended_action'] === 'POST /api/executive/alerts/3/read', 'hint carried');
    }

    private function testMappingMarketingMedium(): void
    {
        $action = $this->map([
            'source' => 'marketing', 'id' => 11, 'title' => 'نفّذ المحتوى: عرض الصيف',
            'description' => 'نص إعلان جاهز', 'category' => 'marketing',
            'priority' => 'medium', 'status' => 'new', 'created_at' => '2026-08-18 12:00:00',
            'action_type' => 'marketing_output', 'action_hint' => null,
        ]);

        $this->assertTrue($action['severity'] === 'medium', 'medium priority => severity medium');
    }

    private function testFiltersNonExecutableSources(): void
    {
        $fake = new FakeActionCenterService();
        $fake->items = [
            ['source' => 'website_optimizer', 'id' => 1, 'title' => 'fix', 'category' => 'seo', 'priority' => 'medium', 'status' => 'pending', 'created_at' => '2026-08-20 00:00:00', 'action_type' => 'website_optimizer_fix'],
            ['source' => 'manual', 'id' => 2, 'title' => 'task', 'category' => 'general', 'priority' => 'medium', 'status' => 'open', 'created_at' => '2026-08-20 00:00:00', 'action_type' => 'manual_task'],
            ['source' => 'outreach', 'id' => 3, 'title' => 'draft', 'category' => 'outreach', 'priority' => 'medium', 'status' => 'draft', 'created_at' => '2026-08-20 00:00:00', 'action_type' => 'outreach_email_draft'],
            ['source' => 'competitor', 'id' => 4, 'title' => 'threat', 'category' => 'threat', 'priority' => 'high', 'status' => 'new', 'created_at' => '2026-08-20 00:00:00', 'action_type' => 'competitor_insight'],
        ];

        $service = new ActionCenterExecutionService($fake);
        $actions = $service->getNextBestActions(new FakeDbAcExecutor(), 1);
        $sources = array_column($actions, 'source_type');

        $this->assertTrue($sources === ['competitor'], 'only executable sources pass the filter');
    }

    private function testExecuteCreatesTasksAndNotifiesHigh(): void
    {
        $db = new FakeDbAcExecutor();
        $db->rows = ['action_executions' => []];

        $createdTasks = [];
        $notified = [];
        $executor = new ActionCenterExecutor(
            $db,
            function ($userId, $payload) use (&$createdTasks) {
                $createdTasks[] = [$userId, $payload];
            },
            function ($userId, $intent) use (&$notified) {
                $notified[] = [$userId, $intent];
            }
        );

        $summary = $executor->executeActions(1, [[
            'action' => 'منافس أطلق عرض جديد',
            'source_type' => 'competitor',
            'source_category' => 'threat',
            'affected_area' => 'competitor:7',
            'affected_area_id' => 7,
            'severity' => 'high',
            'confidence' => 'high',
            'reason' => 'المنافس X خصم 30%',
        ]]);

        $this->assertTrue($summary['planned'] === 1, 'planned = 1');
        $this->assertTrue($summary['tasks_created'] === 1, 'one task created');
        $this->assertTrue($summary['notifications_sent'] === 1, 'high severity => notification');
        $this->assertTrue(count($createdTasks) === 1, 'taskCreator called once');
        $this->assertTrue(strpos($createdTasks[0][1]['title'], 'إجراء: ') !== false, 'task title has prefix');
        $this->assertTrue(count($notified) === 1, 'notifier called once');
        // recordExecution + afterExecution (update ci_insights)
        $this->assertTrue(count($db->execs) === 2, 'record + mark competitor insight actioned');

        $this->assertTrue(
            isset($db->execs[1][0]) && stripos($db->execs[1][0], 'ci_insights') !== false && stripos($db->execs[1][0], 'actioned') !== false,
            'afterExecution marks ci_insights actioned'
        );
    }

    private function testExecuteSkipsDuplicate(): void
    {
        $db = new FakeDbAcExecutor();
        $db->rows = ['action_executions' => [['id' => 99]]];

        $createdTasks = [];
        $executor = new ActionCenterExecutor(
            $db,
            function ($userId, $payload) use (&$createdTasks) {
                $createdTasks[] = $payload;
            }
        );

        $summary = $executor->executeActions(1, [[
            'action' => 'Threat', 'source_type' => 'competitor', 'source_category' => 'threat',
            'affected_area' => 'competitor:7', 'severity' => 'high', 'confidence' => 'high',
        ]]);

        $this->assertTrue($summary['skipped'] === 1, 'duplicate skipped');
        $this->assertTrue($summary['executed'] === 0, 'nothing executed');
        $this->assertTrue(count($createdTasks) === 0, 'no task for duplicate');
    }

    private function testExecuteDryRunWritesNothing(): void
    {
        $db = new FakeDbAcExecutor();
        $db->rows = ['action_executions' => []];

        $createdTasks = [];
        $executor = new ActionCenterExecutor(
            $db,
            function ($userId, $payload) use (&$createdTasks) {
                $createdTasks[] = $payload;
            }
        );

        $summary = $executor->executeActions(1, [[
            'action' => 'Threat', 'source_type' => 'competitor', 'source_category' => 'threat',
            'affected_area' => 'competitor:7', 'severity' => 'high', 'confidence' => 'high',
        ]], ['dry_run' => true]);

        $this->assertTrue($summary['executed'] === 1, 'dry_run counts planned execution');
        $this->assertTrue(count($createdTasks) === 0, 'dry_run creates no task');
        $this->assertTrue(count($db->execs) === 0, 'dry_run writes nothing');
    }

    private function testHistoryReadsActionExecutions(): void
    {
        $db = new FakeDbAcExecutor();
        $db->rows = ['action_executions' => [['id' => 1]]];
        $executor = new ActionCenterExecutor($db);
        $history = $executor->history(1);
        $this->assertTrue(count($history) === 1, 'history reads action_executions');
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
        echo "Action Center Executor Summary\n";
        echo str_repeat('=', 50) . "\n";
        echo "  Passed: {$this->passed}\n";
        echo "  Failed: {$this->failed}\n";
        echo "  Total: {$total}\n";
        echo "  Success Rate: {$percentage}%\n";
        echo str_repeat('=', 50) . "\n\n";
    }
}

if (basename(__FILE__) === basename($_SERVER['PHP_SELF'] ?? '')) {
    $test = new ActionCenterExecutorTest();
    $test->runAll();
}
