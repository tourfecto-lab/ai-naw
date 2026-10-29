<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/Services/Brain/MarketIntelligenceService.php';
require_once __DIR__ . '/../../app/Services/Brain/StrategicDecisions.php';
require_once __DIR__ . '/../../app/Services/Brain/SharedIntelligence.php';

/** ذكاء السوق والذكاء المشترك: المحللات بعينات بنفس شكل ردود المصادر، والقرارات من الإشارات */
class MarketIntelligenceTest extends TestCase
{
    public function testDetectDestinationAndMarketsFromCompanyText(): void
    {
        $eg = MarketIntelligenceService::detect('Egypt tour operator: Luxor, Aswan, Nile cruises', 'English speakers. أمريكا، بريطانيا، كندا');
        $this->assertSame('EG', $eg['destination']);
        $this->assertSame(['US', 'GB', 'CA'], $eg['markets']);
        $it = MarketIntelligenceService::detect('Private tours in Rome and Florence', '', ['Germany', 'USA']);
        $this->assertSame('IT', $it['destination']);
        $this->assertSame(['DE', 'US'], $it['markets']);
        $none = MarketIntelligenceService::detect('', '');
        $this->assertSame(MarketIntelligenceService::DEFAULT_MARKETS, $none['markets']);
        $this->assertSame(true, $none['markets_default']);
    }

    public function testParsers(): void
    {
        $wb = MarketIntelligenceService::parseWorldBank([['page' => 1], [
            ['indicator' => ['id' => 'ST.INT.ARVL'], 'country' => ['id' => 'EG', 'value' => 'Egypt'], 'date' => '2024', 'value' => 15700000],
            ['indicator' => ['id' => 'ST.INT.ARVL'], 'country' => ['id' => 'EG', 'value' => 'Egypt'], 'date' => '2023', 'value' => null],
        ]], 'ST.INT.ARVL');
        $this->assertSame([['key' => 'ST.INT.ARVL:EG', 'period' => '2024', 'value' => 15700000.0]], $wb);

        $ecb = MarketIntelligenceService::parseEcbCsv("KEY,FREQ,CURRENCY,CURRENCY_DENOM,EXR_TYPE,EXR_SUFFIX,TIME_PERIOD,OBS_VALUE,OBS_STATUS\nEXR.D.USD.EUR.SP00.A,D,USD,EUR,SP00,A,2026-10-28,1.1650,A\nEXR.D.GBP.EUR.SP00.A,D,GBP,EUR,SP00,A,2026-10-28,0.8710,A\nEXR.D.USD.EUR.SP00.A,D,USD,EUR,SP00,A,2026-10-29,,A");
        $this->assertSame([['key' => 'USD:EUR', 'period' => '2026-10-28', 'value' => 1.165], ['key' => 'GBP:EUR', 'period' => '2026-10-28', 'value' => 0.871]], $ecb);

        $gov = MarketIntelligenceService::parseGovUk(['title' => 'Egypt travel advice', 'public_updated_at' => '2026-10-20T10:00:00.000+01:00', 'details' => ['change_description' => '<p>Updated safety section</p>']], 'EG');
        $this->assertSame('2026-10-20', $gov[0]['period']);
        $this->assertSame('Updated safety section', $gov[0]['meta']['change']);

        $nager = MarketIntelligenceService::parseNager([['date' => '2026-12-25', 'name' => 'Christmas Day', 'global' => true], ['date' => '2026-03-17', 'name' => "St Patrick's Day", 'global' => false]], 'GB');
        $this->assertSame([['key' => 'holidays:GB', 'period' => '2026-12-25', 'value' => null, 'meta' => ['name' => 'Christmas Day']]], $nager);

        $eu = MarketIntelligenceService::parseEurostat(['id' => ['freq', 'c_resid', 'unit', 'nace_r2', 'geo', 'time'], 'size' => [1, 1, 1, 1, 1, 3],
            'dimension' => ['time' => ['category' => ['index' => ['2025-08' => 0, '2025-09' => 1, '2026-08' => 2]]]], 'value' => ['0' => 1000.0, '2' => 1100.0]], 'IT');
        $this->assertSame([['key' => 'nights_foreign:IT', 'period' => '2025-08', 'value' => 1000.0], ['key' => 'nights_foreign:IT', 'period' => '2026-08', 'value' => 1100.0]], $eu);
    }

    public function testBadResponsesAreErrorsNotNumbers(): void
    {
        foreach ([fn () => MarketIntelligenceService::parseWorldBank([['message' => [['value' => 'Invalid indicator']]]], 'X'),
            fn () => MarketIntelligenceService::parseEcbCsv("A,B\n1,2"),
            fn () => MarketIntelligenceService::parseGovUk(['title' => 'x'], 'EG'),
            fn () => MarketIntelligenceService::parseEurostat(['error' => ['label' => 'No data']], 'IT')] as $fn) {
            $thrown = false;
            try { $fn(); } catch (RuntimeException $e) { $thrown = true; }
            $this->assertSame(true, $thrown);
        }
    }

    public function testMarketDecisions(): void
    {
        $m = ['destination' => 'EG', 'lead_time_days' => 40, 'eurozone' => false,
            'travel_advice' => ['updated_at' => '2026-10-25', 'change' => 'Updated safety section', 'recent' => true],
            'holidays' => [['country' => 'GB', 'date' => '2026-12-25', 'name' => 'Christmas Day', 'days_away' => 57], ['country' => 'US', 'date' => '2026-12-25', 'name' => 'Christmas Day', 'days_away' => 57], ['country' => 'GB', 'date' => '2026-11-05', 'name' => 'X', 'days_away' => 7]],
            'fx' => ['latest' => 1.23, 'base' => 1.15, 'from' => '2026-07-30', 'to' => '2026-10-28', 'change_pct' => 7.0], 'fx_gbp' => ['change_pct' => 1.0],
            'economy' => ['DE' => ['gdp_growth' => -0.3, 'year' => '2025'], 'GB' => ['gdp_growth' => 1.1, 'year' => '2025']]];
        $keys = array_column(StrategicDecisions::marketDecisions($m), 'key');
        $this->assertSame(['travel_advice_EG', 'holiday_20261225', 'fx_eur_usd', 'economy_DE'], $keys);
        $this->assertSame(true, str_contains(StrategicDecisions::marketDecisions($m)[1]['title']['en'], 'the UK and the US'));
        $this->assertSame([], StrategicDecisions::marketDecisions(null));

        $eu = StrategicDecisions::marketDecisions(['destination' => 'IT', 'eurozone' => true, 'fx' => ['latest' => 1.05, 'base' => 1.12, 'from' => 'a', 'to' => 'b', 'change_pct' => -6.3],
            'demand' => ['period' => '2026-08', 'nights' => 1100, 'previous_period' => '2025-08', 'previous' => 1000, 'change_pct' => 10.0]]);
        $this->assertSame(['fx_usd', 'demand_up_IT'], array_column($eu, 'key'));
        $this->assertSame('acquisition', $eu[1]['area']);
    }

    public function testSharedIntelligenceNeedsFiveCompanies(): void
    {
        $rows = [];
        foreach ([1, 2, 3, 4] as $u) $rows[] = ['user_id' => $u, 'decision_key' => 'promote_peak_' . $u, 'outcome' => 'improved'];
        $this->assertSame([], SharedIntelligence::aggregate($rows));
        $rows[] = ['user_id' => 5, 'decision_key' => 'promote_peak_9', 'outcome' => 'worse'];
        $rows[] = ['user_id' => 5, 'decision_key' => 'seo_autopilot', 'outcome' => 'improved'];
        $p = SharedIntelligence::aggregate($rows);
        $this->assertSame(['promote_peak' => ['companies' => 5, 'measured' => 5, 'improved_rate' => 0.8]], $p);
        $this->assertSame(1.12, SharedIntelligence::weight($p['promote_peak']));

        // النتيجة بتدخل الأولوية وبتظهر كدليل
        $s = ['understanding' => ['profile_confirmed' => true, 'goal' => '', 'audience' => 'x', 'offers' => 'y', 'markets' => ['countries' => ['GB']]], 'scores' => [], 'competitors' => ['tracked' => 1],
            'seo' => ['autopilot' => true, 'ai_questions_approved' => 1], 'growth' => ['focus_trips' => [['id' => 3, 'name' => 'Abu Simbel', 'reason' => 'needs_bookings_in_peak', 'recent' => 0, 'previous' => 0, 'upcoming' => 1]]]];
        $d = StrategicDecisions::decide($s, [], $p)[0];
        $this->assertSame('promote_peak_3', $d['key']);
        $this->assertSame(33.6, $d['priority']);
        $this->assertSame(true, str_contains(end($d['evidence'])['en'], '80% of 5 measured cases across 5 companies'));
    }

    public function testFailedSourceIsRetriedAfterHalfAnHourNotItsFullTtl(): void
    {
        $now = strtotime('2026-10-29 15:00:00 UTC');
        $this->assertSame(true, MarketIntelligenceService::isDue(null, null, 86400, $now));
        $this->assertSame(false, MarketIntelligenceService::isDue('2026-10-29 14:15:00', 'ok', 86400, $now));
        $this->assertSame(true, MarketIntelligenceService::isDue('2026-10-29 14:15:00', 'error', 86400, $now));
        $this->assertSame(false, MarketIntelligenceService::isDue('2026-10-29 14:45:00', 'error', 86400, $now));
        $this->assertSame(true, MarketIntelligenceService::isDue('2026-10-28 14:00:00', 'ok', 86400, $now));
    }
}
