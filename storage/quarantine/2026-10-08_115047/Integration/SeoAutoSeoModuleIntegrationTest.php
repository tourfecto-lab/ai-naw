<?php

/**
 * Tourfecto - SEO/AutoSeo Module Integration Test (M6)
 * بيفحص فجوات Competitive Analysis SeoAutoSeo:
 *   G1 زحف كامل للموقع (Multi-page crawl + on-page + تكرارات)
 *   G3 الفهرسة لدى Google (Google Indexing API - fail-safe بلا creds)
 *   G4 بيانات خارجية للكلمات (Keyword Research - fail-safe + إثراء حقيقي)
 *   G6 تقرير بصري (Charts) + تقارير بريدية مجدولة
 *   G7 Rank Tracking (سجل زمني لترتيب الكلمات المفتاحية)
 *
 * محتاج الميجريشن 2026_08_29_000004_seo_multi_crawl_rank_tracking_reports.sql
 * و 2026_08_08_000045_keyword_intelligence.sql (عمود enriched_at) -
 * بيتخطى تلقائيًا (markTestSkipped) لو DB غير متاحة أو الميجريشن لسه
 * ما اتشغّلش.
 * @version 1.0.0  @date 2026-08-29
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/Core/Model.php';
require_once __DIR__ . '/../../app/Core/Database.php';
require_once __DIR__ . '/../../app/Core/Contracts/KeywordRankingSourceInterface.php';
require_once __DIR__ . '/../../app/Core/Contracts/KeywordResearchSourceInterface.php';
require_once __DIR__ . '/../../app/Services/CompetitorIntelligence/NullKeywordRankingSource.php';
require_once __DIR__ . '/../../app/Services/Seo/SeoCrawlerService.php';
require_once __DIR__ . '/../../app/Services/Seo/GoogleIndexingService.php';
require_once __DIR__ . '/../../app/Services/Seo/KeywordResearchService.php';
require_once __DIR__ . '/../../app/Services/Seo/NullKeywordResearchSource.php';
require_once __DIR__ . '/../../app/Services/Seo/HttpKeywordResearchSource.php';
require_once __DIR__ . '/../../app/Services/Seo/RankTrackingService.php';
require_once __DIR__ . '/../../app/Services/Seo/SeoChartService.php';
require_once __DIR__ . '/../../app/Services/Seo/SeoScheduledReportService.php';

final class SeoAutoSeoModuleIntegrationTest extends TestCase
{
    private static ?PDO $pdo = null;
    private static bool $dbChecked = false;
    private static int $userId = 0;
    private static int $websiteId = 0;

    private function db(): ?PDO
    {
        if (self::$dbChecked) {
            return self::$pdo;
        }
        self::$dbChecked = true;

        try {
            $app = dirname(__DIR__, 2) . '/app';
            if (!defined('APP_ENV')) {
                foreach ([$app . '/Config/app.php', $app . '/Config/database.php'] as $cfg) {
                    if (file_exists($cfg)) {
                        require_once $cfg;
                    }
                }
            }
            if (!class_exists('Database')) {
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

            $tables = ['seo_crawl_pages', 'seo_rank_tracking_history', 'seo_report_schedules', 'tracked_keywords'];
            foreach ($tables as $t) {
                if (empty($conn->query("SHOW TABLES LIKE '{$t}'")->fetchAll())) {
                    self::$pdo = null;
                    return null;
                }
            }
            $cols = $conn->query("SHOW COLUMNS FROM websites")->fetchAll();
            $names = array_column($cols, 'Field');
            if (!in_array('last_rank_tracked_at', $names, true) || !in_array('google_indexing_enabled', $names, true)) {
                self::$pdo = null;
                return null;
            }

            self::$pdo = $conn;
            return self::$pdo;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function tableExists(string $table): bool
    {
        $pdo = $this->db();
        if ($pdo === null) {
            return false;
        }
        return !empty($pdo->query("SHOW TABLES LIKE '" . str_replace('`', '', $table) . "'")->fetchAll());
    }

    private function columnExists(string $table, string $column): bool
    {
        $pdo = $this->db();
        if ($pdo === null) {
            return false;
        }
        return !empty($pdo->query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'")->fetchAll());
    }

    /** مثيل Database (الذي تستهلكه الـ Services - ليس PDO). */
    private function database(): Database
    {
        return Database::getInstance();
    }

    protected function setUp(): void
    {
        $pdo = $this->db();
        if ($pdo === null) {
            $this->markTestSkipped('DB غير متاحة أو ميجريشن M6 (crawl_pages/rank_tracking_history/report_schedules) لسه ما اتشغّلش');
        }
        if (self::$userId === 0) {
            self::$userId = createTestUser();
            self::$websiteId = createTestWebsite(self::$userId);
            // دومين ثابت للزحف + تفعيل الاتصال لفحص dueWebsites
            $pdo->exec(
                "UPDATE websites SET main_url = 'https://crawl.example', is_connected = 1 WHERE id = " . (int) self::$websiteId
            );
        } else {
            // Defensive: cleanDatabase() بين الملفات بيمسح users/websites ويرجّع
            // الفيكتشرز بس - نعيد إنشاء المستخدم والموقع لو اترشّحوا
            $userStmt = $pdo->query("SELECT id FROM users WHERE id = " . (int) self::$userId);
            $userExists = $userStmt ? $userStmt->fetchAll() : [];
            if (empty($userExists)) {
                self::$userId = createTestUser();
                self::$websiteId = createTestWebsite(self::$userId);
                $pdo->exec(
                    "UPDATE websites SET main_url = 'https://crawl.example', is_connected = 1 WHERE id = " . (int) self::$websiteId
                );
            } else {
                $siteStmt = $pdo->query("SELECT id FROM websites WHERE id = " . (int) self::$websiteId);
                $siteExists = $siteStmt ? $siteStmt->fetchAll() : [];
                if (empty($siteExists)) {
                    self::$websiteId = createTestWebsite(self::$userId);
                    $pdo->exec(
                        "UPDATE websites SET main_url = 'https://crawl.example', is_connected = 1 WHERE id = " . (int) self::$websiteId
                    );
                }
            }
        }
    }

    protected function tearDown(): void
    {
        $pdo = $this->db();
        if ($pdo === null) {
            return;
        }
        $uid = (int) self::$userId;
        $wid = (int) self::$websiteId;
        foreach ([
            'seo_crawl_pages',
            'seo_rank_tracking_history',
            'seo_report_schedules',
            'tracked_keywords',
        ] as $t) {
            $pdo->exec("DELETE FROM {$t} WHERE user_id = {$uid}");
        }
        if ($this->tableExists('auto_seo_applied_fixes')) {
            $pdo->exec("DELETE FROM auto_seo_applied_fixes WHERE website_id = {$wid}");
        }
        if ($this->tableExists('seo_gsc_page_metrics')) {
            $pdo->exec("DELETE FROM seo_gsc_page_metrics WHERE website_id = {$wid}");
        }
        $pdo->exec("DELETE FROM seo_reports WHERE user_id = {$uid}");
        $pdo->exec("DELETE FROM wo_audit_findings WHERE audit_id IN (SELECT id FROM wo_audits WHERE user_id = {$uid})");
        $pdo->exec("DELETE FROM wo_audits WHERE user_id = {$uid}");
        $pdo->exec(
            "UPDATE websites SET last_rank_tracked_at = NULL, google_indexing_enabled = 0, last_google_indexed_at = NULL
             WHERE id = {$wid}"
        );
    }

    private function addTrackedKeyword(string $keyword): int
    {
        $pdo = $this->db();
        $pdo->exec(
            "INSERT INTO tracked_keywords (user_id, website_id, keyword, current_position, search_volume, difficulty)
             VALUES (" . (int) self::$userId . ", " . (int) self::$websiteId . ", " . $pdo->quote($keyword) . ", NULL, NULL, NULL)"
        );
        return (int) $pdo->lastInsertId();
    }

    // ============================ G1: Multi-page Crawl ============================

    public function testCrawlerRunsBfsAndStoresPages(): void
    {
        $pdo = $this->db();
        $fetcher = static function (string $url): array {
            if ($url === 'https://crawl.example/') {
                return [
                    'body' => '<html><head><title>My Travel Site</title>'
                        . '<meta name="description" content="Desert safari tours in Dubai"></head>'
                        . '<body><h1>Home</h1><p>Welcome to our travel agency for desert safari trips.</p>'
                        . '<a href="/about">About</a><a href="/contact">Contact</a>'
                        . '<a href="https://external.example/">External</a></body></html>',
                    'code' => 200,
                    'error' => null,
                    'time' => 0.05,
                ];
            }
            if ($url === 'https://crawl.example/about') {
                return [
                    'body' => '<html><head><title>My Travel Site</title></head>'
                        . '<body><h1>About us</h1><h1>Our story</h1><p>We have been organizing trips since 2010.</p></body></html>',
                    'code' => 200,
                    'error' => null,
                    'time' => 0.03,
                ];
            }
            return ['body' => null, 'code' => 0, 'error' => 'connection refused', 'time' => 0.0];
        };

        $service = new SeoCrawlerService($fetcher);
        $result = $service->crawl($this->database(), self::$websiteId, self::$userId, ['max_urls' => 5, 'max_depth' => 1]);

        $this->assertTrue($result['success']);
        $this->assertNull($result['error']);
        $this->assertNotNull($result['crawl_id']);
        $this->assertSame(3, $result['summary']['pages_checked']);

        $about = null;
        foreach ($result['pages'] as $p) {
            if (strpos($p['url'], '/about') !== false) {
                $about = $p;
            }
        }
        $this->assertNotNull($about);
        $this->assertSame(0, $about['has_meta_description']);
        $this->assertSame(2, $about['h1_count']);
        $this->assertSame(200, $about['status_code']);

        // الـ /contact فشل بأمان
        $contact = array_values(array_filter($result['pages'], static fn ($p) => strpos($p['url'], '/contact') !== false));
        $this->assertNotEmpty($contact);
        $this->assertNotNull($contact[0]['fetch_error']);

        // لا زحف خارج الدومين
        $this->assertSame([], array_values(array_filter($result['pages'], static fn ($p) => strpos($p['url'], 'external.example') !== false)));

        // مخزّن في DB
        $rows = $pdo->query("SELECT * FROM seo_crawl_pages WHERE website_id = " . (int) self::$websiteId)->fetchAll();
        $this->assertCount(3, $rows);

        // lastCrawl يعيد نفس الدورة
        $last = $service->lastCrawl($this->database(), self::$websiteId, self::$userId);
        $this->assertNotNull($last);
        $this->assertSame($result['crawl_id'], $last['crawl_id']);
        $this->assertSame(3, $last['summary']['pages_checked']);
    }

    public function testCrawlerAggregatesDuplicateTitlesAndErrors(): void
    {
        $fetcher = static function (string $url): array {
            if ($url === 'https://crawl.example/') {
                return ['body' => '<html><head><title>Dup Title</title><meta name="description" content="x"></head><body><h1>A</h1><p>Some words here.</p><a href="/page2">p2</a></body></html>', 'code' => 200, 'error' => null, 'time' => 0.01];
            }
            if ($url === 'https://crawl.example/page2') {
                return ['body' => '<html><head><title>Dup Title</title></head><body><h1>B</h1><p>More unique words on this page.</p></body></html>', 'code' => 200, 'error' => null, 'time' => 0.01];
            }
            return ['body' => null, 'code' => 404, 'error' => 'Not Found', 'time' => 0.0];
        };

        $service = new SeoCrawlerService($fetcher);
        $result = $service->crawl($this->database(), self::$websiteId, self::$userId, ['max_urls' => 5, 'max_depth' => 1]);

        $this->assertTrue($result['success']);
        $summary = $result['summary'];
        $this->assertSame(2, $summary['pages_ok']);
        $this->assertSame(1, $summary['duplicate_titles']);
        $this->assertSame(1, $summary['pages_missing_meta_description']);
        $this->assertGreaterThan(0, $summary['avg_word_count']);
    }

    public function testCrawlerWebsiteNotFoundFailsSafe(): void
    {
        $service = new SeoCrawlerService();
        $result = $service->crawl($this->database(), 999999999, self::$userId, ['max_urls' => 3]);
        $this->assertFalse($result['success']);
        $this->assertNotNull($result['error']);
        $this->assertSame([], $result['pages']);
    }

    // ============================ G3: Google Indexing ============================

    public function testGoogleIndexingFailsSafeWithoutCredentials(): void
    {
        $service = new GoogleIndexingService();
        if ($service->isConfigured()) {
            $this->markTestSkipped('GOOGLE_SERVICE_ACCOUNT_JSON متظبط في البيئة - يتم اختبار التكامل الحقيقي يدويًا');
        }

        $this->assertFalse($service->isConfigured());
        $this->assertSame('google_service_account_not_configured', $service->configReason());

        $notify = $service->notify('https://crawl.example/');
        $this->assertFalse($notify['available']);
        $this->assertSame('google_service_account_not_configured', $notify['reason']);
        $this->assertFalse($notify['success']);

        $token = $service->fetchAccessToken();
        $this->assertNull($token);
    }

    public function testGoogleIndexingSubmitSiteRespectsToggle(): void
    {
        $service = new GoogleIndexingService();
        if ($service->isConfigured()) {
            $this->markTestSkipped('GOOGLE_SERVICE_ACCOUNT_JSON متظبط في البيئة - يتم اختبار التكامل الحقيقي يدويًا');
        }
        $pdo = $this->db();
        $uid = (int) self::$userId;
        $wid = (int) self::$websiteId;

        // غير مفعّل على الموقع → رفض واضح
        $pdo->exec("UPDATE websites SET google_indexing_enabled = 0 WHERE id = {$wid}");
        $disabled = $service->submitSite($this->database(), $wid, $uid);
        $this->assertFalse($disabled['available']);
        $this->assertSame('google_indexing_disabled_for_website', $disabled['reason']);
        $this->assertSame(0, $disabled['submitted']);

        // مفعّل لكن بلا creds → fail-safe بلا اختلاق
        $pdo->exec("UPDATE websites SET google_indexing_enabled = 1 WHERE id = {$wid}");
        $unconfigured = $service->submitSite($this->database(), $wid, $uid);
        $this->assertFalse($unconfigured['available']);
        $this->assertSame('google_service_account_not_configured', $unconfigured['reason']);
    }

    // ============================ G4: Keyword Research ============================

    public function testKeywordResearchFailsSafeWithoutSource(): void
    {
        $service = new KeywordResearchService(new NullKeywordResearchSource());
        $status = $service->status();
        $this->assertFalse($status['available']);
        $this->assertSame('no_keyword_research_source_configured', $status['reason']);

        $enrich = $service->enrichTrackedKeywords($this->database(), self::$websiteId, self::$userId);
        $this->assertFalse($enrich['available']);
        $this->assertSame(0, $enrich['enriched']);
    }

    public function testKeywordResearchDefaultResolutionIsFailSafe(): void
    {
        $service = new KeywordResearchService();
        $status = $service->status();
        $this->assertIsBool($status['available']);
        $this->assertContains($status['source'], ['null', 'http_keyword_research']);
    }

    public function testKeywordResearchEnrichWithConfiguredSource(): void
    {
        if (!$this->columnExists('tracked_keywords', 'enriched_at')) {
            $this->markTestSkipped('عمود enriched_at مش موجود في tracked_keywords (ميجريشن 000045 keyword_intelligence لسه ما اتشغّلش)');
        }
        $this->addTrackedKeyword('desert safari dubai');
        $service = new KeywordResearchService(new SeoFakeKeywordResearchSource());

        $result = $service->enrichTrackedKeywords($this->database(), self::$websiteId, self::$userId);
        $this->assertTrue($result['available']);
        $this->assertSame(1, $result['enriched']);
        $this->assertSame(1, $result['total']);

        $rows = $this->db()->query(
            "SELECT search_volume, difficulty, enriched_at FROM tracked_keywords WHERE user_id = " . (int) self::$userId
        )->fetchAll();
        $this->assertSame(1200, (int) $rows[0]['search_volume']);
        $this->assertSame(35, (int) $rows[0]['difficulty']);
        $this->assertNotNull($rows[0]['enriched_at']);
    }

    // ============================ G7: Rank Tracking ============================

    public function testRankTrackingFailsSafeWithoutSource(): void
    {
        $service = new RankTrackingService();
        $result = $service->checkWebsite($this->database(), self::$websiteId, self::$userId);
        $this->assertFalse($result['available']);
        $this->assertSame('no_keyword_ranking_source_configured', $result['reason']);
        $this->assertSame(0, $result['recorded']);
    }

    public function testRankTrackingRecordsHistoryAndUpdatesKeyword(): void
    {
        $this->addTrackedKeyword('dune bashing dubai');
        $service = new RankTrackingService(new SeoFakeKeywordRankingSource());

        $result = $service->checkWebsite($this->database(), self::$websiteId, self::$userId);
        $this->assertTrue($result['available']);
        $this->assertSame(1, $result['checked']);
        $this->assertSame(1, $result['recorded']);
        $this->assertSame('integration:fake_seo_serp', $result['source']);

        $kw = $this->db()->query(
            "SELECT current_position FROM tracked_keywords WHERE user_id = " . (int) self::$userId
        )->fetchAll();
        $this->assertSame(1, (int) $kw[0]['current_position']);

        $hist = $this->db()->query(
            "SELECT COUNT(*) AS c FROM seo_rank_tracking_history WHERE user_id = " . (int) self::$userId
        )->fetchAll();
        $this->assertSame(1, (int) $hist[0]['c']);

        $site = $this->db()->query(
            "SELECT last_rank_tracked_at FROM websites WHERE id = " . (int) self::$websiteId
        )->fetchAll();
        $this->assertNotNull($site[0]['last_rank_tracked_at']);
    }

    public function testRankTrackingOverviewAndHistory(): void
    {
        $this->addTrackedKeyword('quad bike rental');
        $service = new RankTrackingService(new SeoFakeKeywordRankingSource());
        $service->checkWebsite($this->database(), self::$websiteId, self::$userId);

        // قياس أقدم أدنى ترتيب لإثبات حساب best/trend
        $this->db()->exec(
            "INSERT INTO seo_rank_tracking_history (website_id, user_id, keyword, position, url, source, checked_at)
             VALUES (" . (int) self::$websiteId . ", " . (int) self::$userId . ", 'quad bike rental', 5, NULL, 'manual', '2026-08-01 10:00:00')"
        );

        $overview = $service->trackingOverview($this->database(), self::$websiteId, self::$userId);
        $this->assertTrue($overview['status']['available']);
        $this->assertCount(1, $overview['keywords']);
        $kw = $overview['keywords'][0];
        $this->assertSame('quad bike rental', $kw['keyword']);
        $this->assertSame(1, $kw['best_position']);
        $this->assertSame(1, $kw['current_position']);
        $this->assertSame(2, $kw['readings']);

        $history = $service->history($this->database(), self::$websiteId, self::$userId, 'quad bike rental');
        $this->assertSame('quad bike rental', $history['keyword']);
        $this->assertCount(2, $history['points']);
        $this->assertSame(5, $history['points'][0]['position']);
        $this->assertSame(1, $history['points'][1]['position']);
    }

    public function testRankTrackingDueWebsites(): void
    {
        $pdo = $this->db();
        $uid = (int) self::$userId;
        $wid = (int) self::$websiteId;
        $pdo->exec("UPDATE websites SET last_rank_tracked_at = NULL WHERE id = {$wid}");
        $this->addTrackedKeyword('hot air balloon dubai');

        $service = new RankTrackingService(new SeoFakeKeywordRankingSource());
        $due = $service->dueWebsites($this->database(), 100);
        $ids = array_column($due, 'id');
        $this->assertContains($wid, $ids);

        // بعد الفحص الناجح → مش مستحق تاني (interval = يوم)
        $service->checkWebsite($this->database(), $wid, $uid);
        $dueAfter = $service->dueWebsites($this->database(), 100);
        $this->assertNotContains($wid, array_column($dueAfter, 'id'));
    }

    // ============================ G6: Charts ============================

    private function insertCompletedAudit(int $score): int
    {
        $pdo = $this->db();
        $pdo->exec(
            "INSERT INTO wo_audits (website_id, user_id, status, overall_score, started_at, completed_at)
             VALUES (" . (int) self::$websiteId . ", " . (int) self::$userId . ", 'completed', {$score}, NOW(), NOW())"
        );
        return (int) $pdo->lastInsertId();
    }

    public function testChartCategoryScoresFromFindings(): void
    {
        $auditId = $this->insertCompletedAudit(82);
        $pdo = $this->db();
        $pdo->exec(
            "INSERT INTO wo_audit_findings (audit_id, category, check_key, title, status, severity, message) VALUES "
            . "({$auditId}, 'seo', 'title', 'Short title', 'pass', 'low', 'x'), "
            . "({$auditId}, 'seo', 'meta', 'Missing meta', 'pass', 'low', 'x'), "
            . "({$auditId}, 'seo', 'h1', 'Duplicate H1', 'fail', 'high', 'x'), "
            . "({$auditId}, 'speed', 'lcp', 'Slow LCP', 'warn', 'medium', 'x')"
        );

        $chart = (new SeoChartService($this->database()))->categoryScores(self::$websiteId, self::$userId);
        $this->assertContains('seo', $chart['labels']);
        $this->assertContains('speed', $chart['labels']);
        $seoIdx = array_search('seo', $chart['labels'], true);
        $this->assertSame(66.7, $chart['datasets'][0]['data'][$seoIdx]); // (100+100+0)/3
    }

    public function testChartScoreTrendFromSeoReports(): void
    {
        $pdo = $this->db();
        $pdo->exec(
            "INSERT INTO seo_reports (website_id, user_id, overall_score, findings_total, fixes_applied, source, created_at)
             VALUES (" . (int) self::$websiteId . ", " . (int) self::$userId . ", 70, 5, 2, 'wo_audit', '2026-08-01 10:00:00')"
        );

        $chart = (new SeoChartService($this->database()))->scoreTrend(self::$websiteId, self::$userId);
        $this->assertCount(1, $chart['labels']);
        $this->assertSame(70.0, $chart['datasets'][0]['data'][0]);
    }

    public function testChartFixesAppliedTrend(): void
    {
        if (!$this->tableExists('auto_seo_applied_fixes')) {
            $this->markTestSkipped('جدول auto_seo_applied_fixes غير موجود');
        }
        $pdo = $this->db();
        $pdo->exec(
            "INSERT INTO auto_seo_applied_fixes (website_id, user_id, category, check_key, field_name, injected_code, is_active, created_at)
             VALUES (" . (int) self::$websiteId . ", " . (int) self::$userId . ", 'seo', 'title', 'title', '<title>x</title>', 1, NOW())"
        );

        $chart = (new SeoChartService($this->database()))->fixesAppliedTrend(self::$websiteId);
        $this->assertCount(1, $chart['labels']);
        $this->assertSame(1, $chart['datasets'][0]['data'][0]);
    }

    public function testChartGscTopPages(): void
    {
        $pdo = $this->db();
        $pdo->exec(
            "INSERT INTO seo_gsc_page_metrics (website_id, page_path, clicks, impressions, ctr, position, fetched_at)
             VALUES (" . (int) self::$websiteId . ", '/safari-tours', 120, 1000, 0.12, 5.5, NOW())"
        );

        $chart = (new SeoChartService($this->database()))->gscTopPages(self::$websiteId);
        $this->assertContains('/safari-tours', $chart['labels']);
        $this->assertSame(120, $chart['datasets'][0]['data'][0]);
    }

    // ============================ G6: Scheduled Reports ============================

    public function testScheduleValidation(): void
    {
        $service = new SeoScheduledReportService($this->database());
        $wid = self::$websiteId;
        $uid = self::$userId;

        $badFreq = $service->saveSchedule($wid, $uid, ['frequency' => 'hourly', 'recipient_email' => 'a@b.com']);
        $this->assertFalse($badFreq['success']);
        $this->assertNotNull($badFreq['error']);

        $badHour = $service->saveSchedule($wid, $uid, ['frequency' => 'daily', 'hour' => 24, 'recipient_email' => 'a@b.com']);
        $this->assertFalse($badHour['success']);

        $badEmail = $service->saveSchedule($wid, $uid, ['frequency' => 'daily', 'recipient_email' => 'not-an-email']);
        $this->assertFalse($badEmail['success']);
    }

    public function testScheduleSaveListUpdateDelete(): void
    {
        $service = new SeoScheduledReportService($this->database());
        $wid = self::$websiteId;
        $uid = self::$userId;

        $created = $service->saveSchedule($wid, $uid, ['frequency' => 'weekly', 'weekday' => 0, 'hour' => 9, 'recipient_email' => 'owner@example.com']);
        $this->assertTrue($created['success']);
        $id = (int) $created['schedule']['id'];
        $this->assertGreaterThan(0, $id);

        $list = $service->listSchedules($wid, $uid);
        $this->assertNotEmpty($list);
        $this->assertSame('owner@example.com', $list[0]['recipient_email']);

        $updated = $service->saveSchedule($wid, $uid, ['frequency' => 'daily', 'hour' => 7, 'recipient_email' => 'new@example.com'], $id);
        $this->assertTrue($updated['success']);
        $this->assertSame('daily', $updated['schedule']['frequency']);
        $this->assertSame('new@example.com', $updated['schedule']['recipient_email']);

        // عزل تينانت: جدول مستخدم تاني مش محذوف بمحاولة الحذف الخاطئة
        $other = $service->saveSchedule(999999999, $uid, ['frequency' => 'daily', 'recipient_email' => 'other@example.com']);
        $this->assertTrue($other['success']);

        $this->assertTrue($service->deleteSchedule($wid, $uid, $id));
        $this->assertFalse($service->deleteSchedule($wid, $uid, $id));
        $this->assertFalse($service->deleteSchedule($wid, $uid, (int) $other['schedule']['id']));
    }

    public function testScheduleDueSelection(): void
    {
        $service = new SeoScheduledReportService($this->database());
        $wid = self::$websiteId;
        $uid = self::$userId;

        $created = $service->saveSchedule($wid, $uid, ['frequency' => 'daily', 'hour' => 0, 'recipient_email' => 'due@example.com']);
        $id = (int) $created['schedule']['id'];

        $due = $service->dueSchedules(100);
        $ids = array_column($due, 'id');
        $this->assertContains($id, $ids);

        // بعد الإرسال (last_sent_at = اليوم) → مش مستحق تاني
        $this->db()->exec("UPDATE seo_report_schedules SET last_sent_at = NOW() WHERE id = {$id}");
        $dueAfter = $service->dueSchedules(100);
        $this->assertNotContains($id, array_column($dueAfter, 'id'));
    }

    public function testReportHtmlBuildsAndEscapes(): void
    {
        $service = new SeoScheduledReportService($this->database());
        $auditId = $this->insertCompletedAudit(75);
        $this->db()->exec(
            "INSERT INTO wo_audit_findings (audit_id, category, check_key, title, status, severity, message) VALUES "
            . "({$auditId}, 'seo', 'xss', '<script>alert(1)</script>', 'fail', 'high', 'x')"
        );

        $html = $service->buildReportHtml(self::$websiteId, self::$userId, 'https://crawl.example/');
        $this->assertStringContainsString('75 / 100', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('تقرير SEO دوري', $html);
    }
}

/** مصدر Keyword Research وهمي حقيقي السلوك لاختبار مسار الإثراء (بدون شبكة). */
class SeoFakeKeywordResearchSource implements KeywordResearchSourceInterface
{
    public function isConfigured(): bool
    {
        return true;
    }

    public function sourceName(): string
    {
        return 'fake_keyword_research';
    }

    public function getKeywordData(array $keywords): array
    {
        $data = [];
        foreach ($keywords as $kw) {
            $data[$kw] = ['search_volume' => 1200, 'difficulty' => 35];
        }
        return ['available' => true, 'reason' => null, 'data' => $data];
    }
}

/** مصدر ترتيبات SERP وهمي حقيقي السلوك لاختبار Rank Tracking (بدون شبكة). */
class SeoFakeKeywordRankingSource implements KeywordRankingSourceInterface
{
    public function isConfigured(): bool
    {
        return true;
    }

    public function sourceName(): string
    {
        return 'fake_seo_serp';
    }

    public function check(string $domain, array $keywords): array
    {
        $rankings = [];
        foreach ($keywords as $i => $kw) {
            $rankings[] = ['keyword' => $kw, 'position' => $i + 1, 'url' => 'https://' . $domain . '/' . strtolower(str_replace(' ', '-', $kw))];
        }
        return ['available' => true, 'reason' => null, 'rankings' => $rankings];
    }
}
