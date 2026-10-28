<?php

/**
 * Tourfecto - Executive Suite (Module 5) Integration Test
 * بيفحص ثلاث خدمات الإدارة التنفيذية بمصادر بيانات حقيقية:
 *   1) ExecutiveDashboardService: الدرجات الست من بيانات حقيقية (wo_audits,
 *      competitors, reviews, ai_articles, tracked_keywords) مع null للمصادر
 *      الفارغة، + Top Opportunities/Problems من جداول CEO والتدقيق، +
 *      RecentChanges من auto_pilot_change_log، + لقطة المنافسين.
 *   2) CeoAdvisorService: gatherAccountSnapshot بتجميع كل المصادر فعلًا
 *      (websites/wo_audits/competitors/tracked_keywords/outreach_prospects/
 *      api_usage_logs/ملاحظات/مخاطر/فرص) + ask() مع محرك AI وهمي (صفر شبكة).
 *   3) ActionCenterService: getActionItems بتجميع 8 مصادر موحّدة + فلتر
 *      الموقع؛ ActionCenterExecutionService بيحوّل العناصر لإجراءات تنفيذية
 *      (3 مصادر executable بس)؛ ActionCenterExecutor بينفّذ فعلًا (taskCreator/
 *      notifier وهميين + action_executions حقيقي + منع تكرار + وسم ci_insights
 *      actioned).
 *
 * صفر شبكة/AI حقيقية. معرّفات معزولة: المستخدم 999800، الموقع 999850.
 * @version 1.0.0  @date 2026-08-31
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/Core/Model.php';
require_once __DIR__ . '/../../app/Core/Logger.php';
require_once __DIR__ . '/../../app/Services/ExecutiveDashboard/ExecutiveDashboardService.php';
require_once __DIR__ . '/../../app/Services/CeoAdvisor/CeoAdvisorService.php';
require_once __DIR__ . '/../../app/Services/ActionCenter/ActionCenterService.php';
require_once __DIR__ . '/../../app/Services/ActionCenter/ActionCenterExecutionService.php';
require_once __DIR__ . '/../../app/Services/Execution/ActionExecutor.php';
require_once __DIR__ . '/../../app/Services/ActionCenter/ActionCenterExecutor.php';

final class FakeCeoAi
{
    public array $calls = [];
    private array $response;

    public function __construct(array $response)
    {
        $this->response = $response;
    }

    public function generateContent(string $prompt, array $options = []): array
    {
        $this->calls[] = ['prompt' => $prompt, 'options' => $options];
        return $this->response;
    }
}

final class ExecutiveSuiteModuleIntegrationTest extends TestCase
{
    private const USER = 999800;
    private const WEBSITE = 999850;
    private const WEBSITE2 = 999851;

    private static ?PDO $pdo = null;
    private static bool $dbChecked = false;

    private function db(): ?PDO
    {
        if (self::$dbChecked) {
            return self::$pdo;
        }
        self::$dbChecked = true;

        try {
            $app = dirname(__DIR__, 2) . '/app';
            if (!defined('APP_ENV')) {
                foreach ([
                    $app . '/Config/app.php',
                    $app . '/Config/database.php',
                    $app . '/Config/encryption.php',
                    $app . '/Config/constants.php',
                ] as $cfg) {
                    if (file_exists($cfg)) {
                        require_once $cfg;
                    }
                }
            }
            if (!class_exists('Database') && file_exists($app . '/Core/Database.php')) {
                require_once $app . '/Core/Database.php';
            }

            $db = Database::getInstance();
            $ref = new ReflectionProperty(Database::class, 'connection');
            $ref->setAccessible(true);
            $conn = $ref->getValue($db);
            if (!$conn instanceof PDO) {
                self::$pdo = null;
                return null;
            }

            $required = [
                'websites', 'wo_audits', 'wo_audit_findings', 'wo_fixes',
                'competitors', 'tracked_keywords', 'reviews', 'ai_articles',
                'ceo_growth_opportunities', 'ceo_risk_alerts', 'ceo_business_context_notes',
                'auto_pilot_change_log', 'generated_websites', 'outreach_prospects',
                'outreach_emails', 'cc_ai_tasks', 'ci_insights',
                'ai_assistant_interactions', 'action_executions', 'api_usage_logs',
            ];
            foreach ($required as $t) {
                if (empty($conn->query("SHOW TABLES LIKE '{$t}'")->fetchAll())) {
                    self::$pdo = null;
                    return null;
                }
            }

            self::$pdo = $conn;
            return self::$pdo;
        } catch (\Throwable $e) {
            return null;
        }
    }

    protected function setUp(): void
    {
        $pdo = $this->db();
        if ($pdo === null) {
            $this->markTestSkipped('DB غير متاحة أو بعض جداول الموديول التنفيذي غير موجودة');
        }
        $this->cleanup();

        $pdo->exec("INSERT INTO users (id, email, password, company_name, created_at)
                    VALUES (999800, 'exec@tourfecto.test', 'x', 'Exec User', NOW())
                    ON DUPLICATE KEY UPDATE email = email");
        $pdo->exec("INSERT INTO websites (id, user_id, main_url, company_name)
                    VALUES (999850, 999800, 'https://exec-site.test', 'Exec Site')
                    ON DUPLICATE KEY UPDATE user_id = 999800");
        $pdo->exec("INSERT INTO websites (id, user_id, main_url, company_name)
                    VALUES (999851, 999800, 'https://other-site.test', 'Other Site')
                    ON DUPLICATE KEY UPDATE user_id = 999800");
    }

    protected function tearDown(): void
    {
        $pdo = self::$pdo;
        if ($pdo === null) {
            return;
        }
        $this->cleanup();
    }

    private function cleanup(): void
    {
        $pdo = self::$pdo;
        $u = 999800;
        $w = 999850;
        $w2 = 999851;

        $pdo->exec("DELETE FROM action_executions WHERE user_id = {$u}");
        $pdo->exec("DELETE FROM notifications WHERE user_id = {$u}");
        $pdo->exec("DELETE FROM api_usage_logs WHERE user_id = {$u}");
        $pdo->exec("DELETE FROM cc_ai_tasks WHERE user_id = {$u}");
        $pdo->exec("DELETE FROM ceo_risk_alerts WHERE user_id = {$u}");
        $pdo->exec("DELETE FROM ceo_growth_opportunities WHERE user_id = {$u}");
        $pdo->exec("DELETE FROM ceo_business_context_notes WHERE user_id = {$u}");
        $pdo->exec("DELETE FROM ai_assistant_interactions WHERE user_id = {$u}");
        $pdo->exec("DELETE FROM crm_tasks WHERE user_id = {$u}");
        $pdo->exec("DELETE FROM ai_articles WHERE user_id = {$u}");
        $pdo->exec("DELETE FROM ci_insights WHERE user_id = {$u}");
        $pdo->exec("DELETE FROM tracked_keywords WHERE user_id = {$u}");
        $pdo->exec("DELETE FROM reviews WHERE user_id = {$u}");
        $pdo->exec("DELETE FROM competitors WHERE user_id = {$u}");
        $pdo->exec("DELETE FROM outreach_emails WHERE prospect_id IN (SELECT id FROM outreach_prospects WHERE user_id = {$u})");
        $pdo->exec("DELETE FROM outreach_prospects WHERE user_id = {$u}");
        $pdo->exec("DELETE FROM wo_audit_findings WHERE audit_id IN (SELECT id FROM wo_audits WHERE user_id = {$u})");
        $pdo->exec("DELETE FROM wo_fixes WHERE audit_id IN (SELECT id FROM wo_audits WHERE user_id = {$u})");
        $pdo->exec("DELETE FROM wo_audits WHERE user_id = {$u}");
        $pdo->exec("DELETE FROM auto_pilot_change_log WHERE generated_website_id IN (SELECT id FROM generated_websites WHERE user_id = {$u})");
        $pdo->exec("DELETE FROM generated_websites WHERE user_id = {$u}");
        $pdo->exec("DELETE FROM websites WHERE id IN ({$w}, {$w2})");
        $pdo->exec("DELETE FROM users WHERE id = {$u}");
    }

    private function dbInstance(): Database
    {
        return Database::getInstance();
    }

    private function exec(string $sql): void
    {
        self::$pdo->exec($sql);
    }

    // ================================================================
    // ExecutiveDashboardService
    // ================================================================

    private function seedScoreData(): void
    {
        $this->exec("INSERT INTO wo_audits (website_id, user_id, status, overall_score, completed_at)
                     VALUES (999850, 999800, 'completed', 80, NOW())");
        $this->exec("INSERT INTO competitors (website_id, user_id, competitor_name, competitor_domain, my_score, last_analyzed_at)
                     VALUES (999850, 999800, 'Rival A', 'rival.test', 70, NOW()),
                            (999850, 999800, 'Rival B', 'rivalb.test', 90, NOW())");
        $this->exec("INSERT INTO reviews (website_id, user_id, source_platform, review_text, rating, sentiment)
                     VALUES (999850, 999800, 'google_business', 'Great!', 5, 'positive'),
                            (999850, 999800, 'google_business', 'Nice!', 5, 'positive')");
        for ($i = 0; $i < 5; $i++) {
            $faq = $i < 2 ? "'[{\"q\":\"q{$i}\"}]'" : 'NULL';
            $this->exec("INSERT INTO ai_articles (user_id, website_id, topic, status, faqs_json)
                         VALUES (999800, 999850, 'topic{$i}', 'completed', {$faq})");
        }
        $this->exec("INSERT INTO tracked_keywords (user_id, website_id, keyword, priority, opportunity_score, target_page)
                     VALUES (999800, 999850, 'nile cruise', 'high', 100, 'https://exec-site.test/nile'),
                            (999800, 999850, 'cairo tours', 'high', 100, 'https://exec-site.test/cairo')");
    }

    public function testScoresComputeFromRealData(): void
    {
        $this->seedScoreData();

        $scores = (new ExecutiveDashboardService())->getScores($this->dbInstance(), 999800, 999850);

        $this->assertSame(80.0, $scores['seo_score']);
        $this->assertSame(80.0, $scores['competitor_score']);
        $this->assertSame(100.0, $scores['reputation_score']);
        $this->assertSame(47.0, $scores['content_score']);
        $this->assertSame(100.0, $scores['visibility_score']);
        $this->assertSame(81.4, $scores['overall_growth_score']);
    }

    public function testScoresAllNullWhenNoData(): void
    {
        $scores = (new ExecutiveDashboardService())->getScores($this->dbInstance(), 999800, 999850);

        $this->assertNull($scores['seo_score']);
        $this->assertNull($scores['competitor_score']);
        $this->assertNull($scores['reputation_score']);
        $this->assertNull($scores['content_score']);
        $this->assertNull($scores['visibility_score']);
        $this->assertNull($scores['overall_growth_score']);
    }

    public function testTopOpportunitiesMixAndSort(): void
    {
        $this->exec("INSERT INTO ceo_growth_opportunities (user_id, title, description, estimated_impact, status)
                     VALUES (999800, 'Enter Saudi market', 'x', 'high', 'new')");
        $this->exec("INSERT INTO tracked_keywords (user_id, website_id, keyword, priority, opportunity_score, target_page)
                     VALUES (999800, 999850, 'desert safari', 'high', 90, NULL)");

        $opps = (new ExecutiveDashboardService())->getTopOpportunities($this->dbInstance(), 999800, 999850, 2);

        $this->assertCount(2, $opps);
        $this->assertSame('high', $opps[0]['impact'], 'الفرصة عالية الأثر أولًا');
        $sources = array_column($opps, 'source');
        $this->assertContains('growth_opportunity', $sources);
        $this->assertContains('keyword', $sources);
    }

    public function testTopProblemsSortBySeverity(): void
    {
        $this->exec("INSERT INTO wo_audits (website_id, user_id, status, overall_score, completed_at)
                     VALUES (999850, 999800, 'completed', 60, NOW())");
        $this->exec("INSERT INTO wo_audit_findings (audit_id, category, title, status, severity)
                     VALUES (LAST_INSERT_ID(), 'technical', 'Broken schema', 'fail', 'critical')");
        $this->exec("INSERT INTO ceo_risk_alerts (user_id, title, description, severity, is_resolved)
                     VALUES (999800, 'Churn risk', 'x', 'high', 0)");

        $problems = (new ExecutiveDashboardService())->getTopProblems($this->dbInstance(), 999800, 999850, 2);

        $this->assertCount(2, $problems);
        $this->assertSame('critical', $problems[0]['severity']);
        $this->assertSame('seo_finding', $problems[0]['source']);
        $this->assertSame('risk_alert', $problems[1]['source']);
    }

    public function testRecentChangesExcludesRolledBack(): void
    {
        $this->exec("INSERT INTO generated_websites (user_id, slug, content_json)
                     VALUES (999800, 'exec-site', '{}')");
        $gid = (int) self::$pdo->lastInsertId();
        $this->exec("INSERT INTO auto_pilot_change_log (generated_website_id, field_name, old_value, new_value, `trigger`, applied_at, rolled_back_at)
                     VALUES ({$gid}, 'title', 'Old', 'New', 'manual_click', NOW(), NULL),
                            ({$gid}, 'title', 'X', 'Y', 'audit_auto_pilot', NOW(), NOW())");

        $changes = (new ExecutiveDashboardService())->getRecentChanges($this->dbInstance(), 999800, 999850, 5);

        $this->assertCount(1, $changes);
        $this->assertSame('title', $changes[0]['field_name']);
        $this->assertSame('New', $changes[0]['new_value']);
    }

    public function testCompetitorSnapshotLimitedToFiveLatest(): void
    {
        for ($i = 1; $i <= 6; $i++) {
            $this->exec("INSERT INTO competitors (website_id, user_id, competitor_name, competitor_domain, my_score, competitor_score, last_analyzed_at)
                         VALUES (999850, 999800, 'Rival {$i}', 'r{$i}.test', 60, 70, NOW())");
        }

        $snap = (new ExecutiveDashboardService())->getCompetitorSnapshot($this->dbInstance(), 999800, 999850);

        $this->assertCount(5, $snap);
    }

    // ================================================================
    // CeoAdvisorService
    // ================================================================

    private function seedSnapshotData(): void
    {
        $this->exec("INSERT INTO wo_audits (website_id, user_id, status, overall_score, completed_at)
                     VALUES (999850, 999800, 'completed', 88, NOW())");
        $this->exec("INSERT INTO competitors (website_id, user_id, competitor_name, competitor_domain, my_score, competitor_score, last_analyzed_at)
                     VALUES (999850, 999800, 'Rival A', 'rival.test', 80, 60, NOW())");
        $this->exec("INSERT INTO tracked_keywords (user_id, website_id, keyword, priority, opportunity_score)
                     VALUES (999800, 999850, 'kw high', 'high', 90),
                            (999800, 999850, 'kw low', 'low', 40),
                            (999800, 999850, 'kw mid', 'medium', 50)");
        $statuses = ['prospect', 'contacted', 'replied', 'negotiating', 'link_acquired'];
        foreach ($statuses as $s) {
            $this->exec("INSERT INTO outreach_prospects (user_id, website_id, domain, status)
                         VALUES (999800, 999850, 'domain-{$s}.test', '{$s}')");
        }
        $this->exec("INSERT INTO api_usage_logs (user_id, api_type, cost_in_usd)
                     VALUES (999800, 'gemini', 1.25)");
        $this->exec("INSERT INTO ceo_business_context_notes (user_id, note)
                     VALUES (999800, 'ملاحظة استراتيجية')");
        $this->exec("INSERT INTO ceo_risk_alerts (user_id, title, severity, is_resolved)
                     VALUES (999800, 'مخاطرة سعر الصرف', 'high', 0)");
        $this->exec("INSERT INTO ceo_growth_opportunities (user_id, title, estimated_impact, status)
                     VALUES (999800, 'فرصة الشرق الأوسط', 'high', 'new')");
    }

    public function testGatherAccountSnapshotAggregatesRealData(): void
    {
        $this->seedSnapshotData();

        $snap = (new CeoAdvisorService())->gatherAccountSnapshot($this->dbInstance(), 999800);

        $this->assertSame(2, $snap['websites_count']);
        $this->assertCount(1, $snap['seo_scores']);
        $this->assertSame(88.0, $snap['seo_scores'][0]['score']);
        $this->assertCount(1, $snap['competitor_comparisons']);
        $this->assertSame(1, $snap['keyword_opportunities']['high_priority_count']);
        $this->assertSame(3, $snap['keyword_opportunities']['total_tracked']);
        $this->assertSame(1, $snap['outreach_pipeline']['prospect']);
        $this->assertSame(1, $snap['outreach_pipeline']['contacted']);
        $this->assertSame(1, $snap['outreach_pipeline']['link_acquired']);
        $this->assertSame(1.25, $snap['ai_cost_this_month']);
        $this->assertSame(['ملاحظة استراتيجية'], $snap['manual_notes']);
        $this->assertCount(1, $snap['open_risks']);
        $this->assertCount(1, $snap['open_opportunities']);
    }

    public function testAskReturnsAnswerFromSnapshot(): void
    {
        $this->seedSnapshotData();
        $fake = new FakeCeoAi(['success' => true, 'data' => 'ابدأ بسوق الشرق الأوسط', 'provider' => 'gemini']);
        $service = new CeoAdvisorService($fake);

        $res = $service->ask($this->dbInstance(), 999800, 'إيه أول خطوة أتخذها؟');

        $this->assertTrue($res['success']);
        $this->assertSame('ابدأ بسوق الشرق الأوسط', $res['answer']);
        $this->assertSame(2, $res['snapshot_used']['websites_count']);
        $this->assertSame('gemini', $res['provider']);
        $this->assertCount(1, $fake->calls);
        $this->assertStringContainsString('الشرق الأوسط', $fake->calls[0]['prompt'] ?? '');
    }

    public function testAskRejectsEmptyQuestion(): void
    {
        $res = (new CeoAdvisorService())->ask($this->dbInstance(), 999800, '   ');

        $this->assertFalse($res['success']);
        $this->assertStringContainsString('سؤالك', (string) $res['error']);
    }

    public function testAskRequiresWebsites(): void
    {
        // Defensive cleanup: 999801 لازم مايكونش ليه مواقع - أي صف متبقّي من
        // اختبار سابق في نفس الجلسة (ترتيب عشوائي) ممكن يخلي السيناريو ده
        // فاشل. بنمسحه قبل التنفيذ بدل الاعتماد على حالة قاعدة مشتركة.
        self::$pdo->exec("DELETE FROM websites WHERE user_id = 999801");

        $fake = new FakeCeoAi(['success' => true, 'data' => 'x']);
        $res = (new CeoAdvisorService($fake))->ask($this->dbInstance(), 999801, 'سؤال');
        // مستخدم 999801 مالهوش مواقع
        $this->assertFalse($res['success']);
        $this->assertStringContainsString('موقع', (string) $res['error']);
    }

    public function testAskAiFailureSurfaces(): void
    {
        $this->seedSnapshotData();
        $fake = new FakeCeoAi(['success' => false, 'error' => 'AI down']);
        $res = (new CeoAdvisorService($fake))->ask($this->dbInstance(), 999800, 'سؤال');

        $this->assertFalse($res['success']);
        $this->assertStringContainsString('AI down', (string) $res['error']);
    }

    // ================================================================
    // ActionCenterService
    // ================================================================

    private function seedActionItems(): void
    {
        $this->exec("INSERT INTO wo_audits (website_id, user_id, status, overall_score, completed_at)
                     VALUES (999850, 999800, 'completed', 60, NOW())");
        $this->exec("INSERT INTO wo_fixes (audit_id, category, title, description, status)
                     VALUES (LAST_INSERT_ID(), 'technical', 'Fix meta descriptions', 'desc', 'pending')");

        $this->exec("INSERT INTO outreach_prospects (user_id, website_id, domain, status, updated_at)
                     VALUES (999800, 999850, 'follow.test', 'contacted', DATE_SUB(NOW(), INTERVAL 6 DAY))");
        $pid = (int) self::$pdo->lastInsertId();
        $this->exec("INSERT INTO outreach_emails (prospect_id, subject, body, status)
                     VALUES ({$pid}, 'Draft subject', 'body', 'draft')");

        $this->exec("INSERT INTO cc_ai_tasks (user_id, title, status)
                     VALUES (999800, 'راجع قنوات الأسعار', 'open')");
        $this->exec("INSERT INTO ceo_risk_alerts (user_id, title, description, severity, is_resolved)
                     VALUES (999800, 'خطر إلغاء حجوزات', 'desc', 'critical', 0)");
        $this->exec("INSERT INTO ceo_growth_opportunities (user_id, title, description, estimated_impact, status)
                     VALUES (999800, 'توسّع في الخليج', 'desc', 'high', 'new')");
        $this->exec("INSERT INTO ci_insights (user_id, website_id, type, title, description, threat_level, status)
                     VALUES (999800, 999850, 'threat', 'منافس يخفض الأسعار', 'desc', 'high', 'new')");
        $this->exec("INSERT INTO ai_assistant_interactions (user_id, type, title, input_payload, output)
                     VALUES (999800, 'ad_copy', 'إعلان رأس السنة', '{}', 'نص الإعلان')");
    }

    public function testActionItemsAggregateAllSources(): void
    {
        $this->seedActionItems();

        $items = (new ActionCenterService())->getActionItems($this->dbInstance(), 999800, 999850);

        $sources = array_count_values(array_column($items, 'source'));
        $this->assertSame(1, $sources['website_optimizer'] ?? 0);
        $this->assertSame(2, $sources['outreach'] ?? 0); // draft + followup
        $this->assertSame(1, $sources['manual'] ?? 0);
        $this->assertSame(2, $sources['ceo_advisor'] ?? 0); // risk alert + growth opportunity
        $this->assertSame(1, $sources['competitor'] ?? 0);
        $this->assertSame(1, $sources['marketing'] ?? 0);
        $this->assertCount(8, $items);
        // أولوية critical أولًا (risk alert)
        $this->assertSame('critical', $items[0]['priority']);
        $this->assertSame('risk_alert', $items[0]['action_type']);
    }

    public function testActionItemsFilterByWebsite(): void
    {
        $this->exec("INSERT INTO outreach_prospects (user_id, website_id, domain, status)
                     VALUES (999800, 999850, 'mine.test', 'prospect')");
        $pid = (int) self::$pdo->lastInsertId();
        $this->exec("INSERT INTO outreach_emails (prospect_id, subject, body, status)
                     VALUES ({$pid}, 'Mine', 'b', 'draft')");

        $this->exec("INSERT INTO outreach_prospects (user_id, website_id, domain, status)
                     VALUES (999800, 999851, 'other.test', 'prospect')");
        $pid2 = (int) self::$pdo->lastInsertId();
        $this->exec("INSERT INTO outreach_emails (prospect_id, subject, body, status)
                     VALUES ({$pid2}, 'Other', 'b', 'draft')");

        $items = (new ActionCenterService())->getActionItems($this->dbInstance(), 999800, 999850);

        $this->assertCount(1, $items);
        $this->assertSame('mine.test', $items[0]['title'] === 'راجع رسالة التواصل مع mine.test' ? 'mine.test' : '');
    }

    public function testActionItemsEmptyWhenNoData(): void
    {
        $items = (new ActionCenterService())->getActionItems($this->dbInstance(), 999800, 999850);
        $this->assertSame([], $items);
    }

    // ================================================================
    // ActionCenterExecutionService
    // ================================================================

    public function testNextBestActionsOnlyExecutableSources(): void
    {
        $this->seedActionItems();

        $actions = (new ActionCenterExecutionService())->getNextBestActions($this->dbInstance(), 999800, 999850, 10);

        $types = array_column($actions, 'source_type');
        $this->assertNotContains('website_optimizer', $types);
        $this->assertNotContains('outreach', $types);
        $this->assertContains('competitor', $types);
        $this->assertContains('ceo_advisor', $types);
        $this->assertContains('marketing', $types);
        $this->assertCount(4, $actions); // competitor + ceo_advisor(2) + marketing

        $competitor = null;
        foreach ($actions as $a) {
            if (($a['source_type'] ?? '') === 'competitor') {
                $competitor = $a;
                break;
            }
        }
        $this->assertNotNull($competitor, 'competitor action must be present');
        $this->assertSame('high', $competitor['severity']);
        $this->assertSame('threat', $competitor['source_category']);
        $this->assertStringStartsWith('competitor:', $competitor['affected_area']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', (string) $competitor['period']);
    }

    public function testNextBestActionsRespectLimit(): void
    {
        $this->seedActionItems();

        $actions = (new ActionCenterExecutionService())->getNextBestActions($this->dbInstance(), 999800, 999850, 2);

        $this->assertCount(2, $actions);
    }

    // ================================================================
    // ActionCenterExecutor
    // ================================================================

    public function testPlanOneMapsSeverityAndDueDate(): void
    {
        $executor = new ActionCenterExecutor();
        $intent = $executor->planOne([
            'severity' => 'high',
            'confidence' => 'high',
            'affected_area' => 'competitor:42',
            'action' => 'تحقق من خفض الأسعار',
            'source_type' => 'competitor',
            'source_category' => 'threat',
            'id' => 42,
            'created_at' => '2026-08-01 00:00:00',
            'period' => '2026-08-01',
            'description' => 'منافس يخفض',
            'action_hint' => 'POST /x',
        ]);

        $this->assertSame('high', $intent['priority']);
        $this->assertTrue($intent['notify']);
        $this->assertStringContainsString('competitor:threat:competitor:42:2026-08-01', $intent['action_key']);
        $this->assertSame(date('Y-m-d', strtotime('+1 day')), substr($intent['due_date'], 0, 10));
        $this->assertSame('competitor', $intent['source_type']);
    }

    public function testPlanOneLowSeverityNoNotify(): void
    {
        $executor = new ActionCenterExecutor();
        $intent = $executor->planOne([
            'severity' => 'low',
            'confidence' => 'medium',
            'affected_area' => 'marketing:7',
            'action' => 'نفّذ المحتوى',
            'source_type' => 'marketing',
            'source_category' => 'marketing',
            'id' => 7,
            'created_at' => '2026-08-05 00:00:00',
        ]);

        $this->assertSame('low', $intent['priority']);
        $this->assertFalse($intent['notify']);
        $this->assertSame(date('Y-m-d', strtotime('+7 days')), substr($intent['due_date'], 0, 10));
    }

    public function testExecuteActionsCreatesTaskAndRecordsExecution(): void
    {
        $tasks = [];
        $notifications = [];
        $executor = new ActionCenterExecutor($this->dbInstance(), function ($userId, $payload) use (&$tasks) {
            $tasks[] = $payload;
        }, function ($userId, $intent) use (&$notifications) {
            $notifications[] = $intent;
        });

        $summary = $executor->executeActions(999800, [
            ['severity' => 'high', 'confidence' => 'high', 'affected_area' => 'competitor:1',
             'action' => 'راقب المنافس', 'source_type' => 'competitor', 'source_category' => 'threat',
             'id' => 1, 'created_at' => '2026-08-01 00:00:00', 'description' => 'd', 'action_hint' => null],
        ]);

        $this->assertSame(1, $summary['planned']);
        $this->assertSame(1, $summary['executed']);
        $this->assertSame(1, $summary['tasks_created']);
        $this->assertSame(1, $summary['notifications_sent']);
        $this->assertCount(1, $tasks);
        $this->assertStringContainsString('إجراء: ', $tasks[0]['title']);
        $this->assertSame('high', $tasks[0]['priority']);
        $this->assertCount(1, $notifications);

        $rows = self::$pdo->query("SELECT * FROM action_executions WHERE user_id = 999800")->fetchAll(PDO::FETCH_ASSOC);
        $this->assertCount(1, $rows);
        $this->assertSame('competitor', $rows[0]['source_type']);
        $this->assertStringContainsString('crm_task', $rows[0]['actions_taken']);
    }

    public function testExecuteActionsDeduplicatesWithinWindow(): void
    {
        $executor = new ActionCenterExecutor($this->dbInstance(), function () {
        }, function () {
        });

        $action = ['severity' => 'low', 'confidence' => 'medium', 'affected_area' => 'marketing:9',
                   'action' => 'نفّذ المحتوى', 'source_type' => 'marketing', 'source_category' => 'marketing',
                   'id' => 9, 'created_at' => '2026-08-01 00:00:00'];

        $first = $executor->executeActions(999800, [$action]);
        $second = $executor->executeActions(999800, [$action]);

        $this->assertSame(1, $first['executed']);
        $this->assertSame(1, $second['skipped']);
        $this->assertSame(0, $second['executed']);
    }

    public function testExecuteActionsDryRunWritesNothing(): void
    {
        $executor = new ActionCenterExecutor($this->dbInstance(), function () {
        }, function () {
        });

        $action = ['severity' => 'high', 'confidence' => 'high', 'affected_area' => 'competitor:3',
                   'action' => 'A', 'source_type' => 'competitor', 'source_category' => 'threat',
                   'id' => 3, 'created_at' => '2026-08-01 00:00:00'];

        $summary = $executor->executeActions(999800, [$action], ['dry_run' => true]);

        $this->assertSame(1, $summary['executed']);
        $this->assertSame(0, $summary['tasks_created']);
        $this->assertCount(0, self::$pdo->query("SELECT * FROM action_executions WHERE user_id = 999800")->fetchAll());
    }

    public function testCompetitorExecutionMarksInsightActioned(): void
    {
        $this->exec("INSERT INTO ci_insights (user_id, website_id, type, title, description, threat_level, status)
                     VALUES (999800, 999850, 'threat', 'خطر', 'd', 'high', 'new')");
        $insightId = (int) self::$pdo->lastInsertId();

        $executor = new ActionCenterExecutor($this->dbInstance(), function () {
        }, function () {
        });
        $executor->executeActions(999800, [
            ['severity' => 'high', 'confidence' => 'high', 'affected_area' => 'competitor:' . $insightId,
             'affected_area_id' => $insightId, 'action' => 'استجيب',
             'source_type' => 'competitor', 'source_category' => 'threat',
             'id' => $insightId, 'created_at' => '2026-08-01 00:00:00'],
        ]);

        $row = self::$pdo->query('SELECT status FROM ci_insights WHERE id = ' . $insightId)->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('actioned', $row['status']);
    }

    public function testHistoryReturnsExecutions(): void
    {
        $executor = new ActionCenterExecutor($this->dbInstance(), function () {
        }, function () {
        });
        $executor->executeActions(999800, [
            ['severity' => 'medium', 'confidence' => 'high', 'affected_area' => 'ceo_advisor:5',
             'action' => 'راجع المخاطرة', 'source_type' => 'ceo_advisor', 'source_category' => 'risk',
             'id' => 5, 'created_at' => '2026-08-01 00:00:00'],
        ]);

        $history = $executor->history(999800);

        $this->assertCount(1, $history);
        $this->assertSame('ceo_advisor', $history[0]['source_type']);
    }
}
