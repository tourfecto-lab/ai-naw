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

    protected function setUp(): void
    {
        try {
            $app = dirname(__DIR__, 2) . '/app';
            if (!defined('APP_ENV')) foreach (['app.php', 'database.php', 'encryption.php'] as $cfg) if (file_exists($app . '/Config/' . $cfg)) require_once $app . '/Config/' . $cfg;
            if (!class_exists('Database')) require_once $app . '/Core/Database.php';
            if (!class_exists('Logger') && file_exists($app . '/Core/Logger.php')) require_once $app . '/Core/Logger.php';
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
        $this->db->query('DELETE FROM brain_decisions WHERE user_id IN (?, ?)', [self::USER_ID, self::OTHER_USER_ID]);
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
        $store = new BrainDecisionStore($this->db);
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
        $store = new BrainDecisionStore($this->db);
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

    public function testVanishedCauseResolvesOnlyWhenMeasurable(): void
    {
        $store = new BrainDecisionStore($this->db);
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
