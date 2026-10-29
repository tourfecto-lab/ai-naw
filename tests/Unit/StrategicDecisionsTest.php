<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/Services/Brain/StrategicDecisions.php';

/** قرارات العقل: مبنية على أرقام مقاسة بس، ومرتبة حسب الأثر والهدف ونتايج الشركة */
class StrategicDecisionsTest extends TestCase
{
    private function snapshot(array $over = []): array
    {
        return array_replace_recursive([
            'understanding' => ['profile_confirmed' => true, 'goal' => 'Grow visibility on Google', 'audience' => 'UK couples', 'offers' => 'Nile cruise from $410', 'markets' => ['countries' => ['UK']]],
            'scores' => ['seo_score' => 90.0],
            'reputation' => ['reviews_30' => 8, 'avg_rating' => 4.8, 'unanswered_negative' => 0],
            'chat' => ['messages_30' => 60, 'pending_approval' => 0],
            'bookings' => ['last_30' => 4, 'last_90' => 12],
            'seo' => ['autopilot' => true, 'client_tasks' => 0, 'ai_questions_approved' => 20],
            'competitors' => ['tracked' => 2],
            'social' => ['published_30' => 6, 'scheduled' => 3],
            'growth' => ['focus_trips' => []],
        ], $over);
    }

    private function keys(array $decisions): array
    {
        return array_column($decisions, 'key');
    }

    public function testHealthyCompanyGetsNoDecisions(): void
    {
        $this->assertSame([], StrategicDecisions::decide($this->snapshot()));
    }

    public function testUnconfirmedProfileComesFirst(): void
    {
        $d = StrategicDecisions::decide($this->snapshot(['understanding' => ['profile_confirmed' => false], 'chat' => ['pending_approval' => 3]]));
        $this->assertSame('confirm_profile', $d[0]['key']);
        $this->assertContains('approve_chat_replies', $this->keys($d));
    }

    public function testUnavailableSourcesProduceNoDecisions(): void
    {
        // مصدر مش متاح = null: مفيش قرار مبني على رقم مش مقاس
        $d = StrategicDecisions::decide($this->snapshot(['reputation' => null, 'chat' => null, 'social' => null]));
        $this->assertSame([], $d);
    }

    public function testEvidenceAndMetricAreRecorded(): void
    {
        $d = StrategicDecisions::decide($this->snapshot(['reputation' => ['unanswered_negative' => 2]]));
        $this->assertSame('reply_negative_reviews', $d[0]['key']);
        $this->assertStringContainsString('2', $d[0]['evidence'][0]['en']);
        $this->assertSame(['key' => 'reputation.unanswered_negative', 'value' => 2.0, 'direction' => 'down'], $d[0]['metric']);
    }

    public function testGoalChangesPriority(): void
    {
        $over = ['seo' => ['autopilot' => false], 'social' => ['published_30' => 0, 'scheduled' => 0]];
        $visibility = StrategicDecisions::decide($this->snapshot($over + ['understanding' => ['goal' => 'Rank higher on Google']]));
        $bookings = StrategicDecisions::decide($this->snapshot($over + ['understanding' => ['goal' => 'More bookings and sales']]));
        $this->assertSame('seo_autopilot', $visibility[0]['key']);
        $this->assertSame('social_activity', $bookings[0]['key']);
    }

    public function testLearnedWeightsAreBounded(): void
    {
        $over = ['seo' => ['autopilot' => false], 'social' => ['published_30' => 0, 'scheduled' => 0], 'understanding' => ['goal' => '']];
        $d = StrategicDecisions::decide($this->snapshot($over), ['social' => 9.0, 'seo' => 0.1]);
        $by = array_column($d, 'priority', 'key');
        $this->assertSame(30.0, $by['social_activity']);   // 2 × 10 × 1.5 (الحد الأعلى)
        $this->assertSame(10.0, $by['seo_autopilot']);     // 2 × 10 × 0.5 (الحد الأدنى)
    }

    public function testTripSignalsBecomeDecisions(): void
    {
        $d = StrategicDecisions::decide($this->snapshot(['growth' => ['focus_trips' => [
            ['id' => 7, 'name' => 'Abu Simbel', 'reason' => 'needs_bookings_in_peak', 'recent' => 1, 'previous' => 0, 'upcoming' => 1],
            ['id' => 8, 'name' => 'Nile cruise', 'reason' => 'rising_demand', 'recent' => 9, 'previous' => 4, 'upcoming' => 5],
        ]]]));
        $this->assertSame(['promote_peak_7', 'scale_rising_8'], $this->keys($d));
        $this->assertSame(1.0, StrategicDecisions::metricValue($this->snapshot(['growth' => ['focus_trips' => [['id' => 7, 'recent' => 1, 'upcoming' => 1]]]]), 'trip.7.upcoming'));
    }

    public function testMetricValueAndOutcome(): void
    {
        $s = $this->snapshot();
        $this->assertSame(90.0, StrategicDecisions::metricValue($s, 'scores.seo_score'));
        $this->assertSame(1.0, StrategicDecisions::metricValue($s, 'understanding.profile_confirmed'));
        $this->assertNull(StrategicDecisions::metricValue($s, 'scores.missing'));
        $this->assertSame('improved', StrategicDecisions::outcome(3.0, 0.0, 'down'));
        $this->assertSame('worse', StrategicDecisions::outcome(80.0, 70.0, 'up'));
        $this->assertSame('unchanged', StrategicDecisions::outcome(5.0, 5.0, 'up'));
        $this->assertNull(StrategicDecisions::outcome(null, 5.0, 'up'));
    }
}
