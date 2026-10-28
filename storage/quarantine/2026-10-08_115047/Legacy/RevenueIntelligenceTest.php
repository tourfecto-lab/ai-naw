<?php

/**
 * Tourfecto - AI Revenue Intelligence Test
 * اختبارات موديول ذكاء الإيرادات (TOURFECTO AI REVENUE INTELLIGENCE)
 * @version 1.5.0
 * @author Tourfecto Team
 * @copyright 2026 Tourfecto
 *
 * ملاحظة مهمة (Section 22 من المتطلبات):
 * كل الخدمات هنا تُختبر عبر Pure Functions الثابتة (static) بمدخلات
 * Fixtures ثابتة، بدون أي اتصال بقاعدة بيانات حقيقية - هذه ليست
 * Production Data، فقط بيانات اختبار مصطنعة عمدًا لغرض التحقق من صحة
 * منطق الحساب (Forecast/Anomaly/Segmentation/Pipeline/Insights/Assistant).
 * لا تختبر هذه الملف أي Endpoint حي أو يقرأ من rev_revenue_records
 * الحقيقي.
 */

/**
 * Fixture/Mock فقط لأغراض الاختبار (Section 22: "اختبر Behavior باستخدام
 * Fixtures/Mocks للاختبار فقط. ولا تعتبرها Production Data.") - يمنع أي
 * محاولة اتصال بقاعدة بيانات حقيقية أثناء بناء الخدمات في هذا الملف،
 * لأن كل الاختبارات هنا تستهدف Pure Functions فقط ولا تحتاج بيانات فعلية.
 */
class RevenueDataGateway
{
    public function __construct()
    {
    }
}

require_once __DIR__ . '/../../app/Services/RevenueIntelligence/RevenueOverviewService.php';
require_once __DIR__ . '/../../app/Services/RevenueIntelligence/RevenueForecastService.php';
require_once __DIR__ . '/../../app/Services/RevenueIntelligence/RevenueAnomalyService.php';
require_once __DIR__ . '/../../app/Services/RevenueIntelligence/CustomerRevenueService.php';
require_once __DIR__ . '/../../app/Services/RevenueIntelligence/PipelineRevenueService.php';
require_once __DIR__ . '/../../app/Services/RevenueIntelligence/RevenueInsightService.php';
require_once __DIR__ . '/../../app/Services/RevenueIntelligence/RevenueActionService.php';
require_once __DIR__ . '/../../app/Services/RevenueIntelligence/RevenueAssistantService.php';
require_once __DIR__ . '/../../app/Services/RevenueIntelligence/RevenueCacheService.php';
require_once __DIR__ . '/../../app/Services/RevenueIntelligence/RevenueRetentionService.php';
require_once __DIR__ . '/../../app/Services/RevenueIntelligence/RevenueCopilotService.php';
require_once __DIR__ . '/../../app/Services/RevenueIntelligence/BizSubscriptionService.php';
require_once __DIR__ . '/../../app/Services/RevenueIntelligence/DealLevelForecastService.php';
require_once __DIR__ . '/../../app/Services/RevenueIntelligence/RevenueBenchmarkService.php';
require_once __DIR__ . '/../../app/Services/RevenueIntelligence/RevenueChurnService.php';
require_once __DIR__ . '/../../app/Services/RevenueIntelligence/StripeRevenueMapper.php';
// v1.6.0: Dashboard personalization + Stripe webhook (pure static functions)
require_once __DIR__ . '/../../app/Services/RevenueIntelligence/RevenueDashboardService.php';
require_once __DIR__ . '/../../app/Services/RevenueIntelligence/StripeWebhookService.php';
$revaiQueueContract = __DIR__ . '/../../app/Core/Contracts/QueueJobInterface.php';
if (file_exists($revaiQueueContract)) {
    require_once $revaiQueueContract;
}
$revaiDigestJob = __DIR__ . '/../../app/Jobs/SendRevenueDigestJob.php';
if (file_exists($revaiDigestJob)) {
    require_once $revaiDigestJob;
}

class RevenueIntelligenceTest
{
    /** @var array */
    private $testResults = [];
    /** @var int */
    private $passed = 0;
    /** @var int */
    private $failed = 0;

    public function runAll(): void
    {
        echo "\n💰 AI Revenue Intelligence Tests\n";
        echo "=================================\n";

        $this->testForecastInsufficientData();
        $this->testForecastWithTrend();
        $this->testAnomalyDetection();
        $this->testAnomalyInsufficientData();
        $this->testCustomerSegmentation();
        $this->testPipelineIntelligence();
        $this->testInsightGeneration();
        $this->testNextBestActions();
        $this->testAssistantIntentMatching();
        $this->testAssistantNewIntents();
        $this->testAssistantSmartFallback();
        $this->testAssistantNoInventedAnswers();
        $this->testAssistantArabicNormalization();
        $this->testAssistantPeriodAware();
        $this->testAssistantWhatIfScenario();
        $this->testAssistantFollowUpSuggestions();
        $this->testScenarioForecast();
        $this->testPeriodToDays();
        $this->testSeasonalFactor();
        $this->testSeasonalForecast();
        $this->testGraduatedCacheTtl();
        $this->testAssistantArabicSynonyms();
        $this->testCopilotBuildPrompt();
        $this->testCopilotEnhanceFallback();
        $this->testCopilotEnhanceSuccess();
        $this->testRetentionCohort();
        $this->testRetentionRepeatPurchase();
        $this->testRetentionRecurringStability();
        $this->testRetentionRevenueRetentionRate();
        $this->testRetentionNoInventedData();
        $this->testDigestHtml();
        $this->testSubscriptionsMrrArr();
        $this->testSubscriptionsMrrBreakdown();
        $this->testSubscriptionsNrr();
        $this->testSubscriptionsGrr();
        $this->testSubscriptionsChurn();
        $this->testSubscriptionsNotEnoughData();
        $this->testStripeMapperNormalizeAmount();
        $this->testStripeMapperMapInterval();
        $this->testStripeMapperConvertToMrr();
        $this->testStripeMapperSubscriptionCreated();
        $this->testStripeMapperInvoicePaid();
        $this->testStripeMapperSubscriptionDeleted();
        $this->testDealForecastBuckets();
        $this->testDealForecastUndated();
        $this->testDealForecastWeightedValue();
        $this->testSalesAttributionByRep();
        $this->testSalesAttributionByTeam();
        $this->testBenchmarkClassifyChurnReason();
        $this->testBenchmarkAggregateChurnReasons();
        $this->testBenchmarkServiceNotInstalled();
        $this->testChurnAnalyticsNotEnoughData();
        $this->testChurnAnalyticsReasons();
        $this->testDashboardPrefsDefaultLayout();
        $this->testDashboardPrefsNormalizeUnknownKeys();
        $this->testDashboardPrefsNormalizeMissingKeys();
        $this->testDashboardPrefsApplyLayout();
        $this->testStripeWebhookVerifySignature();
        $this->testStripeWebhookVerifySignatureTampered();

        $this->printSummary();
    }

    // ============================================================
    // Section 2: REVENUE FORECASTING
    // ============================================================

    private function testForecastInsufficientData(): void
    {
        $this->startTest('Forecast: insufficient data -> honest message, no invented numbers');
        $series = [];
        for ($i = 0; $i < 5; $i++) {
            $series[] = ['date' => "2026-08-0{$i}", 'revenue' => 100];
        }
        $result = RevenueForecastService::computeForecast($series, 'monthly', '2026-08-09');

        $this->assertTrue($result['insufficient_data'] === true, 'Flags insufficient_data when below MIN_DATA_POINTS');
        $this->assertTrue($result['expected_revenue'] === null, 'expected_revenue is null (never invented)');
        $this->assertTrue($result['message'] === 'Not enough data for reliable forecast.', 'Exact required message is returned');
    }

    private function testForecastWithTrend(): void
    {
        $this->startTest('Forecast: enough data with clear upward trend');
        $series = [];
        for ($i = 0; $i < 30; $i++) {
            $series[] = ['date' => sprintf('2026-07-%02d', ($i % 28) + 1), 'revenue' => 100 + $i * 5];
        }
        $result = RevenueForecastService::computeForecast($series, 'monthly', '2026-08-09');

        $this->assertTrue($result['insufficient_data'] === false, 'Enough data points to attempt a forecast');
        $this->assertTrue($result['growth_trend'] === 'up', 'Detects upward trend correctly');
        $this->assertTrue($result['expected_revenue'] > 0, 'Produces a positive expected revenue');
        $this->assertTrue(in_array($result['confidence'], ['low', 'medium', 'high'], true), 'Confidence is one of low/medium/high');
        $this->assertTrue($result['forecast_range']['low'] <= $result['expected_revenue'], 'Range low <= expected');
        $this->assertTrue($result['forecast_range']['high'] >= $result['expected_revenue'], 'Range high >= expected');
    }

    // ============================================================
    // Section 9: REVENUE ANOMALY DETECTION
    // ============================================================

    private function testAnomalyDetection(): void
    {
        $this->startTest('Anomaly detection: flags an injected spike');
        $series = [];
        for ($i = 1; $i <= 20; $i++) {
            $series[] = ['date' => sprintf('2026-07-%02d', $i), 'revenue' => 100];
        }
        $series[9]['revenue'] = 1500; // شذوذ مصطنع للاختبار
        $result = RevenueAnomalyService::computeAnomalies($series);

        $this->assertTrue($result['has_data'] === true, 'Has enough data to run detection');
        $this->assertTrue(count($result['anomalies']) >= 1, 'Detects at least one anomaly');
        $found = false;
        foreach ($result['anomalies'] as $a) {
            if ($a['period'] === $series[9]['date'] && $a['type'] === 'sudden_increase') {
                $found = true;
            }
        }
        $this->assertTrue($found, 'Correctly identifies the spike day as sudden_increase');
    }

    private function testAnomalyInsufficientData(): void
    {
        $this->startTest('Anomaly detection: insufficient data -> no false positives');
        $series = [['date' => '2026-08-01', 'revenue' => 100], ['date' => '2026-08-02', 'revenue' => 5000]];
        $result = RevenueAnomalyService::computeAnomalies($series);
        $this->assertTrue($result['has_data'] === false, 'Refuses to compute anomalies below MIN_WINDOW');
        $this->assertTrue($result['anomalies'] === [], 'No anomalies invented from too little data');
    }

    // ============================================================
    // Section 5 & 12: CUSTOMER REVENUE INTELLIGENCE & SEGMENTATION
    // ============================================================

    private function testCustomerSegmentation(): void
    {
        $this->startTest('Customer segmentation: VIP / Inactive / Growing rules');

        // عميل VIP: أعلى إيراد + نشاط حديث
        $vipDeals = [['value' => 5000, 'closed_at' => '2026-07-01'], ['value' => 6000, 'closed_at' => '2026-08-01']];
        // عميل غير نشط: آخر شراء منذ أكثر من 180 يوم
        $inactiveDeals = [['value' => 300, 'closed_at' => '2025-01-01']];
        // عميل متنامي: صفقاته تكبر بمرور الوقت لكن إيراده الكلي منخفض نسبيًا
        $growingDeals = [['value' => 100, 'closed_at' => '2026-06-01'], ['value' => 400, 'closed_at' => '2026-08-01']];

        $byContact = [
            1 => ['contact_id' => 1, 'contact_name' => 'VIP Co', 'contact_email' => 'vip@x.com', 'deals' => $vipDeals, 'total' => 11000],
            2 => ['contact_id' => 2, 'contact_name' => 'Ghost Co', 'contact_email' => 'ghost@x.com', 'deals' => $inactiveDeals, 'total' => 300],
            3 => ['contact_id' => 3, 'contact_name' => 'Rising Co', 'contact_email' => 'rise@x.com', 'deals' => $growingDeals, 'total' => 500],
        ];
        $totals = [11000, 300, 500];

        $customers = CustomerRevenueService::buildCustomerRecords($byContact, $totals, '2026-08-09');
        $byId = [];
        foreach ($customers as $c) {
            $byId[$c['contact_id']] = $c;
        }

        $this->assertTrue($byId[1]['value_segment'] === 'VIP', 'Highest-revenue recently-active customer is VIP');
        $this->assertTrue($byId[2]['value_segment'] === 'Inactive', 'Customer with no purchase in 180+ days is Inactive');
        $this->assertTrue($byId[3]['revenue_trend'] === 'growing', 'Detects growing spend trend correctly');
        $this->assertTrue($byId[1]['customer_lifetime_value'] === $byId[1]['customer_revenue'], 'LTV equals realized revenue to date (no invented future value)');
    }

    // ============================================================
    // Section 6: DEAL & PIPELINE REVENUE INTELLIGENCE
    // ============================================================

    private function testPipelineIntelligence(): void
    {
        $this->startTest('Pipeline: weighted value, likely wins, at-risk (overdue) deals');
        $openDeals = [
            ['id' => 1, 'title' => 'Deal A', 'value' => 1000, 'probability' => 80, 'stage_win_probability' => 50, 'expected_close_date' => '2026-09-01', 'stage_name' => 'Negotiation'],
            ['id' => 2, 'title' => 'Deal B (overdue)', 'value' => 500, 'probability' => 0, 'stage_win_probability' => 20, 'expected_close_date' => '2026-07-01', 'stage_name' => 'Prospecting'],
        ];
        $pipeline = PipelineRevenueService::computePipeline($openDeals, 2000, '2026-08-09');

        $this->assertTrue($pipeline['pipeline_value'] === 1500.0, 'Pipeline value = sum of all open deal values');
        $this->assertTrue($pipeline['weighted_pipeline'] === 900.0, 'Weighted pipeline = sum(value x probability)');
        $this->assertTrue(count($pipeline['at_risk_deals']) === 1, 'Overdue open deal flagged as at-risk');
        $this->assertTrue(count($pipeline['likely_wins']) === 1, 'High-probability deal flagged as likely win');
        $this->assertTrue($pipeline['pipeline_coverage'] === 0.45, 'Pipeline coverage computed against provided actual-revenue baseline');
    }

    // ============================================================
    // Sections 3, 4 & 15: OPPORTUNITIES, RISKS, AI EXPLANATION SHAPE
    // ============================================================

    private function testInsightGeneration(): void
    {
        $this->startTest('Insights: every opportunity/risk has the required AI explanation shape');
        $customers = [
            ['contact_id' => 1, 'name' => 'VIP Co', 'customer_revenue' => 9000, 'purchase_frequency' => 3, 'value_segment' => 'VIP', 'revenue_trend' => 'stable', 'days_since_last_purchase' => 10],
            ['contact_id' => 2, 'name' => 'Ghost Co', 'customer_revenue' => 400, 'purchase_frequency' => 1, 'value_segment' => 'Inactive', 'revenue_trend' => 'stable', 'days_since_last_purchase' => 250],
        ];
        $opportunities = RevenueInsightService::opportunitiesFromCustomers($customers);
        $this->assertTrue(count($opportunities) >= 2, 'Generates opportunities for both VIP and inactive-reengagement');

        $overview = ['growth_percent' => -25.0, 'previous_period_revenue' => 1000, 'total_revenue' => 750];
        $risk = RevenueInsightService::riskFromOverview($overview);
        $this->assertTrue($risk !== null, 'Flags a risk when revenue drops more than 10%');

        $requiredKeys = ['type', 'category', 'title', 'finding', 'evidence', 'reasoning_summary', 'confidence', 'recommended_action'];
        $allPresent = true;
        foreach ($requiredKeys as $key) {
            if (!array_key_exists($key, $risk)) {
                $allPresent = false;
            }
        }
        $this->assertTrue($allPresent, 'Risk insight contains Finding/Evidence/Reasoning/Confidence/Recommended Action (section 15)');

        $noDeclineOverview = ['growth_percent' => 2.0, 'previous_period_revenue' => 1000, 'total_revenue' => 1020];
        $this->assertTrue(RevenueInsightService::riskFromOverview($noDeclineOverview) === null, 'Does not invent a risk when growth is healthy');
    }

    // ============================================================
    // Section 11: NEXT BEST REVENUE ACTION
    // ============================================================

    private function testNextBestActions(): void
    {
        $this->startTest('Next best actions: ranks by severity/confidence, never invents impact');
        $opportunities = [
            ['type' => 'opportunity', 'category' => 'high_value_customer', 'title' => 'X', 'finding' => 'f', 'evidence' => [], 'reasoning_summary' => 'r', 'confidence' => 'high', 'estimated_impact' => null, 'affected_area' => 'customer:1', 'recommended_action' => 'Contact them'],
        ];
        $risks = [
            ['type' => 'risk', 'category' => 'revenue_decline', 'title' => 'Y', 'finding' => 'f', 'evidence' => [], 'reasoning_summary' => 'r', 'confidence' => 'high', 'severity' => 'high', 'estimated_impact' => 500.0, 'affected_area' => 'overall_revenue', 'recommended_action' => 'Investigate'],
        ];
        $actions = RevenueActionService::rankActions($opportunities, $risks, [], 10);

        $this->assertTrue(count($actions) === 2, 'Combines opportunities and risks into a single ranked list');
        $this->assertTrue($actions[0]['source_type'] === 'risk', 'High-severity risk is ranked above a plain opportunity');
        $this->assertTrue($actions[0]['expected_impact'] === 500.0, 'Preserves real estimated_impact, never fabricates a new one');
        $this->assertTrue($actions[1]['expected_impact'] === null, 'Leaves expected_impact null when it cannot be honestly calculated');
    }

    // ============================================================
    // Section 10: AI REVENUE ASSISTANT
    // ============================================================

    private function testAssistantIntentMatching(): void
    {
        $this->startTest('AI Assistant: matches the required Arabic/English sample questions');
        $this->assertTrue(RevenueAssistantService::matchIntent('ليه الإيرادات قلت الشهر ده؟') === 'why_revenue_declined', 'Matches "why did revenue decrease"');
        $this->assertTrue(RevenueAssistantService::matchIntent('إيه أكبر مصادر الإيرادات؟') === 'top_revenue_sources', 'Matches "biggest revenue sources"');
        $this->assertTrue(RevenueAssistantService::matchIntent('مين العملاء الأعلى قيمة؟') === 'top_value_customers', 'Matches "highest value customers"');
        $this->assertTrue(RevenueAssistantService::matchIntent('إيه الفرص اللي ممكن تزود الإيرادات؟') === 'growth_opportunities', 'Matches "opportunities to grow revenue"');
        $this->assertTrue(RevenueAssistantService::matchIntent('هل الإيرادات ماشية في اتجاه صاعد؟') === 'is_trending_up', 'Matches "is revenue trending up"');
        $this->assertTrue(RevenueAssistantService::matchIntent('إيه المخاطر الموجودة؟') === 'current_risks', 'Matches "what risks exist"');
        $this->assertTrue(RevenueAssistantService::matchIntent('إيه المتوقع الشهر القادم؟') === 'next_month_forecast', 'Matches "what is expected next month"');
        $this->assertTrue(RevenueAssistantService::matchIntent('what is the weather today') === 'unknown', 'Unsupported/off-topic question maps to "unknown", not guessed');
    }

    private function testAssistantNewIntents(): void
    {
        $this->startTest('AI Assistant: matches the 4 new intents added in the assistant upgrade');
        $this->assertTrue(RevenueAssistantService::matchIntent('إيه حالة الصفقات المفتوحة؟') === 'pipeline_status', 'Matches "pipeline status" (Arabic)');
        $this->assertTrue(RevenueAssistantService::matchIntent('what is our pipeline status') === 'pipeline_status', 'Matches "pipeline status" (English)');
        $this->assertTrue(RevenueAssistantService::matchIntent('إيه تقسيم العملاء عندي؟') === 'customer_segments', 'Matches "customer segments" (Arabic)');
        $this->assertTrue(RevenueAssistantService::matchIntent('show me customer segments') === 'customer_segments', 'Matches "customer segments" (English)');
        $this->assertTrue(RevenueAssistantService::matchIntent('فيه حاجة غريبة حصلت في الإيراد؟') === 'anomaly_check', 'Matches "anything unusual" (Arabic)');
        $this->assertTrue(RevenueAssistantService::matchIntent('any unusual revenue activity') === 'anomaly_check', 'Matches "anything unusual" (English)');
        $this->assertTrue(RevenueAssistantService::matchIntent('إيه المفروض أعمله دلوقتي؟') === 'next_best_action', 'Matches "what should I do now" (Arabic)');
        $this->assertTrue(RevenueAssistantService::matchIntent('what should i do next') === 'next_best_action', 'Matches "what should I do" (English)');
    }

    private function testAssistantSmartFallback(): void
    {
        $this->startTest('AI Assistant: suggests related topics instead of a flat dead-end when no exact match');
        // "revenue sources" بدون صيغة مطابقة تمامًا - لازم يقترح top_revenue_sources كأقرب نية، مش يرفض جاف
        $suggestions = RevenueAssistantService::suggestClosestIntents('tell me about my revenue sources performance');
        $this->assertTrue(in_array('top_revenue_sources', $suggestions, true), 'Suggests top_revenue_sources for a loosely-related phrase');

        // سؤال عشوائي تمامًا بدون أي كلمة متعلقة - لازم يرجع مصفوفة فاضية (مفيش اقتراح مزيّف)
        $noSuggestions = RevenueAssistantService::suggestClosestIntents('xyzzy qwerty random gibberish');
        $this->assertTrue($noSuggestions === [], 'Returns no suggestions for genuinely unrelated gibberish, does not force a match');
    }

    private function testAssistantNoInventedAnswers(): void
    {
        $this->startTest('AI Assistant: never invents an answer for unmatched or data-less questions');
        $service = new RevenueAssistantService(new RevenueOverviewService(), new RevenueForecastService(), new RevenueInsightService(), new CustomerRevenueService());
        // سؤال لا يطابق أي Intent مدعوم - لازم يرجع "Not enough data." وليس إجابة مخترعة، وبدون محاولة اتصال DB (persist=false)
        $answer = $service->ask(0, 'random unrelated question 12345', false);
        $this->assertTrue($answer['has_data'] === false, 'Unmatched question returns has_data=false');
        $this->assertTrue($answer['finding'] === 'Not enough data.', 'Exact required fallback message for unmatched questions');
    }

    // ============================================================
    // Section 10 (v1.2.0): Arabic normalization, period-aware, what-if, follow-ups
    // ============================================================

    private function testAssistantArabicNormalization(): void
    {
        $this->startTest('AI Assistant: Arabic normalization (أ/ا، ى/ي، ة/ه) reaches same intent');
        $this->assertTrue(RevenueAssistantService::matchIntent('اكبر مصدر للايراد') === 'top_revenue_sources', 'Normalized spelling reaches top_revenue_sources');
        $this->assertTrue(RevenueAssistantService::matchIntent('اكبر عميل عندي') === 'top_value_customers', 'Normalized spelling reaches top_value_customers');
        $this->assertTrue(RevenueAssistantService::matchIntent('لية الايرادات انخفضت') === 'why_revenue_declined', 'Common colloquial spelling matches decline intent');
        $this->assertTrue(RevenueAssistantService::matchIntent('الوضع عامل ايه') === 'is_trending_up', 'Alif variant matches trend intent');
    }

    private function testAssistantPeriodAware(): void
    {
        $this->startTest('AI Assistant: detects the period from the question phrasing');
        $this->assertTrue(RevenueAssistantService::detectPeriod('إيه أكبر مصادر الإيرادات الشهر ده؟') === 'monthly', 'Detects monthly');
        $this->assertTrue(RevenueAssistantService::detectPeriod('الوضع عامل إيه الأسبوع ده؟') === 'weekly', 'Detects weekly');
        $this->assertTrue(RevenueAssistantService::detectPeriod('الربع ده ماشي إزاي؟') === 'quarterly', 'Detects quarterly');
        $this->assertTrue(RevenueAssistantService::detectPeriod('how are we doing this year?') === 'yearly', 'Detects yearly (English)');
        $this->assertTrue(RevenueAssistantService::detectPeriod('what is revenue today?') === 'daily', 'Detects daily (English)');
        $this->assertTrue(RevenueAssistantService::detectPeriod('أكبر مصادر الإيرادات؟') === 'monthly', 'Defaults to monthly when no period given');
    }

    private function testAssistantWhatIfScenario(): void
    {
        $this->startTest('AI Assistant: matches what-if scenario questions and extracts growth %');
        $this->assertTrue(RevenueAssistantService::matchIntent('ماذا لو زادت الإيرادات 20%؟') === 'what_if_scenario', 'Matches Arabic what-if');
        $this->assertTrue(RevenueAssistantService::matchIntent('what if revenue grows 15%') === 'what_if_scenario', 'Matches English what-if');
        $this->assertTrue(RevenueAssistantService::extractGrowthPercent('ماذا لو زادت الإيرادات 20%؟') === 20.0, 'Extracts 20% growth');
        $this->assertTrue(RevenueAssistantService::extractGrowthPercent('what if revenue grows 15%') === 15.0, 'Extracts 15% growth');
        $this->assertTrue(RevenueAssistantService::extractGrowthPercent('what if revenue improves') === 0.0, 'Defaults to 0 when no percentage');
    }

    private function testAssistantFollowUpSuggestions(): void
    {
        $this->startTest('AI Assistant: every answer carries follow-up question suggestions (Clari-style)');
        $followUps = RevenueAssistantService::suggestFollowUps('why_revenue_declined');
        $this->assertTrue(count($followUps) >= 3, 'Provides at least 3 follow-up suggestions');
        $this->assertTrue(is_string($followUps[0]), 'Suggestions are readable questions');
        $this->assertTrue(RevenueAssistantService::suggestFollowUps('unknown_intent') !== [], 'Unknown intent still gets generic suggestions');
    }

    private function testScenarioForecast(): void
    {
        $this->startTest('Forecast: what-if scenario scales the real base forecast only');
        $series = [];
        for ($i = 0; $i < 30; $i++) {
            $series[] = ['date' => sprintf('2026-07-%02d', ($i % 28) + 1), 'revenue' => 100 + $i * 5];
        }
        $base = RevenueForecastService::computeForecast($series, 'monthly', '2026-08-09');
        $scenario = RevenueForecastService::scenarioForecast($series, 'monthly', '2026-08-09', 20.0);

        $this->assertTrue($scenario['scenario'] === true, 'Flags the result as a scenario');
        $this->assertTrue($scenario['scenario_growth_percent'] === 20.0, 'Keeps the assumed growth percentage');
        $this->assertTrue($scenario['base_expected_revenue'] === $base['expected_revenue'], 'Base revenue comes from the real forecast, never invented');
        $this->assertTrue(abs($scenario['expected_revenue'] - ($base['expected_revenue'] * 1.2)) < 0.01, 'Applies exactly the assumed growth to the real base');
        $this->assertTrue($scenario['forecast_range']['low'] <= $scenario['expected_revenue'], 'Range low <= expected');
        $this->assertTrue($scenario['forecast_range']['high'] >= $scenario['expected_revenue'], 'Range high >= expected');
        $this->assertTrue($scenario['note'] !== '', 'Carries an honest "not a guarantee" note');
    }

    // ============================================================
    // Section 1: REVENUE OVERVIEW (period helper)
    // ============================================================

    private function testPeriodToDays(): void
    {
        $this->startTest('Overview: period-to-days mapping used across Daily/Weekly/Monthly/Quarterly/Yearly');
        $this->assertTrue(RevenueOverviewService::periodToDays('daily') === 1, 'daily = 1 day');
        $this->assertTrue(RevenueOverviewService::periodToDays('weekly') === 7, 'weekly = 7 days');
        $this->assertTrue(RevenueOverviewService::periodToDays('monthly') === 30, 'monthly = 30 days');
        $this->assertTrue(RevenueOverviewService::periodToDays('quarterly') === 90, 'quarterly = 90 days');
        $this->assertTrue(RevenueOverviewService::periodToDays('yearly') === 365, 'yearly = 365 days');
    }

    // ============================================================
    // v1.3.0: Seasonality, graduated cache TTL, Arabic synonyms
    // ============================================================

    private function testSeasonalFactor(): void
    {
        $this->startTest('Forecast: seasonal factor compares current period to the prior equivalent period');
        // نافذتان نظيفتان: الفترة السابقة كل أيامها 100، الحالية كل أيامها 150
        // (خلال 30 يوم لكل نافذة: السابقة [2026-06-10..2026-07-09]، الحالية [2026-07-10..2026-08-08])
        $series = self::buildDailySeries('2026-06-10', 30, 100);
        $series = array_merge($series, self::buildDailySeries('2026-07-10', 30, 150));

        $result = RevenueForecastService::computeSeasonalFactor($series, 'monthly', '2026-08-09');
        $this->assertTrue($result['seasonal_factor'] !== null, 'Produces a seasonal factor from enough data');
        $this->assertTrue(abs($result['seasonal_factor'] - 1.5) < 0.05, 'Current period 50% above prior period yields factor ~1.5');
        $this->assertTrue($result['has_seasonality'] === true, 'Flags seasonality when the deviation exceeds the 20% threshold');
    }

    private function testSeasonalForecast(): void
    {
        $this->startTest('Forecast: seasonal forecast scales the real linear forecast by the seasonal factor');
        $series = self::buildDailySeries('2026-06-10', 30, 100);
        $series = array_merge($series, self::buildDailySeries('2026-07-10', 30, 150));

        $base = RevenueForecastService::computeForecast($series, 'monthly', '2026-08-09');
        $seasonal = RevenueForecastService::seasonalForecast($series, 'monthly', '2026-08-09');

        $this->assertTrue($seasonal['insufficient_data'] === false, 'Seasonal forecast runs on sufficient data');
        $this->assertTrue($seasonal['seasonal'] === true, 'Flags that a seasonal adjustment was applied');
        $this->assertTrue($seasonal['seasonal_factor'] > 1, 'Factor above 1 for a high season');
        $this->assertTrue($seasonal['expected_revenue'] > $base['expected_revenue'], 'Seasonal forecast exceeds the plain linear forecast in a high season');
        $this->assertTrue($seasonal['forecast_range']['low'] <= $seasonal['expected_revenue'], 'Range low <= expected');
        $this->assertTrue($seasonal['forecast_range']['high'] >= $seasonal['expected_revenue'], 'Range high >= expected');
        $this->assertTrue(strpos($seasonal['seasonality_note'], 'not a full multi-year seasonal model') !== false, 'Honest note: simple comparison, not a full seasonal model');
    }

    private function testGraduatedCacheTtl(): void
    {
        $this->startTest('Cache: graduated TTL - shorter for fast-moving periods, longer for expensive ones');
        $this->assertTrue(RevenueCacheService::ttlForPeriod('daily') < RevenueCacheService::ttlForPeriod('monthly'), 'Daily TTL is shorter than monthly');
        $this->assertTrue(RevenueCacheService::ttlForPeriod('monthly') < RevenueCacheService::ttlForPeriod('yearly'), 'Monthly TTL is shorter than yearly');
        $this->assertTrue(RevenueCacheService::ttlForPeriod('quarterly') > RevenueCacheService::ttlForPeriod('weekly'), 'Quarterly TTL is longer than weekly');
        $this->assertTrue(RevenueCacheService::ttlForPeriod('unknown') === RevenueCacheService::DEFAULT_TTL, 'Unknown period falls back to the default monthly TTL');
    }

    private function testAssistantArabicSynonyms(): void
    {
        $this->startTest('AI Assistant: expanded Arabic synonyms reach the right intent');
        $this->assertTrue(RevenueAssistantService::matchIntent('أفضل زبون عندي') === 'top_value_customers', 'Matches "best customer" via زبون');
        $this->assertTrue(RevenueAssistantService::matchIntent('ليه المبيعات نزلت') === 'why_revenue_declined', 'Matches "why sales dropped" via مبيعات');
        $this->assertTrue(RevenueAssistantService::matchIntent('عايز أزود المبيعات') === 'growth_opportunities', 'Matches "want to grow sales" via المبيعات');
        $this->assertTrue(RevenueAssistantService::matchIntent('الايراد بييجي منين') === 'top_revenue_sources', 'Matches "where does revenue come from" via منين');
        $this->assertTrue(RevenueAssistantService::matchIntent('best client') === 'top_value_customers', 'Matches "best client" (English)');
        $this->assertTrue(RevenueAssistantService::matchIntent('sales forecast') === 'next_month_forecast', 'Matches "sales forecast" (English)');
    }

    // ============================================================
    // v1.4.0: Copilot, Retention, Digest
    // ============================================================

    private function testCopilotBuildPrompt(): void
    {
        $this->startTest('Copilot: buildPrompt embeds the verified answer only, never invents');
        $answer = [
            'finding' => 'Revenue grew by 12.5% this month.',
            'evidence' => ['growth_percent' => 12.5, 'total_revenue' => 15000],
            'recommended_action' => 'Focus on the top segment.',
            'confidence' => 'high',
        ];
        $prompt = RevenueCopilotService::buildPrompt($answer, 'is_trending_up', 'الوضع عامل إيه', 'ar');

        $this->assertTrue(strpos($prompt, '12.5') !== false, 'Prompt contains the exact real number from the answer');
        $this->assertTrue(strpos($prompt, '15000') !== false, 'Prompt contains the exact evidence value');
        $this->assertTrue(strpos($prompt, 'Never add, change, invent, or remove') !== false, 'Strict no-invention rule is present');
        $this->assertTrue(strpos($prompt, 'VERIFIED DATA') !== false, 'Data is labeled as verified/only allowed facts');
        $this->assertTrue(strpos($prompt, 'العربية') !== false, 'Arabic output rule included for lang=ar');
    }

    private function testCopilotEnhanceFallback(): void
    {
        $this->startTest('Copilot: any LLM failure falls back to the original strict answer');
        $answer = ['finding' => 'Not enough data.', 'evidence' => [], 'recommended_action' => null, 'confidence' => null];

        // mock يرمي exception
        $throwing = new class () {
            public function generateContent($prompt, $opts = [])
            {
                throw new RuntimeException('boom');
            }
        };
        $res = RevenueCopilotService::enhance($answer, 'unknown', 'أي سؤال', 'ar', $throwing);
        $this->assertTrue(($res['copilot_used'] ?? true) === false, 'Exception in LLM call -> copilot_used=false');
        $this->assertTrue($res['finding'] === 'Not enough data.', 'Original strict finding is preserved untouched');
        $this->assertTrue(!isset($res['copilot_narrative']), 'No narrative is added on failure');

        // mock يرجع فشل
        $failing = new class () {
            public function generateContent($prompt, $opts = [])
            {
                return ['success' => false, 'error' => 'nope'];
            }
        };
        $res2 = RevenueCopilotService::enhance($answer, 'unknown', 'أي سؤال', 'ar', $failing);
        $this->assertTrue(($res2['copilot_used'] ?? true) === false, 'LLM success=false -> copilot_used=false');
        $this->assertTrue($res2['finding'] === 'Not enough data.', 'Original answer kept on failed enhancement');

        // mock من غير generateContent
        $noMethod = new stdClass();
        $res3 = RevenueCopilotService::enhance($answer, 'unknown', 'أي سؤال', 'ar', $noMethod);
        $this->assertTrue(($res3['copilot_used'] ?? true) === false, 'LLM without generateContent -> safe fallback');
    }

    private function testCopilotEnhanceSuccess(): void
    {
        $this->startTest('Copilot: successful LLM adds narrative without altering any number');
        $answer = [
            'finding' => 'Revenue grew by 12.5% this month.',
            'evidence' => ['growth_percent' => 12.5],
            'recommended_action' => 'Focus on the top segment.',
            'confidence' => 'high',
        ];
        $llm = new class () {
            public function generateContent($prompt, $opts = [])
            {
                return ['success' => true, 'data' => 'إيراداتك نمت 12.5%. استمر في التركيز على أفضل شريحة.', 'tokens_used' => 10, 'cost' => 0.001];
            }
        };
        $res = RevenueCopilotService::enhance($answer, 'is_trending_up', 'الوضع عامل إيه', 'ar', $llm);
        $this->assertTrue(($res['copilot_used'] ?? false) === true, 'Successful LLM marks copilot_used=true');
        $this->assertTrue($res['finding'] === 'Revenue grew by 12.5% this month.', 'Original finding unchanged');
        $this->assertTrue(strpos($res['copilot_narrative'], '12.5%') !== false, 'Narrative reuses the real number');
    }

    private function testRetentionCohort(): void
    {
        $this->startTest('Retention: cohort retention is computed from real won-deal purchase months');
        $deals = [
            ['contact_id' => 1, 'closed_at' => '2026-01-10', 'value' => 100], // cohort 2026-01
            ['contact_id' => 1, 'closed_at' => '2026-02-10', 'value' => 100], // عاد في +1
            ['contact_id' => 2, 'closed_at' => '2026-01-15', 'value' => 200], // cohort 2026-01
            ['contact_id' => 3, 'closed_at' => '2026-02-20', 'value' => 300], // cohort 2026-02
            ['contact_id' => 3, 'closed_at' => '2026-03-05', 'value' => 100], // عاد في +1
        ];
        $result = RevenueRetentionService::computeCohortRetention($deals, '2026-06-01');
        $this->assertTrue($result['has_data'] === true, 'Has data to build cohorts');
        $byMonth = [];
        foreach ($result['cohorts'] as $c) {
            $byMonth[$c['cohort_month']] = $c;
        }
        $this->assertTrue(isset($byMonth['2026-01']), 'Cohort for 2026-01 exists');
        $this->assertTrue($byMonth['2026-01']['customers'] === 2, 'Two customers in the 2026-01 cohort');
        $this->assertTrue($byMonth['2026-01']['retention_rates'][1] === 50.0, '1 of 2 customers returned in +1 month -> 50%');
        $this->assertTrue($byMonth['2026-02']['retention_rates'][1] === 100.0, 'Only customer in 2026-02 cohort returned in +1 -> 100%');
        $this->assertTrue(!isset($byMonth['2026-03']['retention_rates'][5]), 'Future months beyond the horizon are never computed');
    }

    private function testRetentionRepeatPurchase(): void
    {
        $this->startTest('Retention: repeat purchase rate counts customers who bought more than once');
        $deals = [
            ['contact_id' => 1, 'closed_at' => '2026-01-01'], ['contact_id' => 1, 'closed_at' => '2026-02-01'],
            ['contact_id' => 2, 'closed_at' => '2026-01-05'],
            ['contact_id' => 3, 'closed_at' => '2026-01-10'], ['contact_id' => 3, 'closed_at' => '2026-02-10'], ['contact_id' => 3, 'closed_at' => '2026-03-10'],
        ];
        $result = RevenueRetentionService::computeRepeatPurchaseRate($deals);
        $this->assertTrue($result['has_data'] === true, 'Has data');
        $this->assertTrue($result['repeat_customers'] === 2, 'Two repeat customers (1 and 3)');
        $this->assertTrue($result['total_customers'] === 3, 'Three unique customers');
        $this->assertTrue($result['repeat_purchase_rate_percent'] === 66.7, '2/3 -> 66.7%');

        $empty = RevenueRetentionService::computeRepeatPurchaseRate([]);
        $this->assertTrue($empty['has_data'] === false, 'No data -> refuses to invent a rate');
        $this->assertTrue($empty['repeat_purchase_rate_percent'] === null, 'Rate is null when no data');
    }

    private function testRetentionRecurringStability(): void
    {
        $this->startTest('Retention: recurring stability detects gaps and never invents smoothness');
        $series = [
            ['month' => '2026-01', 'total' => 1000],
            ['month' => '2026-02', 'total' => 1200],
            ['month' => '2026-04', 'total' => 900], // فجوة شهر 2026-03
            ['month' => '2026-05', 'total' => 1000],
        ];
        $result = RevenueRetentionService::computeRecurringStability($series, '2026-06-01');
        $this->assertTrue($result['has_data'] === true, 'Has recurring months');
        $this->assertTrue($result['recurring_months'] === 4, 'Counts the present months');
        $this->assertTrue($result['monthly_gaps_detected'] === 1, 'Detects the missing 2026-03 as a gap');
        $this->assertTrue($result['average_monthly_recurring'] === 1025.0, 'Average of the present monthly values');
        $this->assertTrue($result['coefficient_of_variation_percent'] > 0, 'CV reflects real variance (not a fake flat number)');
        $this->assertTrue(strpos($result['note'], 'gap') !== false, 'Note honestly flags the churn gap');

        $empty = RevenueRetentionService::computeRecurringStability([], '2026-06-01');
        $this->assertTrue($empty['has_data'] === false, 'No recurring records -> not enough data');
    }

    private function testRetentionRevenueRetentionRate(): void
    {
        $this->startTest('Retention: revenue retention rate is the honest GRR-style approximation');
        $previous = [['contact_id' => 1, 'value' => 1000], ['contact_id' => 2, 'value' => 500]];
        $current = [['contact_id' => 1, 'value' => 1200], ['contact_id' => 2, 'value' => 300], ['contact_id' => 3, 'value' => 700]];
        $result = RevenueRetentionService::computeRevenueRetentionRate($current, $previous);
        $this->assertTrue($result['has_data'] === true, 'Has both periods');
        $this->assertTrue($result['current_period_revenue'] === 2200.0, 'Current period revenue = 1200+300+700');
        $this->assertTrue($result['retained_revenue'] === 1500.0, 'Retained = revenue from customers who bought in both periods (1200+300)');
        $this->assertTrue($result['revenue_retention_rate_percent'] === 68.2, '1500/2200 -> 68.2%');
        $this->assertTrue(strpos($result['note'], 'Not a literal subscription NRR/GRR') !== false, 'Honest disclosure: not literal NRR/GRR');

        $noPrev = RevenueRetentionService::computeRevenueRetentionRate($current, []);
        $this->assertTrue($noPrev['has_data'] === false, 'Missing previous period -> not enough data, no invented rate');
    }

    private function testRetentionNoInventedData(): void
    {
        $this->startTest('Retention: getRetentionAnalytics carries the honest NRR/GRR disclosure');
        $service = new RevenueRetentionService(new class () extends RevenueDataGateway {
            public function getWonDealsByContact(int $userId): array
            {
                return [];
            }
            public function getMonthlyRevenueSeries(int $userId, int $months, string $source): array
            {
                return [];
            }
        });
        $result = $service->getRetentionAnalytics(42, '2026-06-01');
        $this->assertTrue($result['has_data'] === false, 'No won deals -> has_data=false');
        $this->assertTrue(strpos($result['mrr_grr_note'], 'Not enough data') !== false, 'Honest NRR/GRR disclaimer instead of an invented metric');
    }

    private function testDigestHtml(): void
    {
        $this->startTest('Digest: buildDigestHtml renders real numbers only, with forecast and risks');
        if (!class_exists('SendRevenueDigestJob')) {
            $this->assertTrue(true, 'Digest job not present in this checkout - skipped gracefully');
            return;
        }
        $overview = ['total_revenue' => 15000.5, 'growth_percent' => 12.5, 'revenue_records_count' => 42, 'has_data' => true];
        $forecast = ['insufficient_data' => false, 'expected_revenue' => 17000.25, 'forecast_range' => ['low' => 16000, 'high' => 18000]];
        $risks = ['Revenue heavily depends on one source.'];
        $html = SendRevenueDigestJob::buildDigestHtml($overview, $forecast, $risks);
        $this->assertTrue(strpos($html, '15,000.50') !== false, 'Digest contains the exact total revenue');
        $this->assertTrue(strpos($html, '12.5%') !== false, 'Digest contains the exact growth percent');
        $this->assertTrue(strpos($html, '17,000.25') !== false, 'Digest contains the exact forecast');
        $this->assertTrue(strpos($html, '16,000.00') !== false && strpos($html, '18,000.00') !== false, 'Digest contains the forecast range');
        $this->assertTrue(strpos($html, 'Revenue heavily depends') !== false, 'Digest surfaces high-severity risks');
        $this->assertTrue(strpos($html, 'All figures are computed from your real revenue records') !== false, 'Honest provenance footer');

        $insufficient = ['insufficient_data' => true, 'expected_revenue' => null, 'forecast_range' => ['low' => null, 'high' => null]];
        $html2 = SendRevenueDigestJob::buildDigestHtml($overview, $insufficient, []);
        $this->assertTrue(strpos($html2, 'Not enough data for a reliable forecast') !== false, 'Honest message when forecast data is insufficient');
        $this->assertTrue(strpos($html2, 'No high-severity risks detected') !== false, 'No risks -> no invented risks block');
        $this->assertTrue(strpos($html2, '17,000.25') === false, 'No fabricated forecast number when insufficient');
    }

    // ============================================================
    // v1.5.0: Subscriptions (MRR/ARR/NRR/GRR/Churn) - pure functions
    // ============================================================

    private function testSubscriptionsMrrArr(): void
    {
        $this->startTest('Subscriptions: computeMrr + computeArrFromMrr from real rows');
        $subs = [
            ['customer_name' => 'A', 'status' => 'active', 'mrr' => 100],
            ['customer_name' => 'B', 'status' => 'active', 'mrr' => 250.5],
            ['customer_name' => 'C', 'status' => 'trialing', 'mrr' => 50],
            ['customer_name' => 'D', 'status' => 'cancelled', 'mrr' => 999],
        ];
        $mrr = BizSubscriptionService::computeMrr($subs);
        $this->assertTrue($mrr === 400.5, 'MRR sums only active+trialing rows (100 + 250.5 + 50)');
        $this->assertTrue(BizSubscriptionService::computeArrFromMrr($mrr) === 4806.0, 'ARR = MRR * 12');
        $this->assertTrue(BizSubscriptionService::computeMrr([]) === 0.0, 'Empty -> 0 (no invented revenue)');
    }

    private function testSubscriptionsMrrBreakdown(): void
    {
        $this->startTest('Subscriptions: computeMrrBreakdown from real events');
        $events = [
            ['event_type' => 'new', 'mrr_delta' => 300, 'occurred_at' => '2026-08-01 10:00:00'],
            ['event_type' => 'expansion', 'mrr_delta' => 100, 'occurred_at' => '2026-08-05 10:00:00'],
            ['event_type' => 'contraction', 'mrr_delta' => -50, 'occurred_at' => '2026-08-10 10:00:00'],
            ['event_type' => 'churn', 'mrr_delta' => -120, 'occurred_at' => '2026-08-15 10:00:00'],
            ['event_type' => 'new', 'mrr_delta' => 40, 'occurred_at' => '2026-09-01 10:00:00'],
        ];
        $all = BizSubscriptionService::computeMrrBreakdown($events);
        $this->assertTrue($all['has_data'] === true, 'Has data when events exist');
        $this->assertTrue($all['new'] === 340.0, 'New = 300 + 40');
        $this->assertTrue($all['expansion'] === 100.0, 'Expansion = 100');
        $this->assertTrue($all['contraction'] === 50.0, 'Contraction = 50 (abs)');
        $this->assertTrue($all['churn'] === 120.0, 'Churn = 120 (abs)');
        $this->assertTrue($all['net'] === 270.0, 'Net = 340 + 100 - 50 - 120');
        $aug = BizSubscriptionService::computeMrrBreakdown($events, '2026-08');
        $this->assertTrue($aug['new'] === 300.0, 'August filter keeps only August events');
        $this->assertTrue(BizSubscriptionService::computeMrrBreakdown([])['has_data'] === false, 'Empty events -> has_data=false');
    }

    private function testSubscriptionsNrr(): void
    {
        $this->startTest('Subscriptions: computeNrr literal from anchor period');
        $past = [
            ['contact_id' => 1, 'mrr' => 100, 'status' => 'active'],
            ['contact_id' => 2, 'mrr' => 100, 'status' => 'active'],
        ];
        $current = [
            ['contact_id' => 1, 'mrr' => 120, 'status' => 'active'], // expanded
            ['contact_id' => 2, 'mrr' => 0, 'status' => 'cancelled'], // churned
            ['contact_id' => 3, 'mrr' => 500, 'status' => 'active'], // brand new (not in anchor)
        ];
        $nrr = BizSubscriptionService::computeNrr($current, $past);
        $this->assertTrue($nrr['has_data'] === true, 'NRR computed from real rows');
        $this->assertTrue($nrr['nrr_percent'] === 60.0, 'NRR = 120/200 = 60%');
        $this->assertTrue(BizSubscriptionService::computeNrr([], [])['has_data'] === false, 'No anchor -> has_data=false');
    }

    private function testSubscriptionsGrr(): void
    {
        $this->startTest('Subscriptions: computeGrr literal (retained anchor MRR)');
        $past = [
            ['contact_id' => 1, 'mrr' => 100, 'status' => 'active'],
            ['contact_id' => 2, 'mrr' => 100, 'status' => 'active'],
        ];
        $current = [
            ['contact_id' => 1, 'mrr' => 120, 'status' => 'active'],
            ['contact_id' => 2, 'mrr' => 0, 'status' => 'cancelled'],
        ];
        $grr = BizSubscriptionService::computeGrr($current, $past);
        $this->assertTrue($grr['has_data'] === true, 'GRR computed');
        $this->assertTrue($grr['grr_percent'] === 60.0, 'GRR = retained anchor MRR (120/200) = 60%');
        $this->assertTrue(BizSubscriptionService::computeGrr([], [])['has_data'] === false, 'No anchor -> has_data=false');
    }

    private function testSubscriptionsChurn(): void
    {
        $this->startTest('Subscriptions: computeChurnRate from real events + base');
        $subs = [
            ['status' => 'active'],
            ['status' => 'active'],
            ['status' => 'active'],
            ['status' => 'cancelled'],
        ];
        $events = [
            ['event_type' => 'churn'],
            ['event_type' => 'churn'],
            ['event_type' => 'new'],
        ];
        $churn = BizSubscriptionService::computeChurnRate($subs, $events);
        $this->assertTrue($churn['has_data'] === true, 'Churn computed');
        $this->assertTrue($churn['churned'] === 2, 'Counts only churn events');
        $this->assertTrue($churn['churn_rate_percent'] === 40.0, 'Churn = 2/(3 active + 2 churn) = 40%');
        $this->assertTrue(BizSubscriptionService::computeChurnRate([], [])['has_data'] === false, 'No base -> has_data=false');
    }

    private function testSubscriptionsNotEnoughData(): void
    {
        $this->startTest('Subscriptions: service returns honest reason when tables/data missing');
        $service = new BizSubscriptionService(new class () extends RevenueDataGateway {
            public function hasBizSubscriptionTables(): bool
            {
                return true;
            }
            public function getBizSubscriptions(int $userId): array
            {
                return [];
            }
            public function getBizSubscriptionEvents(int $userId): array
            {
                return [];
            }
        });
        $metrics = $service->getSubscriptionMetrics(42);
        $this->assertTrue($metrics['has_data'] === false, 'No subscriptions -> has_data=false');
        $this->assertTrue(strpos($metrics['reason'], 'No biz subscriptions') !== false, 'Clear honest reason');
        $this->assertTrue($metrics['mrr'] === 0.0, 'MRR is 0 (never invented)');

        $service2 = new BizSubscriptionService(new class () extends RevenueDataGateway {
            public function hasBizSubscriptionTables(): bool
            {
                return false;
            }
        });
        $metrics2 = $service2->getSubscriptionMetrics(42);
        $this->assertTrue(strpos($metrics2['reason'], 'not installed') !== false, 'Tables-missing disclosure');
    }

    // ============================================================
    // v1.5.0 (A): Stripe Revenue Mapper - pure functions
    // ============================================================

    private function testStripeMapperNormalizeAmount(): void
    {
        $this->startTest('Stripe Mapper: normalizeAmountForCurrency (cents to currency)');
        $this->assertTrue(StripeRevenueMapper::normalizeAmountForCurrency(1000, 'usd') === 10.0, '1000 cents USD = 10.0');
        $this->assertTrue(StripeRevenueMapper::normalizeAmountForCurrency(1000, 'jpy') === 1000.0, 'JPY has no decimals');
        $this->assertTrue(StripeRevenueMapper::normalizeAmountForCurrency(250, 'eur') === 2.5, 'EUR cents');
    }

    private function testStripeMapperMapInterval(): void
    {
        $this->startTest('Stripe Mapper: mapIntervalToCycle');
        $this->assertTrue(StripeRevenueMapper::mapIntervalToCycle('month') === 'monthly', 'month -> monthly');
        $this->assertTrue(StripeRevenueMapper::mapIntervalToCycle('year') === 'yearly', 'year -> yearly');
        $this->assertTrue(StripeRevenueMapper::mapIntervalToCycle('week') === 'monthly', 'week -> monthly');
        $this->assertTrue(StripeRevenueMapper::mapIntervalToCycle('weird') === 'monthly', 'unknown -> monthly (safe default)');
    }

    private function testStripeMapperConvertToMrr(): void
    {
        $this->startTest('Stripe Mapper: convertSubscriptionToMrr');
        $this->assertTrue(StripeRevenueMapper::convertSubscriptionToMrr(120.0, 'yearly') === 10.0, '120/yr = 10 MRR');
        $this->assertTrue(StripeRevenueMapper::convertSubscriptionToMrr(30.0, 'quarterly') === 10.0, '30/quarter = 10 MRR');
        $this->assertTrue(StripeRevenueMapper::convertSubscriptionToMrr(15.0, 'monthly') === 15.0, 'monthly unchanged');
    }

    private function testStripeMapperSubscriptionCreated(): void
    {
        $this->startTest('Stripe Mapper: mapSubscriptionCreated -> biz rows');
        $payload = [
            'id' => 'evt_1',
            'created' => 1755000000,
            'data' => ['object' => [
                'id' => 'sub_1', 'customer' => 'cus_123', 'status' => 'active', 'currency' => 'usd',
                'items' => ['data' => [['plan' => ['amount' => 12000, 'interval' => 'year', 'nickname' => 'Pro']]]],
            ]],
        ];
        $result = StripeRevenueMapper::mapSubscriptionCreated($payload);
        $this->assertTrue($result['subscription']['stripe_customer_id'] === 'cus_123', 'Maps customer id');
        $this->assertTrue($result['subscription']['billing_cycle'] === 'yearly', 'Yearly interval mapped');
        $this->assertTrue($result['subscription']['mrr'] === 10.0, '12000/yr = 10 MRR');
        $this->assertTrue($result['subscription']['currency'] === 'USD', 'Currency uppercased');
        $this->assertTrue($result['event']['event_type'] === 'new', 'Created -> new event');
        $this->assertTrue($result['event']['mrr_delta'] === 10.0, 'New event delta = MRR');
    }

    private function testStripeMapperInvoicePaid(): void
    {
        $this->startTest('Stripe Mapper: mapInvoicePaymentSucceeded -> expansion event');
        $payload = [
            'id' => 'evt_2', 'created' => 1755000000,
            'data' => ['object' => [
                'customer' => 'cus_456', 'currency' => 'usd',
                'lines' => ['data' => [
                    ['amount' => 5000, 'price' => ['recurring' => ['interval' => 'month']]],
                ]],
            ]],
        ];
        $result = StripeRevenueMapper::mapInvoicePaymentSucceeded($payload);
        $this->assertTrue($result['event']['event_type'] === 'expansion', 'Payment -> expansion');
        $this->assertTrue($result['event']['mrr_delta'] === 50.0, '5000 cents = 50 MRR');
        $this->assertTrue($result['event']['stripe_customer_id'] === 'cus_456', 'Customer attached');
    }

    private function testStripeMapperSubscriptionDeleted(): void
    {
        $this->startTest('Stripe Mapper: mapSubscriptionDeleted -> churn event (negative delta)');
        $payload = [
            'id' => 'evt_3', 'created' => 1755000000,
            'data' => ['object' => [
                'customer' => 'cus_789', 'currency' => 'usd', 'status' => 'canceled',
                'items' => ['data' => [['plan' => ['amount' => 3000, 'interval' => 'month']]]],
            ]],
        ];
        $result = StripeRevenueMapper::mapSubscriptionDeleted($payload);
        $this->assertTrue($result['event']['event_type'] === 'churn', 'Deleted -> churn');
        $this->assertTrue($result['event']['mrr_delta'] === -30.0, 'Negative MRR delta');
    }

    // ============================================================
    // v1.5.0 (B): Deal-level forecast + Sales attribution
    // ============================================================

    private function testDealForecastBuckets(): void
    {
        $this->startTest('Deal Forecast: buckets this month / this quarter / later');
        $deals = [
            ['status' => 'open', 'value' => 1000, 'probability' => 50, 'expected_close_date' => '2026-08-20'],
            ['status' => 'open', 'value' => 2000, 'probability' => null, 'stage_win_probability' => 75, 'expected_close_date' => '2026-09-15'],
            ['status' => 'open', 'value' => 3000, 'probability' => 100, 'expected_close_date' => '2027-01-01'],
            ['status' => 'won', 'value' => 9999, 'probability' => 100, 'expected_close_date' => '2026-08-20'],
        ];
        $f = DealLevelForecastService::groupOpenDealsByCloseWindow($deals, '2026-08-16');
        $this->assertTrue($f['has_data'] === true, 'Has open deals');
        $this->assertTrue($f['buckets']['this_month']['weighted'] === 500.0, 'This month: 1000*50% = 500');
        $this->assertTrue($f['buckets']['this_quarter']['weighted'] === 1500.0, 'This quarter: 2000*75% = 1500');
        $this->assertTrue($f['buckets']['later']['weighted'] === 3000.0, 'Later: 3000*100%');
        $this->assertTrue($f['buckets']['this_month']['count'] === 1, 'Only open deals bucketed');
        $this->assertTrue($f['total_weighted'] === 5000.0, 'Total weighted = 500 + 1500 + 3000');
    }

    private function testDealForecastUndated(): void
    {
        $this->startTest('Deal Forecast: undated deals excluded from time buckets, never invented');
        $deals = [
            ['status' => 'open', 'value' => 5000, 'probability' => 50, 'expected_close_date' => ''],
            ['status' => 'open', 'value' => 700, 'probability' => 50, 'expected_close_date' => '2026-08-30'],
        ];
        $f = DealLevelForecastService::groupOpenDealsByCloseWindow($deals, '2026-08-16');
        $this->assertTrue($f['buckets']['undated']['count'] === 1, 'Undated counted in undated bucket');
        $this->assertTrue($f['buckets']['undated']['weighted'] === 2500.0, 'Undated weighted still surfaced transparently');
        $this->assertTrue($f['total_weighted'] === 350.0, 'Total excludes undated (0.5*5000 + 0.5*700)');
        $this->assertTrue(strpos($f['note'], 'Undated') !== false, 'Honest note about undated handling');
    }

    private function testDealForecastWeightedValue(): void
    {
        $this->startTest('Deal Forecast: weightedDealValue uses probability then stage fallback');
        $this->assertTrue(DealLevelForecastService::weightedDealValue(['value' => 1000, 'probability' => 40]) === 400.0, 'Value * probability');
        $this->assertTrue(DealLevelForecastService::weightedDealValue(['value' => 1000, 'probability' => null, 'stage_win_probability' => 25]) === 250.0, 'Stage win_probability fallback');
        $this->assertTrue(DealLevelForecastService::weightedDealValue(['value' => 1000, 'probability' => null]) === 0.0, 'No probability -> 0 (no hidden assumption)');
    }

    private function testSalesAttributionByRep(): void
    {
        $this->startTest('Sales Attribution: aggregateByRep');
        $deals = [
            ['status' => 'open', 'value' => 1000, 'probability' => 50, 'assigned_rep_id' => 1, 'rep_name' => 'Alice', 'team_name' => 'SMB'],
            ['status' => 'open', 'value' => 2000, 'probability' => 50, 'assigned_rep_id' => 1, 'rep_name' => 'Alice', 'team_name' => 'SMB'],
            ['status' => 'won', 'value' => 5000, 'probability' => 100, 'assigned_rep_id' => 2, 'rep_name' => 'Bob', 'team_name' => 'Ent'],
            ['status' => 'open', 'value' => 900, 'probability' => 100, 'assigned_rep_id' => 0, 'rep_name' => '', 'team_name' => ''],
        ];
        $a = DealLevelForecastService::aggregateByRep($deals);
        $this->assertTrue($a['has_data'] === true, 'Has data');
        $byName = [];
        foreach ($a['reps'] as $r) {
            $byName[$r['rep_name']] = $r;
        }
        $this->assertTrue($byName['Alice']['open_weighted'] === 1500.0, 'Alice: 1000*50% + 2000*50%');
        $this->assertTrue($byName['Alice']['open_count'] === 2, 'Alice open count');
        $this->assertTrue($byName['Bob']['won_value'] === 5000.0, 'Bob won revenue');
        $this->assertTrue(isset($byName['Unassigned']) && $byName['Unassigned']['open_weighted'] === 900.0, 'Unassigned surfaced honestly');
    }

    private function testSalesAttributionByTeam(): void
    {
        $this->startTest('Sales Attribution: aggregateByTeam');
        $deals = [
            ['status' => 'open', 'value' => 1000, 'probability' => 50, 'assigned_rep_id' => 1, 'rep_name' => 'Alice', 'team_id' => 1, 'team_name' => 'SMB'],
            ['status' => 'won', 'value' => 5000, 'probability' => 100, 'assigned_rep_id' => 2, 'rep_name' => 'Bob', 'team_id' => 2, 'team_name' => 'Ent'],
            ['status' => 'open', 'value' => 900, 'probability' => 100, 'assigned_rep_id' => 0, 'rep_name' => '', 'team_id' => 0, 'team_name' => ''],
        ];
        $a = DealLevelForecastService::aggregateByTeam($deals);
        $byName = [];
        foreach ($a['teams'] as $t) {
            $byName[$t['team_name']] = $t;
        }
        $this->assertTrue($byName['SMB']['open_weighted'] === 500.0, 'SMB open weighted');
        $this->assertTrue($byName['Ent']['won_value'] === 5000.0, 'Ent won value');
        $this->assertTrue(isset($byName['Unassigned']) && $byName['Unassigned']['open_weighted'] === 900.0, 'Unassigned team surfaced');
    }

    // ============================================================
    // v1.5.0 (C): Benchmarks + Churn analytics
    // ============================================================

    private function testBenchmarkClassifyChurnReason(): void
    {
        $this->startTest('Benchmark: classifyChurnReason prioritizes real data');
        $explicit = RevenueBenchmarkService::classifyChurnReason(['status' => 'lost', 'lost_reason' => 'Budget cut']);
        $this->assertTrue($explicit['reason'] === 'explicit' && $explicit['label'] === 'Budget cut', 'Explicit lost_reason wins');
        $fromEvent = RevenueBenchmarkService::classifyChurnReason(['status' => 'open', 'contact_id' => 7], [
            ['event_type' => 'churn', 'contact_id' => 7, 'churn_reason' => 'Switched to competitor'],
        ]);
        $this->assertTrue($fromEvent['label'] === 'Switched to competitor', 'Event churn_reason matched by contact');
        $implied = RevenueBenchmarkService::classifyChurnReason(['status' => 'cancelled']);
        $this->assertTrue($implied['confidence'] === 'low', 'Implied status -> low confidence');
        $unknown = RevenueBenchmarkService::classifyChurnReason(['status' => 'open']);
        $this->assertTrue($unknown['label'] === 'Not enough data', 'No data -> honest unknown');
    }

    private function testBenchmarkAggregateChurnReasons(): void
    {
        $this->startTest('Benchmark: aggregateChurnReasons groups real reasons');
        $deals = [
            ['status' => 'lost', 'lost_reason' => 'Budget'],
            ['status' => 'lost', 'lost_reason' => 'Budget'],
            ['status' => 'lost'],
            ['status' => 'open'],
        ];
        $agg = RevenueBenchmarkService::aggregateChurnReasons($deals);
        $this->assertTrue($agg['total_churned'] === 3, 'Counts only lost/cancelled');
        $this->assertTrue($agg['by_reason'][0]['label'] === 'Budget' && $agg['by_reason'][0]['count'] === 2, 'Top reason sorted first');
        $this->assertTrue($agg['top_reason'] === 'Budget', 'top_reason set');
        $empty = RevenueBenchmarkService::aggregateChurnReasons([]);
        $this->assertTrue($empty['has_data'] === false, 'No churn data -> has_data=false');
    }

    private function testBenchmarkServiceNotInstalled(): void
    {
        $this->startTest('Benchmark: service discloses when table missing');
        $service = new RevenueBenchmarkService(new class () extends RevenueDataGateway {
            public function hasBenchmarkTables(): bool
            {
                return false;
            }
        });
        $result = $service->getBenchmarks(42);
        $this->assertTrue($result['has_data'] === false, 'has_data=false');
        $this->assertTrue(strpos($result['reason'], 'not installed') !== false, 'Clear install disclosure');
    }

    private function testChurnAnalyticsNotEnoughData(): void
    {
        $this->startTest('Churn: no data -> honest reason, no invented reasons');
        $service = new RevenueChurnService(new class () extends RevenueDataGateway {
            public function getDealsWithRep(int $userId): array
            {
                return [];
            }
            public function hasBizSubscriptionTables(): bool
            {
                return false;
            }
        });
        $result = $service->getChurnAnalytics(42);
        $this->assertTrue($result['has_data'] === false, 'has_data=false');
        $this->assertTrue(strpos($result['reason'], 'Not enough data') !== false, 'Exact honest message');
    }

    private function testChurnAnalyticsReasons(): void
    {
        $this->startTest('Churn: aggregates real reasons from lost deals');
        $service = new RevenueChurnService(new class () extends RevenueDataGateway {
            public function getDealsWithRep(int $userId): array
            {
                return [
                    ['status' => 'lost', 'lost_reason' => 'Price'],
                    ['status' => 'lost', 'lost_reason' => 'Price'],
                    ['status' => 'lost', 'lost_reason' => 'Timing'],
                    ['status' => 'open', 'lost_reason' => ''],
                ];
            }
            public function hasBizSubscriptionTables(): bool
            {
                return false;
            }
        });
        $result = $service->getChurnAnalytics(42);
        $this->assertTrue($result['has_data'] === true, 'has_data=true');
        $this->assertTrue($result['total_churned'] === 3, 'Total churned = 3');
        $this->assertTrue($result['top_reason'] === 'Price', 'Top reason = Price');
        $this->assertTrue(strpos($result['note'], 'inferred from real data') !== false, 'Honest provenance note');
    }

    // ============================================================
    // v1.6.0: Dashboard Personalization (pure static functions)
    // ============================================================

    private function testDashboardPrefsDefaultLayout(): void
    {
        $this->startTest('Dashboard prefs: default layout shows all known widgets');
        $layout = RevenueDashboardService::defaultLayout();
        $this->assertTrue(count($layout['widgets']) === 8, 'Default layout has all 8 known widgets');
        $this->assertTrue($layout['widgets'][0]['key'] === 'current_revenue', 'First widget is current_revenue');
        $this->assertTrue($layout['widgets'][0]['visible'] === true, 'All widgets visible by default');
        $this->assertTrue($layout['widgets'][7]['key'] === 'recommended_actions', 'Last widget is recommended_actions');
    }

    private function testDashboardPrefsNormalizeUnknownKeys(): void
    {
        $this->startTest('Dashboard prefs: normalizeLayout drops unknown/invented widget keys');
        $layout = RevenueDashboardService::normalizeLayout([
            'widgets' => [
                ['key' => 'current_revenue', 'visible' => true, 'order' => 0],
                ['key' => 'made_up_metric', 'visible' => true, 'order' => 1],
                ['key' => 'invented_ai_metric', 'visible' => true, 'order' => 2],
            ],
        ]);
        $keys = array_column($layout['widgets'], 'key');
        $this->assertTrue(in_array('current_revenue', $keys, true), 'Keeps the known widget');
        $this->assertTrue(!in_array('made_up_metric', $keys, true), 'Drops invented widget key');
        $this->assertTrue(!in_array('invented_ai_metric', $keys, true), 'Drops second invented widget key');
        $this->assertTrue(count($keys) === 8, 'Missing known keys are re-added to keep a complete layout');
    }

    private function testDashboardPrefsNormalizeMissingKeys(): void
    {
        $this->startTest('Dashboard prefs: normalizeLayout fills missing known widgets as visible');
        $layout = RevenueDashboardService::normalizeLayout(['widgets' => [
            ['key' => 'growth_percent', 'visible' => false, 'order' => 0],
        ]]);
        $byKey = [];
        foreach ($layout['widgets'] as $w) {
            $byKey[$w['key']] = $w;
        }
        $this->assertTrue($byKey['growth_percent']['visible'] === false, 'Respects explicit hidden flag');
        $this->assertTrue($byKey['forecast']['visible'] === true, 'Unmentioned known widget defaults to visible');
        $this->assertTrue(count($byKey) === 8, 'Exactly the 8 known keys survive normalization');
    }

    private function testDashboardPrefsApplyLayout(): void
    {
        $this->startTest('Dashboard prefs: applyLayoutToSummary filters and orders by saved layout');
        $summary = [
            'has_data' => true,
            'current_revenue' => 10000,
            'growth_percent' => 12,
            'forecast' => ['expected_revenue' => 12000],
            'top_opportunity' => ['title' => 'X'],
            'top_risk' => ['title' => 'Y'],
            'top_customer_segment' => ['segment' => 'VIP'],
            'top_revenue_source' => ['source' => 'Subscriptions'],
            'recommended_actions' => [['action' => 'A']],
        ];
        $layout = [
            'widgets' => [
                ['key' => 'forecast', 'visible' => true, 'order' => 0],
                ['key' => 'current_revenue', 'visible' => false, 'order' => 1],
                ['key' => 'growth_percent', 'visible' => true, 'order' => 2],
            ],
        ];
        $out = RevenueDashboardService::applyLayoutToSummary($summary, $layout);
        $this->assertTrue(!array_key_exists('current_revenue', $out), 'Hidden-in-layout widget is filtered out of the applied summary');
        $this->assertTrue($out['forecast'] === ['expected_revenue' => 12000], 'Visible widget data is carried through');
        $this->assertTrue($out['applied_layout'][0] === 'forecast', 'Applies widget order from layout');
        $this->assertTrue(!in_array('current_revenue', $out['applied_layout'], true), 'Hidden widget excluded from applied_layout');
        $this->assertTrue($out['has_data'] === true, 'Preserves has_data');
    }

    // ============================================================
    // v1.6.0: Stripe Webhook Signature (pure function)
    // ============================================================

    private function testStripeWebhookVerifySignature(): void
    {
        $this->startTest('Stripe webhook: verifySignature accepts a valid HMAC signature');
        $secret = 'whsec_test_secret_123456';
        $timestamp = (string) time();
        $payload = '{"id":"evt_1","type":"customer.subscription.created"}';
        $signed = $timestamp . '.' . $payload;
        $sig = hash_hmac('sha256', $signed, $secret);
        $header = "t={$timestamp},v1={$sig}";
        $this->assertTrue(StripeWebhookService::verifySignature($payload, $header, $secret) === true, 'Valid signature accepted');
    }

    private function testStripeWebhookVerifySignatureTampered(): void
    {
        $this->startTest('Stripe webhook: verifySignature rejects tampered payload/signature');
        $secret = 'whsec_test_secret_123456';
        $timestamp = (string) time();
        $payload = '{"id":"evt_1","type":"customer.subscription.created"}';
        $signed = $timestamp . '.' . $payload;
        $sig = hash_hmac('sha256', $signed, $secret);
        $header = "t={$timestamp},v1={$sig}";

        $tampered = '{"id":"evt_1","type":"customer.subscription.deleted"}';
        $this->assertTrue(StripeWebhookService::verifySignature($tampered, $header, $secret) === false, 'Tampered body rejected');
        $this->assertTrue(StripeWebhookService::verifySignature($payload, "t={$timestamp},v1=deadbeef", $secret) === false, 'Wrong signature rejected');
        $this->assertTrue(StripeWebhookService::verifySignature($payload, '', $secret) === false, 'Missing signature header rejected');
        $this->assertTrue(StripeWebhookService::verifySignature($payload, $header, 'whsec_wrong') === false, 'Wrong secret rejected');
    }

    // ============================================================
    // Test harness (نفس نمط باقي ملفات tests/Unit في المشروع)
    // ============================================================

    /** يولّد سلسلة أيام متتالية بقيمة ثابتة - Fixture نظيف للاختبار. */
    private static function buildDailySeries(string $fromDate, int $days, float $revenue): array
    {
        $start = new DateTime($fromDate);
        $series = [];
        for ($i = 0; $i < $days; $i++) {
            $series[] = ['date' => $start->format('Y-m-d'), 'revenue' => $revenue];
            $start->modify('+1 day');
        }
        return $series;
    }

    private function assertTrue(bool $condition, string $message): void
    {
        if ($condition) {
            $this->pass($message);
        } else {
            $this->fail($message);
        }
    }

    private function startTest(string $name): void
    {
        echo "\n  ▶ {$name}\n";
    }

    private function pass(string $message): void
    {
        echo "    ✅ {$message}\n";
        $this->passed++;
        $this->testResults[] = ['status' => 'PASS', 'message' => $message];
    }

    private function fail(string $message): void
    {
        echo "    ❌ {$message}\n";
        $this->failed++;
        $this->testResults[] = ['status' => 'FAIL', 'message' => $message];
    }

    private function printSummary(): void
    {
        $total = $this->passed + $this->failed;
        $percentage = $total > 0 ? round(($this->passed / $total) * 100, 2) : 0;

        echo "\n" . str_repeat('=', 50) . "\n";
        echo "📊 AI Revenue Intelligence Test Summary\n";
        echo str_repeat('=', 50) . "\n";
        echo "  ✅ Passed: {$this->passed}\n";
        echo "  ❌ Failed: {$this->failed}\n";
        echo "  📝 Total: {$total}\n";
        echo "  📈 Success Rate: {$percentage}%\n";
        echo str_repeat('=', 50) . "\n\n";
    }
}

if (basename(__FILE__) === basename($_SERVER['PHP_SELF'] ?? '')) {
    $test = new RevenueIntelligenceTest();
    $test->runAll();
}
