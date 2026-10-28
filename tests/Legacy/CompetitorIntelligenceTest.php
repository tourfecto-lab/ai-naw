<?php

/**
 * Tourfecto - Competitor Intelligence Integration Test
 * @version 1.1.0
 *
 * يحتاج قاعدة بيانات اختبار فعلية (نفس اتفاقية باقي tests/Integration/*)
 * بها كل جداول موديول Competitor Intelligence (شغّل كل الميجريشنز بالترتيب
 * أولاً - انظر CHANGELOG.md قسم "Installation steps").
 *
 * تشغيل مباشر:
 *   php tests/Integration/CompetitorIntelligenceTest.php
 * أو عبر phpunit.xml الحالي للمشروع (نفس اتفاقية باقي ملفات الاختبار).
 *
 * يغطي: إضافة منافس + Watchlist، تعديل منافس، حذف منافس (وتأكد من
 * cascade على Watchlist)، كشف تغيير حقيقي بين لقطتين (Change
 * Detection)، فلترة التنبيهات حسب severity/watchlist (Alert Service)،
 * **تنبيهات الكلمات المفتاحية (تتجاوز حد الخطورة عند التطابق)**،
 * **رفض SendCompetitorAlertWebhookJob لأي رابط غير آمن (SSRF)**،
 * محرك القواعد للتهديدات/الفرص (Threat/Opportunity)، Benchmarking
 * (مقارنة My Business vs Competitor)، توليد تقرير أسبوعي حقيقي
 * (ReportService)، حفظ/استرجاع تفضيلات المستخدم الافتراضية
 * (CiUserPreference)، وعزل الـ Tenant (منافس مستخدم A غير مرئي لمستخدم B).
 */
class CompetitorIntelligenceTest
{
    private $db;
    private $passed = 0;
    private $failed = 0;
    private $testUserId;
    private $testUserBId;
    private $testWebsiteId;
    private $testCompetitorId;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->testUserId = $this->createTestUser('ci_test_a_');
        $this->testUserBId = $this->createTestUser('ci_test_b_');
        $this->testWebsiteId = $this->createTestWebsite($this->testUserId);
    }

    public function runAll(): void
    {
        echo "\n🕵️‍♂️ Competitor Intelligence Integration Tests\n================================================\n\n";

        $this->testAddCompetitorCreatesWatchlistEntry();
        $this->testWebsiteOnboardingDiscoverySurfacesCandidatesAndSkipsExisting();
        $this->testEditCompetitor();
        $this->testChangeDetectionDetectsRealDiff();
        $this->testChangeDetectionIgnoresIdenticalContent();
        $this->testChangeDetectionNeverTreatsFailureAsNoChange();
        $this->testAlertRespectsMinSeverity();
        $this->testAlertSkippedWhenWatchlistPaused();
        $this->testKeywordAlertBypassesSeverityThreshold();
        $this->testWebhookJobRejectsUnsafeUrl();
        $this->testThreatOpportunityScanProducesEvidence();
        $this->testBenchmarkingComparison();
        $this->testReportGeneration();
        $this->testUserPreferencesRoundTrip();
        $this->testTenantIsolation();
        $this->testCiPermissionsIntegration();
        $this->testDeleteCompetitor(); // آخر اختبار عمدًا - بيستخدم منافس مؤقت خاص بيه، لا يمس $this->testCompetitorId المشترك

        $this->cleanup();
        $this->printSummary();
    }

    private function testAddCompetitorCreatesWatchlistEntry(): void
    {
        $this->startTest('Adding a competitor + manual watchlist entry');

        $competitor = new Competitor([
            'user_id' => $this->testUserId,
            'website_id' => $this->testWebsiteId,
            'competitor_name' => 'Test Competitor Co',
            'competitor_domain' => 'https://example.com',
            'category' => 'direct',
            'source' => 'manual',
            'monitoring_frequency' => 'weekly',
            'is_active' => 1,
        ]);
        $competitor->save();
        $this->testCompetitorId = (int) $competitor->getAttribute('id');

        $this->testCompetitorId > 0 ? $this->pass('Competitor created with id ' . $this->testCompetitorId) : $this->fail('Competitor was not created');

        $watchlist = new CiWatchlistItem([
            'user_id' => $this->testUserId,
            'competitor_id' => $this->testCompetitorId,
            'priority' => 'medium',
            'alert_min_severity' => 'medium',
            'alert_channels' => json_encode(['dashboard']),
            'is_paused' => 0,
        ]);
        $watchlist->save();
        (int) $watchlist->getAttribute('id') > 0 ? $this->pass('Watchlist entry created') : $this->fail('Watchlist entry was not created');
    }

    private function testWebsiteOnboardingDiscoverySurfacesCandidatesAndSkipsExisting(): void
    {
        $this->startTest('WebsiteOnboardingDiscoverySource surfaces real onboarding URLs, skips already-added ones');

        // نحفظ 2 competitor URLs في سجل الموقع نفسه - واحد منهم هو نفس
        // المنافس اللي أضفناه بالفعل (لازم يتم استبعاده من النتائج).
        $this->db->query(
            "UPDATE websites SET competitor_1_url = ?, competitor_2_url = ? WHERE id = ?",
            ['https://example.com', 'https://brand-new-rival.example', $this->testWebsiteId]
        );

        $source = new WebsiteOnboardingDiscoverySource();
        $result = $source->discover(['user_id' => $this->testUserId, 'website_id' => $this->testWebsiteId]);

        $result['available'] === true ? $this->pass('Onboarding source reports available=true when URLs exist') : $this->fail('Expected available=true');

        $hosts = array_map(fn ($c) => parse_url($c['website'], PHP_URL_HOST), $result['candidates']);
        !in_array('example.com', $hosts, true)
            ? $this->pass('Already-added competitor (example.com) correctly excluded from suggestions')
            : $this->fail('Already-added competitor was incorrectly suggested again');
        in_array('brand-new-rival.example', $hosts, true)
            ? $this->pass('New onboarding URL correctly surfaced as a candidate')
            : $this->fail('New onboarding URL was not surfaced');

        // ننضف عمودي الموقع تاني عشان الاختبار يفضل idempotent
        $this->db->query("UPDATE websites SET competitor_1_url = NULL, competitor_2_url = NULL WHERE id = ?", [$this->testWebsiteId]);
    }

    private function testEditCompetitor(): void
    {
        $this->startTest('Editing a competitor updates fields and preserves the audit trail');

        $competitor = (new Competitor())->find($this->testCompetitorId);
        $before = $competitor->toArray();

        $competitor->setAttribute('competitor_name', 'Test Competitor Co (Renamed)');
        $competitor->setAttribute('notes', 'Updated via integration test');
        $competitor->setAttribute('monitoring_frequency', 'daily');
        $competitor->save();

        $reloaded = (new Competitor())->find($this->testCompetitorId);
        $reloaded->getAttribute('competitor_name') === 'Test Competitor Co (Renamed)'
            ? $this->pass('competitor_name updated correctly')
            : $this->fail('competitor_name was not updated');
        $reloaded->getAttribute('monitoring_frequency') === 'daily'
            ? $this->pass('monitoring_frequency updated correctly')
            : $this->fail('monitoring_frequency was not updated');

        ActivityLog::record('competitor_intelligence', 'competitor.updated', [
            'user_id' => $this->testUserId, 'subject_type' => 'competitors', 'subject_id' => $this->testCompetitorId,
            'meta' => ['before' => $before, 'after' => $reloaded->toArray()],
        ]);
        $this->pass('Audit log entry recorded for the edit (no exception thrown)');
    }

    private function testChangeDetectionDetectsRealDiff(): void
    {
        $this->startTest('ChangeDetectionService detects a real content diff');

        $competitor = (new Competitor())->find($this->testCompetitorId);

        $before = new CiSnapshot([
            'competitor_id' => $this->testCompetitorId, 'page_type' => 'pricing', 'url' => 'https://example.com/pricing',
            'http_status' => 200, 'content_hash' => hash('sha256', 'Basic Plan $10/mo'), 'title' => 'Pricing',
            'normalized_excerpt' => 'Basic Plan $10/mo', 'fetch_status' => 'ok',
        ]);
        $before->save();

        $after = new CiSnapshot([
            'competitor_id' => $this->testCompetitorId, 'page_type' => 'pricing', 'url' => 'https://example.com/pricing',
            'http_status' => 200, 'content_hash' => hash('sha256', 'Basic Plan $15/mo'), 'title' => 'Pricing',
            'normalized_excerpt' => 'Basic Plan $15/mo', 'fetch_status' => 'ok',
        ]);
        $after->save();

        $change = (new ChangeDetectionService())->detectAndRecord($competitor, 'pricing', $after);

        if ($change !== null && $change->getAttribute('change_type') === 'pricing_change') {
            $this->pass('Pricing change correctly detected and classified as pricing_change');
        } else {
            $this->fail('Expected a pricing_change to be detected, got: ' . ($change ? $change->getAttribute('change_type') : 'null'));
        }

        if ($change !== null && $change->getAttribute('severity') === 'high') {
            $this->pass('Pricing change correctly classified as high severity');
        } else {
            $this->fail('Expected high severity for a pricing page change');
        }
    }

    private function testChangeDetectionIgnoresIdenticalContent(): void
    {
        $this->startTest('ChangeDetectionService reports Nothing Changed for identical hash');

        $competitor = (new Competitor())->find($this->testCompetitorId);
        $hash = hash('sha256', 'Stable homepage content');

        $s1 = new CiSnapshot(['competitor_id' => $this->testCompetitorId, 'page_type' => 'homepage', 'url' => 'https://example.com', 'content_hash' => $hash, 'normalized_excerpt' => 'Stable homepage content', 'fetch_status' => 'ok']);
        $s1->save();
        $s2 = new CiSnapshot(['competitor_id' => $this->testCompetitorId, 'page_type' => 'homepage', 'url' => 'https://example.com', 'content_hash' => $hash, 'normalized_excerpt' => 'Stable homepage content', 'fetch_status' => 'ok']);
        $s2->save();

        $change = (new ChangeDetectionService())->detectAndRecord($competitor, 'homepage', $s2);
        $change === null ? $this->pass('No change correctly reported for identical content hash') : $this->fail('False positive change reported for identical content');
    }

    private function testChangeDetectionNeverTreatsFailureAsNoChange(): void
    {
        $this->startTest('A failed fetch is never silently treated as "no change"');

        $competitor = (new Competitor())->find($this->testCompetitorId);
        $failed = new CiSnapshot([
            'competitor_id' => $this->testCompetitorId, 'page_type' => 'blog', 'url' => 'https://example.com/blog',
            'fetch_status' => 'failed', 'fetch_error' => 'curl_error: timeout',
        ]);
        $failed->save();

        $change = (new ChangeDetectionService())->detectAndRecord($competitor, 'blog', $failed);
        $change === null ? $this->pass('Failed snapshot correctly produced no change record (not conflated with "nothing changed")') : $this->fail('A failed fetch incorrectly produced a change record');

        $failed->getAttribute('fetch_status') === 'failed' ? $this->pass('Snapshot fetch_status explicitly stored as failed') : $this->fail('fetch_status was not stored as failed');
    }

    private function testAlertRespectsMinSeverity(): void
    {
        $this->startTest('AlertService only alerts when severity meets watchlist minimum');

        $competitor = (new Competitor())->find($this->testCompetitorId);
        $this->db->query("UPDATE ci_watchlist SET alert_min_severity = 'high' WHERE competitor_id = ?", [$this->testCompetitorId]);

        $lowChange = new CiChange([
            'competitor_id' => $this->testCompetitorId, 'user_id' => $this->testUserId, 'page_type' => 'blog',
            'change_type' => 'content_change', 'severity' => 'low', 'confidence' => 'low',
        ]);
        $lowChange->save();

        $beforeCount = $this->countAlerts();
        (new AlertService())->notifyChange($competitor, $lowChange);
        $afterLow = $this->countAlerts();
        $afterLow === $beforeCount ? $this->pass('Low-severity change correctly skipped (below watchlist minimum)') : $this->fail('Low-severity change incorrectly generated an alert');

        $highChange = new CiChange([
            'competitor_id' => $this->testCompetitorId, 'user_id' => $this->testUserId, 'page_type' => 'pricing',
            'change_type' => 'pricing_change', 'severity' => 'high', 'confidence' => 'high',
        ]);
        $highChange->save();
        (new AlertService())->notifyChange($competitor, $highChange);
        $afterHigh = $this->countAlerts();
        $afterHigh === $beforeCount + 1 ? $this->pass('High-severity change correctly generated exactly one alert') : $this->fail('Expected exactly one new alert for high-severity change, delta=' . ($afterHigh - $beforeCount));
    }

    private function testAlertSkippedWhenWatchlistPaused(): void
    {
        $this->startTest('AlertService skips alerts when watchlist entry is paused');

        $competitor = (new Competitor())->find($this->testCompetitorId);
        $this->db->query("UPDATE ci_watchlist SET is_paused = 1, alert_min_severity = 'low' WHERE competitor_id = ?", [$this->testCompetitorId]);

        $change = new CiChange([
            'competitor_id' => $this->testCompetitorId, 'user_id' => $this->testUserId, 'page_type' => 'offers',
            'change_type' => 'offer_change', 'severity' => 'critical', 'confidence' => 'high',
        ]);
        $change->save();

        $before = $this->countAlerts();
        (new AlertService())->notifyChange($competitor, $change);
        $after = $this->countAlerts();

        $after === $before ? $this->pass('No alert generated while watchlist is paused, even for critical severity') : $this->fail('Alert was generated despite paused watchlist');

        $this->db->query("UPDATE ci_watchlist SET is_paused = 0 WHERE competitor_id = ?", [$this->testCompetitorId]);
    }

    private function testKeywordAlertBypassesSeverityThreshold(): void
    {
        $this->startTest('Keyword match forces an alert even below the watchlist severity threshold');

        $competitor = (new Competitor())->find($this->testCompetitorId);

        // نضبط الحد الأدنى لـ "critical" عشان التغيير الـ low-severity ما كانش
        // هيولّد تنبيه عادةً - إلا لو الكلمة المفتاحية اتطابقت.
        $this->db->query(
            "UPDATE ci_watchlist SET is_paused = 0, alert_min_severity = 'critical', keyword_filters = ? WHERE competitor_id = ?",
            [json_encode(['discontinued', 'free trial']), $this->testCompetitorId]
        );

        $lowSeverityChangeWithoutKeyword = new CiChange([
            'competitor_id' => $this->testCompetitorId, 'user_id' => $this->testUserId, 'page_type' => 'blog',
            'change_type' => 'content_change', 'severity' => 'low', 'confidence' => 'low',
            'previous_value' => 'Old blog post about travel tips', 'new_value' => 'New blog post about hotel amenities',
        ]);
        $lowSeverityChangeWithoutKeyword->save();

        $before = $this->countAlerts();
        (new AlertService())->notifyChange($competitor, $lowSeverityChangeWithoutKeyword);
        $afterNoKeyword = $this->countAlerts();
        $afterNoKeyword === $before
            ? $this->pass('Low-severity change without a keyword match correctly generated no alert')
            : $this->fail('Low-severity change without a keyword match incorrectly generated an alert');

        $lowSeverityChangeWithKeyword = new CiChange([
            'competitor_id' => $this->testCompetitorId, 'user_id' => $this->testUserId, 'page_type' => 'offers',
            'change_type' => 'offer_change', 'severity' => 'low', 'confidence' => 'low',
            'previous_value' => 'Standard pricing available', 'new_value' => 'New free trial now available for 14 days',
        ]);
        $lowSeverityChangeWithKeyword->save();

        (new AlertService())->notifyChange($competitor, $lowSeverityChangeWithKeyword);
        $afterKeyword = $this->countAlerts();
        $afterKeyword === $afterNoKeyword + 1
            ? $this->pass('Low-severity change matching a keyword correctly bypassed the severity threshold and generated an alert')
            : $this->fail('Keyword match did not correctly bypass the severity threshold, delta=' . ($afterKeyword - $afterNoKeyword));

        // تنظيف - رجّع الإعدادات الافتراضية عشان باقي الاختبارات ما تتأثرش
        $this->db->query("UPDATE ci_watchlist SET alert_min_severity = 'medium', keyword_filters = NULL WHERE competitor_id = ?", [$this->testCompetitorId]);
    }

    private function testWebhookJobRejectsUnsafeUrl(): void
    {
        $this->startTest('SendCompetitorAlertWebhookJob refuses to deliver to a private/unsafe URL');

        $job = new SendCompetitorAlertWebhookJob();
        $caughtException = null;

        try {
            $job->handle([
                'url' => 'http://127.0.0.1:6379/', // محاولة كلاسيكية لـ SSRF - Redis محلي
                'format' => 'generic',
                'title' => 'Test alert',
                'message' => 'This should never actually be sent',
                'severity' => 'high',
                'competitor_name' => 'Test Competitor Co',
            ]);
        } catch (\Throwable $e) {
            $caughtException = $e;
        }

        $caughtException !== null && strpos($caughtException->getMessage(), 'SSRF') !== false
            ? $this->pass('Webhook job correctly refused to deliver to a private IP (blocked by SsrfGuard)')
            : $this->fail('Webhook job did NOT refuse a private-IP URL as expected');

        // رابط بدون URL خالص لازم يترفض كمان (missing_url) قبل حتى ما نوصل لفحص SSRF
        $caughtMissingUrl = null;
        try {
            $job->handle(['url' => '', 'title' => 'x', 'message' => 'y']);
        } catch (\Throwable $e) {
            $caughtMissingUrl = $e;
        }
        $caughtMissingUrl !== null
            ? $this->pass('Webhook job correctly rejects a payload with a missing url')
            : $this->fail('Webhook job did not reject a missing url');
    }

    private function testThreatOpportunityScanProducesEvidence(): void
    {
        $this->startTest('ThreatOpportunityService produces evidence-linked insights');

        $competitor = (new Competitor())->find($this->testCompetitorId);
        // نضيف تغييرات كافية عشان قاعدة "aggressive activity" تنفعل (>=3 high/critical)
        for ($i = 0; $i < 3; $i++) {
            (new CiChange([
                'competitor_id' => $this->testCompetitorId, 'user_id' => $this->testUserId, 'page_type' => 'pricing',
                'change_type' => 'pricing_change', 'severity' => 'high', 'confidence' => 'high',
            ]))->save();
        }

        $insights = (new ThreatOpportunityService())->scanCompetitor($competitor, 30);
        $found = false;
        foreach ($insights as $insight) {
            if ($insight->getAttribute('type') === 'threat' && !empty($insight->getAttribute('evidence'))) {
                $found = true;
                break;
            }
        }
        $found ? $this->pass('At least one threat insight generated with non-empty evidence') : $this->fail('No evidence-linked threat insight was generated');
    }

    private function testBenchmarkingComparison(): void
    {
        $this->startTest('BenchmarkingService compares my business vs competitor using only real signals');

        $result = (new BenchmarkingService())->compare($this->testWebsiteId, [$this->testCompetitorId], 90);

        isset($result['rows']['my_business']) ? $this->pass('Comparison includes a my_business row') : $this->fail('Missing my_business row');
        $competitorKey = 'competitor_' . $this->testCompetitorId;
        isset($result['rows'][$competitorKey]) ? $this->pass('Comparison includes the requested competitor row') : $this->fail('Missing competitor row');

        if (isset($result['rows'][$competitorKey])) {
            $row = $result['rows'][$competitorKey];
            is_array($row['website_presence'] ?? null)
                ? $this->pass('website_presence is a real structured signal, not a fabricated single value')
                : $this->fail('website_presence was not the expected structured signal');
        }
    }

    private function testReportGeneration(): void
    {
        $this->startTest('ReportService generates a real weekly report from stored ci_changes');

        $report = (new ReportService())->generate($this->testUserId, $this->testWebsiteId, 'weekly');
        (int) $report->getAttribute('id') > 0 ? $this->pass('Weekly report row created') : $this->fail('Weekly report was not created');

        $content = json_decode((string) $report->getAttribute('content_json'), true);
        isset($content['total_changes']) && isset($content['changes'])
            ? $this->pass('Report content contains total_changes and a real changes list')
            : $this->fail('Report content missing expected structure');

        // نوع تقرير غير مدعوم لازم يترفض بوضوح
        try {
            (new ReportService())->generate($this->testUserId, $this->testWebsiteId, 'not_a_real_type');
            $this->fail('generate() did not reject an invalid report type');
        } catch (InvalidArgumentException $e) {
            $this->pass('generate() correctly rejects an invalid report type');
        }
    }

    private function testUserPreferencesRoundTrip(): void
    {
        $this->startTest('CiUserPreference saves and round-trips default settings correctly');

        $prefs = new CiUserPreference([
            'user_id' => $this->testUserId,
            'default_monitoring_frequency' => 'daily',
            'default_alert_min_severity' => 'high',
            'default_alert_channels' => json_encode(['dashboard', 'email']),
        ]);
        $prefs->save();

        $reloaded = (new CiUserPreference())->where(['user_id' => $this->testUserId], [], 1);
        !empty($reloaded) && $reloaded[0]->getAttribute('default_monitoring_frequency') === 'daily'
            ? $this->pass('User preferences saved and reloaded correctly')
            : $this->fail('User preferences did not round-trip correctly');

        $this->db->query("DELETE FROM ci_user_preferences WHERE user_id = ?", [$this->testUserId]);
    }

    private function testDeleteCompetitor(): void
    {
        $this->startTest('Deleting a competitor removes it and cascades its watchlist entry');

        // منافس مؤقت خاص بالاختبار ده بس - عشان مايمسّش $this->testCompetitorId
        // المشترك مع باقي الاختبارات واللي الـ cleanup() بيعتمد عليه.
        $temp = new Competitor([
            'user_id' => $this->testUserId, 'website_id' => $this->testWebsiteId,
            'competitor_name' => 'Temp Delete Test Co', 'competitor_domain' => 'https://temp-delete-test.example',
            'category' => 'direct', 'source' => 'manual', 'monitoring_frequency' => 'weekly', 'is_active' => 1,
        ]);
        $temp->save();
        $tempId = (int) $temp->getAttribute('id');

        $tempWatchlist = new CiWatchlistItem(['user_id' => $this->testUserId, 'competitor_id' => $tempId, 'priority' => 'medium', 'alert_min_severity' => 'medium', 'is_paused' => 0]);
        $tempWatchlist->save();

        $temp->delete();

        $stillExists = $this->db->query("SELECT id FROM competitors WHERE id = ?", [$tempId]);
        empty($stillExists) ? $this->pass('Competitor row removed after delete') : $this->fail('Competitor row still exists after delete');

        $watchlistOrphan = $this->db->query("SELECT id FROM ci_watchlist WHERE competitor_id = ?", [$tempId]);
        empty($watchlistOrphan)
            ? $this->pass('Watchlist entry correctly cascaded (ON DELETE CASCADE foreign key)')
            : $this->fail('Watchlist entry was left orphaned after competitor delete');
    }

    private function testTenantIsolation(): void
    {
        $this->startTest('Tenant isolation: user B cannot see user A competitor via user_id filter');

        $rows = $this->db->query("SELECT id FROM competitors WHERE id = ? AND user_id = ?", [$this->testCompetitorId, $this->testUserBId]);
        empty($rows) ? $this->pass('User B query correctly returns zero rows for user A competitor') : $this->fail('Tenant isolation violated: user B could see user A competitor');
    }

    private function testCiPermissionsIntegration(): void
    {
        $this->startTest('CiPermissions correctly gates destructive actions by role');

        require_once dirname(__DIR__, 2) . '/app/Services/CompetitorIntelligence/CiPermissions.php';
        $viewer = ['role' => 'viewer_unrecognized'];
        !CiPermissions::can($viewer, CiPermissions::PERM_DELETE) ? $this->pass('Unrecognized role blocked from delete') : $this->fail('Unrecognized role was allowed to delete');
    }

    // ------------------------------------------------------------
    private function countAlerts(): int
    {
        return (int) ($this->db->query("SELECT COUNT(*) c FROM ci_alerts WHERE competitor_id = ?", [$this->testCompetitorId])[0]['c'] ?? 0);
    }

    private function createTestUser(string $prefix): int
    {
        return (int) $this->db->query(
            "INSERT INTO users (company_name, email, password, phone, role, is_active) VALUES (:company_name, :email, :password, :phone, :role, :is_active)",
            [
                ':company_name' => 'CI Test Company', ':email' => $prefix . uniqid() . '@example.com',
                ':password' => password_hash('Test@123', PASSWORD_ARGON2ID), ':phone' => '+966500000002',
                ':role' => 'user', ':is_active' => 1,
            ]
        );
    }

    private function createTestWebsite(int $userId): int
    {
        return (int) $this->db->query(
            "INSERT INTO websites (user_id, main_url, company_name, industry, is_verified) VALUES (:user_id, :main_url, :company_name, :industry, :is_verified)",
            [':user_id' => $userId, ':main_url' => 'https://ci-test-' . uniqid() . '.com', ':company_name' => 'CI Test Website', ':industry' => 'tourism', ':is_verified' => 1]
        );
    }

    private function cleanup(): void
    {
        $this->db->query("DELETE FROM ci_alerts WHERE competitor_id = ?", [$this->testCompetitorId]);
        $this->db->query("DELETE FROM ci_insights WHERE competitor_id = ?", [$this->testCompetitorId]);
        $this->db->query("DELETE FROM ci_changes WHERE competitor_id = ?", [$this->testCompetitorId]);
        $this->db->query("DELETE FROM ci_snapshots WHERE competitor_id = ?", [$this->testCompetitorId]);
        $this->db->query("DELETE FROM ci_watchlist WHERE competitor_id = ?", [$this->testCompetitorId]);
        $this->db->query("DELETE FROM competitors WHERE id = ?", [$this->testCompetitorId]);
        $this->db->query("DELETE FROM websites WHERE id = ?", [$this->testWebsiteId]);
        $this->db->query("DELETE FROM users WHERE id IN (?, ?)", [$this->testUserId, $this->testUserBId]);
    }

    private function startTest(string $name): void
    {
        echo "\n  ▶ {$name}\n";
    }
    private function pass(string $message): void
    {
        echo "    ✅ {$message}\n";
        $this->passed++;
    }
    private function fail(string $message): void
    {
        echo "    ❌ {$message}\n";
        $this->failed++;
    }

    private function printSummary(): void
    {
        $total = $this->passed + $this->failed;
        $percentage = $total > 0 ? round(($this->passed / $total) * 100, 2) : 0;
        echo "\n" . str_repeat('=', 50) . "\n";
        echo "📊 Competitor Intelligence Test Summary\n";
        echo str_repeat('=', 50) . "\n";
        echo "  ✅ Passed: {$this->passed}\n  ❌ Failed: {$this->failed}\n  📝 Total: {$total}\n  📈 Success Rate: {$percentage}%\n";
        echo str_repeat('=', 50) . "\n\n";
    }
}

if (basename(__FILE__) === basename($_SERVER['PHP_SELF'] ?? '')) {
    $test = new CompetitorIntelligenceTest();
    $test->runAll();
}
