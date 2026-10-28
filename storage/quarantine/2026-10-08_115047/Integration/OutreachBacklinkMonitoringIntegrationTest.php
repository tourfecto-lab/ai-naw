<?php

/**
 * Tourfecto - Outreach Backlink Monitoring + Follow-Ups + Performance Test
 * (Item 2a/2b/2c) - @version 1.0.0  @date 2026-08-31
 *
 * بيفحص:
 *   1) registerAcquiredLink: تسجيل رابط بعد link_acquired + idempotency.
 *   2) checkLink: فحص رابط بحقنة fetcher وهمية (live على 2xx، lost على 4xx/5xx/خطأ).
 *   3) monitorDue: يرجع الروابط المستحقة بس (متفحصةش أو عدّى 7 أيام).
 *   4) summaryForWebsite: عدّ live/lost/pending لموقع معيّن.
 *   5) controller: updateProspectStatus(status=link_acquired) يسجّل الرابط
 *      تلقائيًا في monitored_backlinks.
 *   6) OutreachFollowUpDraftService: يولّد مسودات متابعة بعد 7 أيام بحد أقصى
 *      3 متابعات، ومش بيبعث أي حاجة (draft فقط)، وبيبلّغ المستخدم.
 *   7) OutreachPerformanceService: قمع المراحل + معدلات التحويل + حالة
 *      الباك لينكس + متوسط الوقت للرابط.
 *
 * محتاج الميجريشن: 2026_08_31_000001_create_monitored_backlinks.sql (idempotent).
 * بيتخطى تلقائيًا لو DB غير متاحة.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/Core/Model.php';
require_once __DIR__ . '/../../app/Models/User.php';
require_once __DIR__ . '/../../app/Models/Website.php';
require_once __DIR__ . '/../../app/Models/OutreachProspect.php';
require_once __DIR__ . '/../../app/Models/OutreachEmail.php';
require_once __DIR__ . '/../../app/Models/MonitoredBacklink.php';
require_once __DIR__ . '/../../app/Models/Notification.php';
require_once __DIR__ . '/../../app/Models/ActivityLog.php';
require_once __DIR__ . '/../../app/Models/Subscription.php';
require_once __DIR__ . '/../../app/Services/Outreach/OutreachEmailGenerator.php';
require_once __DIR__ . '/../../app/Services/Outreach/ProspectDiscoverySourceInterface.php';
require_once __DIR__ . '/../../app/Services/Outreach/CompetitorBacklinkDiscoverySource.php';
require_once __DIR__ . '/../../app/Services/Outreach/BacklinkMonitorService.php';
require_once __DIR__ . '/../../app/Services/Outreach/OutreachFollowUpDraftService.php';
require_once __DIR__ . '/../../app/Services/Outreach/OutreachPerformanceService.php';
require_once __DIR__ . '/../../app/Services/Subscription/UsageTracker.php';
require_once __DIR__ . '/../../app/Services/Subscription/SubscriptionValidator.php';
require_once __DIR__ . '/../../app/Services/CompetitorIntelligence/CiRateLimiter.php';
require_once __DIR__ . '/../../app/Controllers/OutreachController.php';

/** مولد رسائل وهمي - بيمنع أي استدعاء AI/شبكة فعلًا في الاختبارات */
class Item2FakeOutreachEmailGenerator extends OutreachEmailGenerator
{
    public function __construct()
    {
    }

    public function generate(array $prospect, array $myWebsite, int $sequenceNumber = 0): array
    {
        return [
            'success' => true,
            'data' => [
                'subject' => 'متابعة ' . $sequenceNumber . ' لـ ' . ($prospect['domain'] ?? 'موقعك'),
                'body' => "مرحبًا فريق " . ($prospect['domain'] ?? '') . "،\nمتابعة رقم " . $sequenceNumber . ".",
            ],
        ];
    }
}

final class OutreachBacklinkMonitoringIntegrationTest extends TestCase
{
    private const USER_ID = 999402;
    private const WEBSITE_ID = 999402;
    private const OTHER_USER_ID = 999403;
    private const OTHER_WEBSITE_ID = 999403;

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

            foreach (['monitored_backlinks', 'outreach_prospects', 'outreach_emails'] as $table) {
                $found = $conn->query("SHOW TABLES LIKE '{$table}'")->fetchAll();
                if (empty($found)) {
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
            $this->markTestSkipped('DB غير متاحة أو جداول Outreach/Monitored مش متشغّلة');
        }

        $_SERVER['auth_user'] = null;
        unset($_SERVER['auth_user']);
        $_GET = [];

        $this->cleanup();

        $pdo->exec("INSERT INTO users (id, email, password, company_name, created_at)
                    VALUES (" . self::USER_ID . ", 'backlink-test@tourfecto.test', 'x', 'Backlink Test Travel', NOW())
                    ON DUPLICATE KEY UPDATE email = email");
        $pdo->exec("INSERT INTO websites (id, user_id, main_url, company_name, industry, target_language, target_country)
                    VALUES (" . self::WEBSITE_ID . ", " . self::USER_ID . ", 'https://backlink-owntest.com', 'Backlink Test Travel', 'tourism', 'ar', 'SA')
                    ON DUPLICATE KEY UPDATE main_url = VALUES(main_url)");
    }

    private function cleanup(): void
    {
        $pdo = $this->db();
        if ($pdo === null) {
            return;
        }
        foreach ([self::USER_ID, self::OTHER_USER_ID] as $uid) {
            $pdo->exec("DELETE FROM monitored_backlinks WHERE user_id = {$uid}");
        }
        $stmt = $pdo->prepare("DELETE FROM outreach_emails WHERE prospect_id IN
                                (SELECT id FROM outreach_prospects WHERE user_id = ?)");
        $stmt->execute([self::USER_ID]);
        $stmt = $pdo->prepare("DELETE FROM outreach_prospects WHERE user_id IN (?, ?)");
        $stmt->execute([self::USER_ID, self::OTHER_USER_ID]);
        $pdo->exec("DELETE FROM notifications WHERE user_id IN (" . self::USER_ID . ", " . self::OTHER_USER_ID . ")");
    }

    private function addProspect(string $domain, string $status = 'contacted', ?string $linkUrl = null): int
    {
        $pdo = $this->db();
        $stmt = $pdo->prepare("INSERT INTO outreach_prospects
                    (user_id, website_id, domain, contact_name, contact_email, status, link_url, notes)
                    VALUES (?, ?, ?, NULL, 'contact@example.test', ?, ?, 'monitor test')");
        $stmt->execute([self::USER_ID, self::WEBSITE_ID, $domain, $status, $linkUrl]);
        return (int) $pdo->lastInsertId();
    }

    private function addSentEmail(int $prospectId, int $sequenceNumber, string $sentAt): int
    {
        $pdo = $this->db();
        $stmt = $pdo->prepare("INSERT INTO outreach_emails
                    (prospect_id, sequence_number, subject, body, status, sent_at)
                    VALUES (?, ?, 'رسالة', 'نص', 'sent', ?)");
        $stmt->execute([$prospectId, $sequenceNumber, $sentAt]);
        return (int) $pdo->lastInsertId();
    }

    private function monitorService(?callable $fetcher = null): BacklinkMonitorService
    {
        return new BacklinkMonitorService($fetcher);
    }

    public function testRegisterAcquiredLinkIsIdempotent(): void
    {
        $prospectId = $this->addProspect('linked.example.com', 'link_acquired', 'https://linked.example.com/our-page');

        $service = $this->monitorService();
        $first = $service->registerAcquiredLink(self::USER_ID, self::WEBSITE_ID, $prospectId, 'https://linked.example.com/our-page');
        $this->assertSame('pending', $first->getAttribute('status'));
        $this->assertSame('linked.example.com', $first->getAttribute('domain'));

        // التكرار لازم يرجّع نفس السجل - مش سجل جديد
        $second = $service->registerAcquiredLink(self::USER_ID, self::WEBSITE_ID, $prospectId, 'https://linked.example.com/our-page');
        $this->assertSame((int) $first->getAttribute('id'), (int) $second->getAttribute('id'));

        $count = $this->db()->query(
            "SELECT COUNT(*) FROM monitored_backlinks WHERE prospect_id = {$prospectId}"
        )->fetchColumn();
        $this->assertSame('1', (string) $count);
    }

    public function testCheckLinkMarksLiveOn2xx(): void
    {
        $prospectId = $this->addProspect('live.example.com', 'link_acquired', 'https://live.example.com/page');
        $service = $this->monitorService(fn () => ['success' => true, 'http_status' => 200, 'error' => null]);
        $backlink = $service->registerAcquiredLink(self::USER_ID, self::WEBSITE_ID, $prospectId, 'https://live.example.com/page');

        $res = $service->checkLink((int) $backlink->getAttribute('id'));

        $this->assertTrue($res['success']);
        $this->assertSame('live', $res['status']);
        $this->assertSame(200, $res['http_status']);
        $this->assertSame('live', (new MonitoredBacklink())->find((int) $backlink->getAttribute('id'))->getAttribute('status'));
        $this->assertSame(1, (int) (new MonitoredBacklink())->find((int) $backlink->getAttribute('id'))->getAttribute('check_count'));
    }

    public function testCheckLinkMarksLostOn404AndOnNetworkError(): void
    {
        $service404 = $this->monitorService(fn () => ['success' => false, 'http_status' => 404, 'error' => 'http_404']);
        $prospect404 = $this->addProspect('gone.example.com', 'link_acquired', 'https://gone.example.com/page');
        $b404 = $service404->registerAcquiredLink(self::USER_ID, self::WEBSITE_ID, $prospect404, 'https://gone.example.com/page');
        $res = $service404->checkLink((int) $b404->getAttribute('id'));
        $this->assertSame('lost', $res['status']);
        $this->assertSame(404, $res['http_status']);

        $serviceErr = $this->monitorService(fn () => ['success' => false, 'http_status' => null, 'error' => 'curl_error: timeout']);
        $prospectErr = $this->addProspect('timeout.example.com', 'link_acquired', 'https://timeout.example.com/page');
        $bErr = $serviceErr->registerAcquiredLink(self::USER_ID, self::WEBSITE_ID, $prospectErr, 'https://timeout.example.com/page');
        $res = $serviceErr->checkLink((int) $bErr->getAttribute('id'));
        $this->assertSame('lost', $res['status']);
        $this->assertNull($res['http_status']);
        $this->assertStringContainsString('curl_error', (string) $res['error']);
    }

    public function testDueBacklinksReturnsOnlyPendingOrStale(): void
    {
        $pdo = $this->db();
        $p1 = $this->addProspect('due1.example.com', 'link_acquired', 'https://due1.example.com/a');
        $p2 = $this->addProspect('due2.example.com', 'link_acquired', 'https://due2.example.com/b');
        $p3 = $this->addProspect('fresh.example.com', 'link_acquired', 'https://fresh.example.com/c');

        $service = $this->monitorService();
        $service->registerAcquiredLink(self::USER_ID, self::WEBSITE_ID, $p1, 'https://due1.example.com/a'); // last_checked = null
        $service->registerAcquiredLink(self::USER_ID, self::WEBSITE_ID, $p2, 'https://due2.example.com/b');
        $service->registerAcquiredLink(self::USER_ID, self::WEBSITE_ID, $p3, 'https://fresh.example.com/c');

        // p2: اتحقق من 8 أيام → مستحق. p3: اتحقق دلوقتي → مش مستحق.
        $pdo->exec("UPDATE monitored_backlinks SET last_checked_at = DATE_SUB(NOW(), INTERVAL 8 DAY) WHERE link_url = 'https://due2.example.com/b'");
        $pdo->exec("UPDATE monitored_backlinks SET last_checked_at = NOW() WHERE link_url = 'https://fresh.example.com/c'");

        $due = $service->dueBacklinks();
        $urls = array_column($due, 'link_url');
        $this->assertContains('https://due1.example.com/a', $urls);
        $this->assertContains('https://due2.example.com/b', $urls);
        $this->assertNotContains('https://fresh.example.com/c', $urls);
    }

    public function testSummaryForWebsiteCountsStatuses(): void
    {
        $p1 = $this->addProspect('s1.example.com', 'link_acquired', 'https://s1.example.com/x');
        $p2 = $this->addProspect('s2.example.com', 'link_acquired', 'https://s2.example.com/y');
        $p3 = $this->addProspect('s3.example.com', 'link_acquired', 'https://s3.example.com/z');

        $live = $this->monitorService(fn () => ['success' => true, 'http_status' => 200, 'error' => null]);
        $b1 = $live->registerAcquiredLink(self::USER_ID, self::WEBSITE_ID, $p1, 'https://s1.example.com/x');
        $b2 = $live->registerAcquiredLink(self::USER_ID, self::WEBSITE_ID, $p2, 'https://s2.example.com/y');
        $lost = $this->monitorService(fn () => ['success' => false, 'http_status' => 410, 'error' => 'http_410']);
        $b3 = $lost->registerAcquiredLink(self::USER_ID, self::WEBSITE_ID, $p3, 'https://s3.example.com/z');

        $live->checkLink((int) $b1->getAttribute('id'));
        $live->checkLink((int) $b2->getAttribute('id'));
        $lost->checkLink((int) $b3->getAttribute('id'));

        $summary = $this->monitorService()->summaryForWebsite(self::USER_ID, self::WEBSITE_ID);
        $this->assertSame(2, $summary['live']);
        $this->assertSame(1, $summary['lost']);
        $this->assertSame(0, $summary['pending']);
        $this->assertSame(3, $summary['total']);
    }

    public function testControllerRegistersBacklinkOnLinkAcquired(): void
    {
        $prospectId = $this->addProspect('ctrl-linked.example.com', 'negotiating', null);

        $user = (new User())->find(self::USER_ID);
        if ($user === null) {
            $this->markTestSkipped('مستخدم الاختبار مش موجود');
        }
        $_SERVER['auth_user'] = $user->toArray();
        $_GET = [];

        // بدون status => 422 (الكلاس بيقرأ $_GET في الـ constructor)
        $controller = new OutreachController();
        $response = $controller->updateProspectStatus(['id' => (string) $prospectId]);
        $this->assertFalse($response['success']);
        $this->assertSame(422, $response['code']);

        $_GET = ['status' => 'link_acquired', 'link_url' => 'https://ctrl-linked.example.com/backlink'];
        $controller = new OutreachController();
        $response = $controller->updateProspectStatus(['id' => (string) $prospectId]);
        $this->assertTrue($response['success'], 'تحديث الحالة لازم ينجح: ' . ($response['error'] ?? ''));
        $this->assertSame('link_acquired', $response['data']['prospect']['status']);
        $this->assertSame('https://ctrl-linked.example.com/backlink', $response['data']['prospect']['link_url']);

        // لازم يتسجّل الرابط في monitored_backlinks تلقائيًا
        $row = $this->db()->query(
            "SELECT * FROM monitored_backlinks WHERE prospect_id = {$prospectId} AND link_url = 'https://ctrl-linked.example.com/backlink'"
        )->fetchAll(PDO::FETCH_ASSOC);
        $this->assertCount(1, $row);
        $this->assertSame('ctrl-linked.example.com', $row[0]['domain']);

        $_SERVER['auth_user'] = null;
        unset($_SERVER['auth_user']);
    }

    public function testFollowUpDraftServiceGeneratesDraftsOnlyAfter7Days(): void
    {
        $pdo = $this->db();

        // مرشّح اتواصل معاه من 9 أيام - هياخد متابعة sequence 1
        $old = $this->addProspect('old.example.com', 'contacted', null);
        $this->addSentEmail($old, 0, date('Y-m-d H:i:s', strtotime('-9 days')));

        // مرشّح اتواصل معاه امبارح - مش مستحق لسه
        $recent = $this->addProspect('recent.example.com', 'contacted', null);
        $this->addSentEmail($recent, 0, date('Y-m-d H:i:s', strtotime('-1 day')));

        // مرشّح declined - مش مستحق مهما عدّى الوقت
        $declined = $this->addProspect('declined.example.com', 'declined', null);
        $this->addSentEmail($declined, 0, date('Y-m-d H:i:s', strtotime('-30 days')));

        $service = new OutreachFollowUpDraftService(new Item2FakeOutreachEmailGenerator());
        $stats = $service->generateDueFollowUps();

        $this->assertSame(1, $stats['generated'], 'فقط المرشّح القديم النشط لازم ياخد متابعة');
        $this->assertCount(1, $stats['drafts']);

        // المسودة saved بـ draft + sequence 1
        $email = $this->db()->query(
            "SELECT * FROM outreach_emails WHERE prospect_id = {$old} AND sequence_number = 1"
        )->fetchAll(PDO::FETCH_ASSOC);
        $this->assertCount(1, $email);
        $this->assertSame('draft', $email[0]['status']);

        // مفيش أي رسالة للمرشّحين التانيين
        $countRecent = $pdo->query("SELECT COUNT(*) FROM outreach_emails WHERE prospect_id = {$recent}")->fetchColumn();
        $this->assertSame('1', (string) $countRecent); // لسه الرسالة الأولى بس
        $countDeclined = $pdo->query("SELECT COUNT(*) FROM outreach_emails WHERE prospect_id = {$declined}")->fetchColumn();
        $this->assertSame('1', (string) $countDeclined);

        // إشعار جاهز للمراجعة اتسجّل للمستخدم
        $notif = $pdo->query(
            "SELECT COUNT(*) FROM notifications WHERE user_id = " . self::USER_ID . " AND type = 'outreach_followup_draft'"
        )->fetchColumn();
        $this->assertSame('1', (string) $notif);
    }

    public function testFollowUpDraftServiceIdempotentAndCappedAtThree(): void
    {
        $pdo = $this->db();
        $prospect = $this->addProspect('cap.example.com', 'contacted', null);
        $this->addSentEmail($prospect, 0, date('Y-m-d H:i:s', strtotime('-30 days')));

        $service = new OutreachFollowUpDraftService(new Item2FakeOutreachEmailGenerator());

        // seq 1: أول تشغيلة تولّد المتابعة الأولى
        $r1 = $service->generateDueFollowUps();
        $this->assertSame(1, $r1['generated']);

        // Idempotent: إعادة التشغيل فورًا لا تولّد نسخة مكررة من نفس الـ sequence
        $r1b = $service->generateDueFollowUps();
        $this->assertSame(0, $r1b['generated']);

        // محاكاة موافقة المستخدم + الإرسال الفعلي (الخدمة لا ترسل أبدًا) ثم تقادم الوقت
        $pdo->exec("UPDATE outreach_emails SET status = 'sent', sent_at = DATE_SUB(NOW(), INTERVAL 8 DAY)
                    WHERE prospect_id = {$prospect} AND sequence_number = 1");

        // seq 2
        $r2 = $service->generateDueFollowUps();
        $this->assertSame(1, $r2['generated']);
        $pdo->exec("UPDATE outreach_emails SET status = 'sent', sent_at = DATE_SUB(NOW(), INTERVAL 8 DAY)
                    WHERE prospect_id = {$prospect} AND sequence_number = 2");

        // seq 3
        $r3 = $service->generateDueFollowUps();
        $this->assertSame(1, $r3['generated']);
        $pdo->exec("UPDATE outreach_emails SET status = 'sent', sent_at = DATE_SUB(NOW(), INTERVAL 8 DAY)
                    WHERE prospect_id = {$prospect} AND sequence_number = 3");

        // بعد الوصول للحد الأقصى (3 متابعات لكل مرشّح) - مفيش متابعات جديدة
        $r4 = $service->generateDueFollowUps();
        $this->assertSame(0, $r4['generated']);

        $totalEmails = $pdo->query("SELECT COUNT(*) FROM outreach_emails WHERE prospect_id = {$prospect}")->fetchColumn();
        $this->assertSame('4', (string) $totalEmails); // أولى + 3 متابعات
    }

    public function testPerformanceReport(): void
    {
        $pdo = $this->db();

        // قمع المراحل
        $prospects = [
            'link_acquired' => $this->addProspect('perf1.example.com', 'link_acquired', 'https://perf1.example.com/x'),
            'replied' => $this->addProspect('perf2.example.com', 'replied', null),
            'contacted' => $this->addProspect('perf3.example.com', 'contacted', null),
            'prospect' => $this->addProspect('perf4.example.com', 'prospect', null),
        ];

        // سجلّين باك لينكس: واحد live وواحد lost
        $live = $this->monitorService(fn () => ['success' => true, 'http_status' => 200, 'error' => null]);
        $lost = $this->monitorService(fn () => ['success' => false, 'http_status' => 410, 'error' => 'http_410']);
        $b1 = $live->registerAcquiredLink(self::USER_ID, self::WEBSITE_ID, $prospects['link_acquired'], 'https://perf1.example.com/x');
        $b2 = $live->registerAcquiredLink(self::USER_ID, self::WEBSITE_ID, $prospects['replied'], 'https://perf1.example.com/lost');
        $live->checkLink((int) $b1->getAttribute('id'));
        $lost->checkLink((int) $b2->getAttribute('id'));

        // متوسط الوقت للرابط: مرّت 3 أيام من created_at لـ updated_at
        $pdo->exec("UPDATE outreach_prospects SET updated_at = DATE_ADD(created_at, INTERVAL 3 DAY) WHERE id = {$prospects['link_acquired']}");

        $report = (new OutreachPerformanceService())->report(self::USER_ID, self::WEBSITE_ID);

        $this->assertSame(4, $report['funnel']['total']);
        $this->assertSame(1, $report['funnel']['link_acquired']);
        $this->assertSame(1, $report['funnel']['replied']);
        $this->assertSame(1, $report['funnel']['contacted']);
        $this->assertSame(1, $report['funnel']['prospect']);

        $this->assertSame(1, $report['backlinks']['live']);
        $this->assertSame(1, $report['backlinks']['lost']);
        $this->assertSame(0, $report['backlinks']['pending']);
        $this->assertSame(2, $report['backlinks']['total']);

        $this->assertSame(3.0, $report['avg_time_to_link_days']);

        // reached = contacted(1)+replied(1)+link_acquired(1) = 3 من 4
        $this->assertSame(75.0, $report['conversion']['contact_rate']);
        // replied = replied(1)+link_acquired(1) = 2 من reached(3)
        $this->assertSame(66.7, $report['conversion']['reply_rate']);
        // negotiating = link_acquired(1) من replied(2)
        $this->assertSame(50.0, $report['conversion']['negotiation_rate']);
        // acquired = 1 من negotiating(1)
        $this->assertSame(100.0, $report['conversion']['acquisition_rate']);
        $this->assertSame(25.0, $report['conversion']['overall_acquired_rate']);
    }
}
