<?php

/**
 * العقل الاستراتيجي: الأهداف والسياسة والذاكرة والسجل (قاعدة بيانات)
 *   1) الهدف بيتحفظ بخط أساس من صورة الشركة، وبيتحقق من الاتجاه والميعاد والحد الأقصى، ومعزول لكل شركة.
 *   2) مجال مقفول (suggest) مابيتنفّذش فيه تلقائيًا حتى لو التشغيل الذاتي شغال.
 *   3) القرار المرفوض بسبب بيتفتكر ومايدخلش الخطة، و"بعد أسبوعين" بيرجع بعد المدة.
 *   4) كل موافقة/رفض/قياس بيتسجل في سجل التدقيق.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/Services/Brain/BrainDecisionStore.php';

final class BrainControlIntegrationTest extends TestCase
{
    private const USER_ID = 999840;
    private const OTHER_USER_ID = 999841;
    private const WEBSITE_ID = 999850;

    private ?Database $db = null;
    private array $tasks = [];

    private function store(): BrainDecisionStore
    {
        $exec = new BrainExecutor($this->db, function (int $uid, array $task) { $this->tasks[] = [$uid, $task]; }, function (int $uid, array $intent) { });
        return new BrainDecisionStore($this->db, $exec);
    }

    protected function setUp(): void
    {
        try {
            $app = dirname(__DIR__, 2) . '/app';
            if (!defined('APP_ENV')) foreach (['app.php', 'database.php', 'encryption.php'] as $cfg) if (file_exists($app . '/Config/' . $cfg)) require_once $app . '/Config/' . $cfg;
            if (!class_exists('Database')) require_once $app . '/Core/Database.php';
            if (!class_exists('Logger') && file_exists($app . '/Core/Logger.php')) require_once $app . '/Core/Logger.php';
            $this->db = Database::getInstance();
            BrainSchema::ensure($this->db);
            if (!BrainSchema::ensureExtras($this->db)) $this->markTestSkipped('جداول التحكم غير متاحة');
            foreach ([self::USER_ID, self::OTHER_USER_ID] as $uid) {
                $this->db->query("INSERT INTO users (id, email, password, company_name, created_at) VALUES (?, ?, 'x', 'Brain Control Travel', NOW()) ON DUPLICATE KEY UPDATE email = email", [$uid, 'brain-control-' . $uid . '@example.test']);
            }
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
        $in = [self::USER_ID, self::OTHER_USER_ID];
        foreach (['brain_decisions', 'brain_settings', 'brain_goals', 'brain_policy', 'brain_prefs', 'brain_memory', 'brain_audit'] as $t) {
            $this->db->query("DELETE FROM {$t} WHERE user_id IN (?, ?)", $in);
        }
        $this->db->query("DELETE FROM action_executions WHERE user_id IN (?, ?) AND source_type = 'brain'", $in);
        foreach (['ceo_risk_alerts', 'ceo_growth_opportunities', 'cc_ai_tasks'] as $t) {
            try { $this->db->query("DELETE FROM {$t} WHERE user_id IN (?, ?)", $in); } catch (Throwable $e) { /* اختياري */ }
        }
    }

    private function snapshot(int $unansweredNegative = 3, int $bookings = 10): array
    {
        return [
            'understanding' => ['profile_confirmed' => true, 'goal' => 'More bookings', 'audience' => 'x', 'offers' => 'y', 'markets' => ['countries' => ['UK']]],
            'scores' => ['seo_score' => 90.0],
            'reputation' => ['reviews_30' => 9, 'unanswered_negative' => $unansweredNegative, 'avg_rating' => 4.2],
            'chat' => ['pending_approval' => 0], 'bookings' => ['last_30' => $bookings, 'last_90' => 10],
            'seo' => ['autopilot' => true, 'client_tasks' => 0, 'ai_questions_approved' => 5],
            'competitors' => ['tracked' => 1], 'social' => ['published_30' => 3, 'scheduled' => 1], 'growth' => ['focus_trips' => []],
        ];
    }

    private function deadline(int $days = 30): string
    {
        return gmdate('Y-m-d', time() + $days * 86400);
    }

    public function testGoalIsSavedWithBaselineAndTracked(): void
    {
        $c = $this->store()->control();
        $id = $c->createGoal(self::USER_ID, self::WEBSITE_ID, 'bookings.last_30', '20', $this->deadline(), $this->snapshot(3, 10));
        $goals = $c->goals(self::USER_ID, self::WEBSITE_ID, $this->snapshot(3, 12));
        $this->assertCount(1, $goals);
        $this->assertSame($id, $goals[0]['id']);
        $this->assertSame(10.0, $goals[0]['baseline']);
        $this->assertSame(12.0, $goals[0]['progress']['current']);
        $this->assertSame(20.0, $goals[0]['target']);
        // شركة تانية ماتشوفش الهدف ولا تقدر تلغيه
        $this->assertSame([], $c->goals(self::OTHER_USER_ID, self::WEBSITE_ID, $this->snapshot()));
        $c->cancelGoal(self::OTHER_USER_ID, self::WEBSITE_ID, $id);
        $this->assertSame('active', $c->goals(self::USER_ID, self::WEBSITE_ID, $this->snapshot())[0]['status']);
    }

    public function testGoalAchievedIsClosedAndLogged(): void
    {
        $c = $this->store()->control();
        $c->createGoal(self::USER_ID, self::WEBSITE_ID, 'bookings.last_30', 15, $this->deadline(), $this->snapshot(3, 10));
        $goals = $c->goals(self::USER_ID, self::WEBSITE_ID, $this->snapshot(3, 16));
        $this->assertSame('achieved', $goals[0]['status']);
        $events = array_column($c->recent(self::USER_ID, self::WEBSITE_ID), 'event');
        $this->assertContains('goal_achieved', $events);
    }

    /** @dataProvider badGoals */
    public function testGoalValidation(string $metric, $target, int $days, string $message): void
    {
        $c = $this->store()->control();
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        $c->createGoal(self::USER_ID, self::WEBSITE_ID, $metric, $target, $this->deadline($days), $this->snapshot(3, 10));
    }

    public static function badGoals(): array
    {
        return [
            'unknown metric' => ['users.password', 5, 30, 'Unknown goal metric'],
            'target not higher' => ['bookings.last_30', 10, 30, 'higher'],
            'target not lower' => ['reputation.unanswered_negative', 5, 30, 'lower'],
            'not a number' => ['bookings.last_30', 'abc', 30, 'Invalid target'],
            'too far' => ['bookings.last_30', 20, 400, 'within the next year'],
            'in the past' => ['bookings.last_30', 20, -1, 'within the next year'],
            'metric not measured' => ['scores.seo_score_missing', 20, 30, 'Unknown goal metric'],
        ];
    }

    public function testUnmeasuredMetricAndLimits(): void
    {
        $c = $this->store()->control();
        $snap = $this->snapshot();
        unset($snap['bookings']);
        try { $c->createGoal(self::USER_ID, self::WEBSITE_ID, 'bookings.last_30', 20, $this->deadline(), $snap); $this->fail('expected'); }
        catch (InvalidArgumentException $e) { $this->assertStringContainsString('not measured', $e->getMessage()); }

        $c->createGoal(self::USER_ID, self::WEBSITE_ID, 'bookings.last_30', 20, $this->deadline(), $this->snapshot());
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('already have an active goal');
        $c->createGoal(self::USER_ID, self::WEBSITE_ID, 'bookings.last_30', 25, $this->deadline(), $this->snapshot());
    }

    public function testSuggestOnlyAreaIsNotAutoExecutedButOthersAre(): void
    {
        $store = $this->store();
        $store->setAutonomy(self::USER_ID, true);
        $store->control()->setAreaMode(self::USER_ID, 'reputation', 'suggest');
        $row = $store->sync(self::USER_ID, self::WEBSITE_ID, $this->snapshot(3))[0];
        $this->assertSame('reputation', $row['area']);
        $this->assertSame('proposed', $row['status']);
        $this->assertCount(0, $this->tasks);

        // لما نرجّعه auto، الدورة الجاية بتوافق وتنفّذ وتتسجل كموافقة تلقائية
        $store->control()->setAreaMode(self::USER_ID, 'reputation', 'auto');
        $row = $store->sync(self::USER_ID, self::WEBSITE_ID, $this->snapshot(3))[0];
        $this->assertSame('approved', $row['status']);
        $this->assertCount(1, $this->tasks);
        $this->assertContains('auto_approved', array_column($store->control()->recent(self::USER_ID, self::WEBSITE_ID), 'event'));
        // سياسة شركة مابتأثرش على شركة تانية
        $this->assertSame('auto', $store->control()->areaModes(self::OTHER_USER_ID)['reputation']);
    }

    public function testPolicyValidation(): void
    {
        $c = $this->store()->control();
        $this->expectException(InvalidArgumentException::class);
        $c->setAreaMode(self::USER_ID, 'payments', 'auto');
    }

    public function testDismissReasonIsRemembered(): void
    {
        $store = $this->store();
        $snap = $this->snapshot(3);
        $row = $store->sync(self::USER_ID, self::WEBSITE_ID, $snap)[0];
        $store->setStatus(self::USER_ID, self::WEBSITE_ID, $row['id'], 'dismissed', $snap, 'ar', 'not_relevant');
        $this->assertSame(['reply_negative_reviews'], $store->control()->excludedKeys(self::USER_ID, self::WEBSITE_ID));
        $this->assertSame([], $store->control()->excludedKeys(self::OTHER_USER_ID, self::WEBSITE_ID));

        // الرفض بسبب غير معروف مابيتسجلش في الذاكرة
        $store->setStatus(self::USER_ID, self::WEBSITE_ID, $row['id'], 'proposed', $snap);
        $this->assertSame([], $store->control()->excludedKeys(self::USER_ID, self::WEBSITE_ID));
        $store->setStatus(self::USER_ID, self::WEBSITE_ID, $row['id'], 'dismissed', $snap, 'ar', 'drop table');
        $this->assertSame([], $store->control()->excludedKeys(self::USER_ID, self::WEBSITE_ID));

        // "بعد أسبوعين": مستبعد دلوقتي، وبيرجع لما المدة تعدّي
        $store->setStatus(self::USER_ID, self::WEBSITE_ID, $row['id'], 'dismissed', $snap, 'ar', 'later');
        $this->assertSame(['reply_negative_reviews'], $store->control()->excludedKeys(self::USER_ID, self::WEBSITE_ID));
        $this->db->query('UPDATE brain_memory SET snooze_until = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 DAY) WHERE user_id = ?', [self::USER_ID]);
        $this->assertSame([], $store->control()->excludedKeys(self::USER_ID, self::WEBSITE_ID));
        $events = array_column($store->control()->recent(self::USER_ID, self::WEBSITE_ID), 'event');
        $this->assertContains('dismissed', $events);
        $this->assertContains('restored', $events);
    }

    public function testAutonomySkipsRememberedDecisions(): void
    {
        $store = $this->store();
        $snap = $this->snapshot(3);
        $row = $store->sync(self::USER_ID, self::WEBSITE_ID, $snap)[0];
        $store->setStatus(self::USER_ID, self::WEBSITE_ID, $row['id'], 'dismissed', $snap, 'ar', 'not_relevant');
        $store->setStatus(self::USER_ID, self::WEBSITE_ID, $row['id'], 'proposed', $snap); // رجّعه ومسح الذاكرة
        $store->control()->remember(self::USER_ID, self::WEBSITE_ID, 'reply_negative_reviews', 'not_relevant');
        $store->setAutonomy(self::USER_ID, true);
        $this->assertSame('proposed', $store->sync(self::USER_ID, self::WEBSITE_ID, $snap)[0]['status']);
        $this->assertCount(0, $this->tasks);
    }

    public function testPrefsAreClampedAndIsolated(): void
    {
        $c = $this->store()->control();
        $this->assertSame(['weekly_capacity' => 6, 'digest_enabled' => false, 'digest_sent_at' => null], $c->prefs(self::USER_ID));
        $p = $c->setPrefs(self::USER_ID, 500, true);
        $this->assertSame(BrainPlanner::MAX_CAPACITY, $p['weekly_capacity']);
        $this->assertTrue($p['digest_enabled']);
        $p = $c->setPrefs(self::USER_ID, null, false);
        $this->assertSame(BrainPlanner::MAX_CAPACITY, $p['weekly_capacity']);
        $this->assertFalse($p['digest_enabled']);
        $this->assertFalse($c->prefs(self::OTHER_USER_ID)['digest_enabled']);
    }

    public function testAuditRecordsApprovalAndMeasurementPerCompany(): void
    {
        $store = $this->store();
        $snap = $this->snapshot(3);
        $id = $store->sync(self::USER_ID, self::WEBSITE_ID, $snap)[0]['id'];
        $store->setStatus(self::USER_ID, self::WEBSITE_ID, $id, 'approved', $snap);
        $store->setStatus(self::USER_ID, self::WEBSITE_ID, $id, 'done', $snap);
        $this->db->query('UPDATE brain_decisions SET done_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 8 DAY) WHERE id = ?', [$id]);
        $store->sync(self::USER_ID, self::WEBSITE_ID, $this->snapshot(1));
        $events = array_column($store->control()->recent(self::USER_ID, self::WEBSITE_ID), 'event');
        foreach (['approved', 'marked_done', 'measured'] as $e) $this->assertContains($e, $events);
        $this->assertSame([], $store->control()->recent(self::OTHER_USER_ID, self::WEBSITE_ID));
    }
}
