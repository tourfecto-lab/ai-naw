<?php

/**
 * العقل الاستراتيجي: الخطة الأسبوعية وتقدم الأهداف والملخص (منطق نقي)
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/Services/Brain/BrainPlanner.php';
require_once __DIR__ . '/../../app/Services/Brain/BrainDigest.php';

final class BrainPlannerTest extends TestCase
{
    private function d(string $key, string $area, float $priority, string $status = 'proposed', bool $spend = false): array
    {
        static $id = 0;
        return ['id' => ++$id, 'key' => $key, 'area' => $area, 'priority' => $priority, 'status' => $status, 'requires_spend' => $spend, 'title' => ['ar' => 'ع ' . $key, 'en' => 'T ' . $key]];
    }

    private function goal(array $o = []): array
    {
        return $o + ['metric_key' => 'bookings.last_30', 'direction' => 'up', 'target_value' => 20, 'baseline_value' => 10, 'created_at' => '2026-10-01 00:00:00', 'deadline' => '2026-10-31', 'status' => 'active'];
    }

    public function testGoalAchievedWhenTargetReached(): void
    {
        $p = BrainPlanner::goalProgress($this->goal(), 22.0, strtotime('2026-10-10 UTC'));
        $this->assertSame('achieved', $p['status']);
        $this->assertSame(100.0, $p['pct']);
    }

    public function testGoalBehindWhenProgressLagsTime(): void
    {
        // نص المدة عدّى والتقدم 10%
        $p = BrainPlanner::goalProgress($this->goal(), 11.0, strtotime('2026-10-16 UTC'));
        $this->assertSame('behind', $p['status']);
        $this->assertSame(10.0, $p['pct']);
        $this->assertGreaterThan(0, $p['needed_per_week']);
    }

    public function testGoalOnTrackAndAhead(): void
    {
        $now = strtotime('2026-10-16 UTC');
        $this->assertSame('on_track', BrainPlanner::goalProgress($this->goal(), 14.5, $now)['status']);
        $this->assertSame('ahead', BrainPlanner::goalProgress($this->goal(), 17.0, $now)['status']);
    }

    public function testGoalMissedAfterDeadline(): void
    {
        $this->assertSame('missed', BrainPlanner::goalProgress($this->goal(), 12.0, strtotime('2026-11-05 UTC'))['status']);
    }

    public function testDownwardGoal(): void
    {
        $g = $this->goal(['metric_key' => 'reputation.unanswered_negative', 'direction' => 'down', 'baseline_value' => 10, 'target_value' => 2]);
        $this->assertSame('achieved', BrainPlanner::goalProgress($g, 2.0, strtotime('2026-10-10 UTC'))['status']);
        $p = BrainPlanner::goalProgress($g, 6.0, strtotime('2026-10-16 UTC'));
        $this->assertSame(50.0, $p['pct']);
        $this->assertSame('on_track', $p['status']);
    }

    public function testUnmeasuredGoalIsUnknown(): void
    {
        $p = BrainPlanner::goalProgress($this->goal(), null, strtotime('2026-10-10 UTC'));
        $this->assertSame('unknown', $p['status']);
        $this->assertNull($p['pct']);
    }

    public function testGoalAreasBoostBehindMore(): void
    {
        $w = BrainPlanner::goalAreas([
            ['status' => 'active', 'metric_key' => 'reputation.avg_rating', 'progress' => ['status' => 'behind']],
            ['status' => 'active', 'metric_key' => 'scores.seo_score', 'progress' => ['status' => 'on_track']],
            ['status' => 'achieved', 'metric_key' => 'social.published_30', 'progress' => ['status' => 'achieved']],
        ]);
        $this->assertSame(1.35, $w['reputation']);
        $this->assertSame(1.2, $w['seo']);
        $this->assertArrayNotHasKey('social', $w);
    }

    public function testPlanRespectsCapacityAndDefersTheRest(): void
    {
        $ds = [$this->d('a', 'seo', 9), $this->d('b', 'social', 8), $this->d('c', 'whatsapp', 7), $this->d('d', 'ads', 6)];
        $plan = BrainPlanner::weeklyPlan($ds, [], [], 6);
        $this->assertSame(['a', 'b'], array_column($plan['items'], 'key'));
        $this->assertSame(6, $plan['used']);
        // اللي زيادة بيتأجل مع السبب حتى لو جهده صغير بعد ما الطاقة خلصت
        $this->assertSame(['capacity', 'capacity'], array_column(array_filter($plan['deferred'], static fn ($x) => in_array($x['key'], ['c', 'd'], true)), 'reason'));
    }

    public function testInProgressComesFirstAndGoalBoostReorders(): void
    {
        $ds = [$this->d('seo1', 'seo', 5), $this->d('rep1', 'reputation', 4.5), $this->d('wip', 'ads', 1, 'approved')];
        $plan = BrainPlanner::weeklyPlan($ds, ['reputation' => 1.35], [], 20);
        $this->assertSame(['wip', 'rep1', 'seo1'], array_column($plan['items'], 'key'));
        $this->assertTrue($plan['items'][1]['goal_aligned']);
    }

    public function testFirstItemAlwaysFitsEvenIfBiggerThanCapacity(): void
    {
        $plan = BrainPlanner::weeklyPlan([$this->d('big', 'seo', 5)], [], [], 1);
        $this->assertCount(1, $plan['items']);
    }

    public function testRememberedDecisionsAreExcluded(): void
    {
        $plan = BrainPlanner::weeklyPlan([$this->d('x', 'seo', 5), $this->d('y', 'seo', 4)], [], ['x'], 6);
        $this->assertSame(['y'], array_column($plan['items'], 'key'));
    }

    public function testEverythingWaitsForProfileConfirmationExceptDataAndMarket(): void
    {
        $ds = [$this->d('confirm_profile', 'data', 10), $this->d('seo_autopilot', 'seo', 9), $this->d('fx_eur_usd', 'market', 3)];
        $plan = BrainPlanner::weeklyPlan($ds, [], [], 20);
        $this->assertSame(['confirm_profile', 'fx_eur_usd'], array_column($plan['items'], 'key'));
        $this->assertSame('blocked', $plan['deferred'][0]['reason']);
        $this->assertSame(['confirm_profile'], $plan['deferred'][0]['blocked_by']);
    }

    public function testClosedDecisionsNeverEnterThePlan(): void
    {
        $plan = BrainPlanner::weeklyPlan([$this->d('a', 'seo', 9, 'done'), $this->d('b', 'seo', 8, 'dismissed'), $this->d('c', 'seo', 7, 'resolved')], [], [], 20);
        $this->assertSame([], $plan['items']);
    }

    public function testCapacityIsClamped(): void
    {
        $this->assertSame(BrainPlanner::DEFAULT_CAPACITY, BrainPlanner::capacity(0));
        $this->assertSame(BrainPlanner::MAX_CAPACITY, BrainPlanner::capacity(999));
        $this->assertSame(1, BrainPlanner::capacity(-5) );
    }

    public function testDigestIsSkippedWhenThereIsNothingToSay(): void
    {
        $this->assertNull(BrainDigest::build('X', ['items' => []], [], [], []));
    }

    public function testDigestEscapesAndLocalises(): void
    {
        $ds = [$this->d('a', 'seo', 9)];
        $ds[0]['title'] = ['ar' => '<script>x</script>', 'en' => 'Fix <b>site</b>'];
        $plan = BrainPlanner::weeklyPlan($ds, [], [], 6);
        $mail = BrainDigest::build('A&B', $plan, [], ['completed' => 3, 'improved' => 2], $ds, 'en', 'https://example.test/brain');
        $this->assertNotNull($mail);
        $this->assertStringNotContainsString('<script>', $mail['html']);
        $this->assertStringContainsString('Fix &lt;b&gt;site&lt;/b&gt;', $mail['html']);
        $this->assertStringContainsString('A&amp;B', $mail['html']);
        $this->assertStringContainsString('2 of 3', $mail['html']);
        $ar = BrainDigest::build('', $plan, [], [], $ds, 'ar');
        $this->assertStringContainsString('dir="rtl"', $ar['html']);
    }
}
