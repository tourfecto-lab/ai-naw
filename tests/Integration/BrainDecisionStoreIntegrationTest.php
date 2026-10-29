<?php

/**
 * العقل الاستراتيجي: حفظ القرارات وعزل الشركات وحلقة القياس
 *   1) القرارات بتتحفظ لشركة واحدة بس، وشركة تانية ماتشوفهاش ولا تقدر تغيّرها.
 *   2) موافقة ← تنفيذ ← بعد 7 أيام بنقيس النتيجة، والتحسّن بيرفع وزن المجال.
 *   3) القرار اللي سببه اختفى من البيانات بيتقفل "اتحل"، ومش بيتقفل لو المصدر مش متاح.
 * محتاج الميجريشن 2026_10_29_000003_strategic_brain.sql (أو BrainSchema وقت التشغيل).
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/Services/Brain/BrainDecisionStore.php';

final class BrainDecisionStoreIntegrationTest extends TestCase
{
    private const USER_ID = 999820;
    private const OTHER_USER_ID = 999821;
    private const WEBSITE_ID = 999830;

    private ?Database $db = null;
    /** @var array<int,array> المهام والإشعارات اللي المنفّذ طلبها (من غير CRM حقيقي) */
    private array $tasks = [];
    private array $notices = [];

    private function store(): BrainDecisionStore
    {
        $exec = new BrainExecutor($this->db, function (int $uid, array $task) { $this->tasks[] = [$uid, $task]; }, function (int $uid, array $intent) { $this->notices[] = [$uid, $intent['action']]; });
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
            // جداول اللوحة التنفيذية مربوطة بـ users، فالمستخدمين التجريبيين لازم يكونوا موجودين
            foreach ([self::USER_ID, self::OTHER_USER_ID] as $uid) {
                $this->db->query("INSERT INTO users (id, email, password, company_name, created_at) VALUES (?, ?, 'x', 'Brain Test Travel', NOW()) ON DUPLICATE KEY UPDATE email = email", [$uid, 'brain-' . $uid . '@tourfecto.test']);
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
        $this->db->query('DELETE FROM brain_decisions WHERE user_id IN (?, ?)', [self::USER_ID, self::OTHER_USER_ID]);
        $this->db->query('DELETE FROM brain_settings WHERE user_id IN (?, ?)', [self::USER_ID, self::OTHER_USER_ID]);
        $this->db->query("DELETE FROM action_executions WHERE user_id IN (?, ?) AND source_type = 'brain'", [self::USER_ID, self::OTHER_USER_ID]);
        foreach (['ceo_risk_alerts', 'ceo_growth_opportunities', 'cc_ai_tasks'] as $t) {
            try { $this->db->query("DELETE FROM {$t} WHERE user_id IN (?, ?)", [self::USER_ID, self::OTHER_USER_ID]); } catch (Throwable $e) { /* الجدول اختياري */ }
        }
    }

    private function snapshot(int $unansweredNegative, ?array $reputation = null): array
    {
        return [
            'understanding' => ['profile_confirmed' => true, 'goal' => 'More bookings', 'audience' => 'x', 'offers' => 'y', 'markets' => ['countries' => ['UK']]],
            'scores' => ['seo_score' => 90.0],
            'reputation' => $reputation ?? ['reviews_30' => 9, 'unanswered_negative' => $unansweredNegative],
            'chat' => ['pending_approval' => 0], 'bookings' => ['last_90' => 10],
            'seo' => ['autopilot' => true, 'client_tasks' => 0, 'ai_questions_approved' => 5],
            'competitors' => ['tracked' => 1], 'social' => ['published_30' => 3, 'scheduled' => 1], 'growth' => ['focus_trips' => []],
        ];
    }

    public function testDecisionsAreIsolatedPerCompany(): void
    {
        $store = $this->store();
        $list = $store->sync(self::USER_ID, self::WEBSITE_ID, $this->snapshot(3));
        $this->assertSame(['reply_negative_reviews'], array_column($list, 'key'));
        $this->assertSame('proposed', $list[0]['status']);
        $this->assertSame(3.0, $list[0]['baseline']);

        $this->assertSame([], $store->list(self::OTHER_USER_ID, self::WEBSITE_ID));
        $this->expectException(InvalidArgumentException::class);
        $store->setStatus(self::OTHER_USER_ID, self::WEBSITE_ID, $list[0]['id'], 'dismissed', $this->snapshot(3));
    }

    public function testDoneDecisionIsMeasuredAndLearned(): void
    {
        $store = $this->store();
        $id = $store->sync(self::USER_ID, self::WEBSITE_ID, $this->snapshot(3))[0]['id'];
        $store->setStatus(self::USER_ID, self::WEBSITE_ID, $id, 'approved', $this->snapshot(3));
        $store->setStatus(self::USER_ID, self::WEBSITE_ID, $id, 'done', $this->snapshot(3));

        // قبل 7 أيام: لسه مفيش نتيجة
        $row = $store->sync(self::USER_ID, self::WEBSITE_ID, $this->snapshot(1))[0];
        $this->assertSame('done', $row['status']);
        $this->assertNull($row['outcome']);

        $this->db->query('UPDATE brain_decisions SET done_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 8 DAY) WHERE id = ?', [$id]);
        $row = $store->sync(self::USER_ID, self::WEBSITE_ID, $this->snapshot(1))[0];
        $this->assertSame('improved', $row['outcome']);
        $this->assertSame(1.0, $row['result']);
        $this->assertSame(1.1, $store->learnedWeights(self::USER_ID, self::WEBSITE_ID)['reputation']);
        $this->assertSame(['total' => 1, 'completed' => 1, 'improved' => 1, 'worse' => 0, 'in_progress' => 0], $store->results(self::USER_ID, self::WEBSITE_ID));
    }

    public function testApprovalCreatesOneTaskAndExecutiveRisk(): void
    {
        $store = $this->store();
        $id = $store->sync(self::USER_ID, self::WEBSITE_ID, $this->snapshot(3))[0]['id'];
        $store->setStatus(self::USER_ID, self::WEBSITE_ID, $id, 'approved', $this->snapshot(3));
        $this->assertCount(1, $this->tasks);
        $this->assertSame(self::USER_ID, $this->tasks[0][0]);
        $this->assertSame('high', $this->tasks[0][1]['priority']);
        $this->assertCount(1, $this->notices); // أثر عالي = إشعار
        $this->assertNotEmpty($this->db->query('SELECT id FROM ceo_risk_alerts WHERE user_id = ? AND source_module = ?', [self::USER_ID, 'brain']));

        // نفس القرار مايتنفّذش مرتين خلال 30 يوم
        $store->setStatus(self::USER_ID, self::WEBSITE_ID, $id, 'proposed', $this->snapshot(3));
        $store->setStatus(self::USER_ID, self::WEBSITE_ID, $id, 'approved', $this->snapshot(3));
        $this->assertCount(1, $this->tasks);
    }

    public function testAutonomyApprovesAndExecutesOnlyForThatCompany(): void
    {
        $store = $this->store();
        $store->setAutonomy(self::USER_ID, true);
        $this->assertSame(true, $store->autonomy(self::USER_ID));
        $this->assertSame(false, $store->autonomy(self::OTHER_USER_ID));

        $row = $store->sync(self::USER_ID, self::WEBSITE_ID, $this->snapshot(2))[0];
        $this->assertSame('approved', $row['status']);
        $this->assertCount(1, $this->tasks);

        $other = $store->sync(self::OTHER_USER_ID, self::WEBSITE_ID, $this->snapshot(2))[0];
        $this->assertSame('proposed', $other['status']);
        $this->assertCount(1, $this->tasks);
    }

    public function testVanishedCauseResolvesOnlyWhenMeasurable(): void
    {
        $store = $this->store();
        $store->sync(self::USER_ID, self::WEBSITE_ID, $this->snapshot(2));

        // مصدر التقييمات مش متاح: القرار يفضل مفتوح
        $snapshot = $this->snapshot(0);
        $snapshot['reputation'] = null;
        $this->assertSame('proposed', $store->sync(self::USER_ID, self::WEBSITE_ID, $snapshot)[0]['status']);

        // المصدر متاح والتقييمات اترد عليها: اتحل واتحسن
        $row = $store->sync(self::USER_ID, self::WEBSITE_ID, $this->snapshot(0))[0];
        $this->assertSame('resolved', $row['status']);
        $this->assertSame('improved', $row['outcome']);
    }
}
