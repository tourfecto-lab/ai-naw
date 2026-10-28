<?php

/**
 * Tourfecto - Outreach Prospect Discovery Integration Test
 * بيفحص اكتشاف مرشّحين الـ Backlink التلقائي (Phase 10 pipeline):
 *   1) اكتشاف مرشحين من منافسين متتبعين فعليًا (بيانات عامة معلنة فقط)،
 *      حفظهم بـ status='prospect' بدون أي بيانات تواصل شخصية
 *      (contact_email/contact_name NULL دائمًا - الأمان إلزامي).
 *   2) Idempotency: إعادة الاكتشاف بتضيف صفر مرشحين جدد (duplicates).
 *   3) استبعاد دومين الموقع نفسه (skipped_own).
 *   4) استبعاد الدومينات اللي اتعمل منها رابط فعلًا (already_linked).
 *   5) insufficient_data لما مفيش منافسين متتبعين (لا اختلاق).
 *   6) توليد مسودة (draft) لكل مرشح جديد - وأي إرسال بيظل محتاج موافقة.
 *   7) relevance_score (0-100) في النطاق ومثبّت في notes.
 *   8) endpoint /api/outreach/discover: 401 بدون مصادقة، وسيناريو ناجح
 *      (مع fake generator لمنع أي استدعاء AI خارجي فعلًا).
 *
 * محتاج الميجريشنز: 2026_08_08_000042 (CI tables), 2026_08_08_000048
 * (outreach), 2026_08_14_000048 (ci_rate_limits), 2026_08_15_000070
 * (competitors). بيتخطى تلقائيًا (markTestSkipped) لو DB غير متاحة.
 * @version 1.0.0  @date 2026-08-28
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/Core/Model.php';
require_once __DIR__ . '/../../app/Models/User.php';
require_once __DIR__ . '/../../app/Models/Website.php';
require_once __DIR__ . '/../../app/Models/OutreachProspect.php';
require_once __DIR__ . '/../../app/Models/OutreachEmail.php';
require_once __DIR__ . '/../../app/Models/ActivityLog.php';
require_once __DIR__ . '/../../app/Services/Outreach/ProspectDiscoverySourceInterface.php';
require_once __DIR__ . '/../../app/Services/Outreach/CompetitorBacklinkDiscoverySource.php';
require_once __DIR__ . '/../../app/Services/Outreach/OutreachEmailGenerator.php';
require_once __DIR__ . '/../../app/Services/Outreach/ProspectDiscoveryService.php';
require_once __DIR__ . '/../../app/Services/CompetitorIntelligence/CiRateLimiter.php';
require_once __DIR__ . '/../../app/Services/Subscription/UsageTracker.php';
require_once __DIR__ . '/../../app/Services/Subscription/SubscriptionValidator.php';
require_once __DIR__ . '/../../app/Models/Subscription.php';
require_once __DIR__ . '/../../app/Controllers/OutreachController.php';

/** مولد رسائل وهمي - بيمنع أي استدعاء AI/شبكة فعلًا في الاختبارات */
class FakeOutreachEmailGenerator extends OutreachEmailGenerator
{
    public function __construct()
    {
    }

    public function generate(array $prospect, array $myWebsite, int $sequenceNumber = 0): array
    {
        return [
            'success' => true,
            'data' => [
                'subject' => 'تعاون محتوى مع ' . ($prospect['domain'] ?? 'موقعك'),
                'body' => "مرحبًا فريق " . ($prospect['domain'] ?? '') . "،\nنقترح تعاون محتوى سياحي.",
            ],
        ];
    }
}

final class OutreachDiscoveryIntegrationTest extends TestCase
{
    private const USER_ID = 999400;
    private const WEBSITE_ID = 999400;
    private const OTHER_USER_ID = 999401;
    private const OTHER_WEBSITE_ID = 999401;

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

            foreach (['outreach_prospects', 'competitors', 'ci_snapshots', 'ci_rate_limits'] as $table) {
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
            $this->markTestSkipped('DB غير متاحة أو جداول Outreach/CI مش متشغّلة - راجع تعليق أعلى الملف');
        }

        // تنظيف المصادقة حتى لا تتسرب بين الاختبارات (ترتيب عشوائي)
        $_SERVER['auth_user'] = null;
        unset($_SERVER['auth_user']);
        $_GET = [];

        $this->cleanup();

        // مستخدم + موقع (اختبار البند 1)
        $pdo->exec("INSERT INTO users (id, email, password, company_name, created_at)
                    VALUES (" . self::USER_ID . ", 'outreach-test@tourfecto.test', 'x', 'Outreach Test Travel', NOW())
                    ON DUPLICATE KEY UPDATE email = email");
        $pdo->exec("INSERT INTO websites (id, user_id, main_url, company_name, industry, target_language, target_country)
                    VALUES (" . self::WEBSITE_ID . ", " . self::USER_ID . ", 'https://mytravel-owntest.com', 'Outreach Test Travel', 'tourism', 'ar', 'SA')
                    ON DUPLICATE KEY UPDATE main_url = VALUES(main_url)");

        // مستخدم/موقع تاني لعزل الاختبارات
        $pdo->exec("INSERT INTO users (id, email, password, company_name, created_at)
                    VALUES (" . self::OTHER_USER_ID . ", 'outreach-test-2@tourfecto.test', 'x', 'Isolation Travel', NOW())
                    ON DUPLICATE KEY UPDATE email = email");
        $pdo->exec("INSERT INTO websites (id, user_id, main_url, company_name, industry)
                    VALUES (" . self::OTHER_WEBSITE_ID . ", " . self::OTHER_USER_ID . ", 'https://isolation-test.com', 'Isolation Travel', 'tourism')
                    ON DUPLICATE KEY UPDATE main_url = VALUES(main_url)");
    }

    private function cleanup(): void
    {
        $pdo = $this->db();
        if ($pdo === null) {
            return;
        }
        $stmt = $pdo->prepare("DELETE FROM outreach_emails WHERE prospect_id IN
                                (SELECT id FROM outreach_prospects WHERE user_id = ?)");
        $stmt->execute([self::USER_ID]);
        $stmt = $pdo->prepare("DELETE FROM outreach_prospects WHERE user_id IN (?, ?)");
        $stmt->execute([self::USER_ID, self::OTHER_USER_ID]);
        $stmt = $pdo->prepare("DELETE FROM ci_snapshots WHERE competitor_id IN
                                (SELECT id FROM competitors WHERE user_id IN (?, ?))");
        $stmt->execute([self::USER_ID, self::OTHER_USER_ID]);
        $stmt = $pdo->prepare("DELETE FROM competitors WHERE user_id IN (?, ?)");
        $stmt->execute([self::USER_ID, self::OTHER_USER_ID]);
        $pdo->exec("DELETE FROM ci_rate_limits WHERE scope_key LIKE 'discovery_run:user:" . self::USER_ID . "%'");
        $stmt = $pdo->prepare("DELETE FROM activity_logs WHERE user_id IN (?, ?)");
        $stmt->execute([self::USER_ID, self::OTHER_USER_ID]);
    }

    private function addCompetitor(string $domain, string $name = '', float $score = 0, int $websiteId = 0): int
    {
        $pdo = $this->db();
        $websiteId = $websiteId ?: self::WEBSITE_ID;
        $stmt = $pdo->prepare("INSERT INTO competitors
                    (user_id, website_id, competitor_domain, competitor_name, competitor_score, is_active, source)
                    VALUES (?, ?, ?, ?, ?, 1, 'manual')");
        $stmt->execute([self::USER_ID, $websiteId, $domain, $name ?: $domain, $score]);
        return (int) $pdo->lastInsertId();
    }

    private function addSnapshot(int $competitorId, string $pageType = 'homepage', string $url = ''): int
    {
        $pdo = $this->db();
        $stmt = $pdo->prepare("INSERT INTO ci_snapshots
                    (competitor_id, page_type, url, http_status, fetch_status)
                    VALUES (?, ?, ?, 200, 'ok')");
        $stmt->execute([$competitorId, $pageType, $url ?: 'https://' . uniqid() . '.example/page']);
        return (int) $pdo->lastInsertId();
    }

    private function service(?OutreachEmailGenerator $generator = null): ProspectDiscoveryService
    {
        return new ProspectDiscoveryService(
            new CompetitorBacklinkDiscoverySource(),
            $generator ?? new FakeOutreachEmailGenerator()
        );
    }

    public function testRelevanceScoreBoundsAndSensitivity(): void
    {
        $low = ProspectDiscoveryService::relevanceScore(['competitor_score' => 0, 'has_snapshot' => false], 'tourism', 'x.com');
        $high = ProspectDiscoveryService::relevanceScore(['competitor_score' => 100, 'has_snapshot' => true, 'business_type' => 'موقع سياحي في نفس المجال'], 'tourism', 'Nile Cruises Tourism');

        $this->assertGreaterThanOrEqual(0, $low);
        $this->assertLessThanOrEqual(100, $high);
        // حضور قوي (نشاط + لقطة + تشابه) لازم يعطي درجة أعلى من الأدنى
        $this->assertGreaterThan($low, $high);
        $this->assertGreaterThanOrEqual($low + 30, $high);
    }

    public function testDiscoverCreatesProspectsWithoutPersonalData(): void
    {
        $c1 = $this->addCompetitor('rival-travel.com', 'Rival Travel', 85);
        $this->addSnapshot($c1, 'blog', 'https://rival-travel.com/guide');
        $c2 = $this->addCompetitor('another-agency.net', 'Another Agency', 40);
        $this->addSnapshot($c2, 'homepage', 'https://another-agency.net');

        $result = $this->service()->discoverForWebsite(self::USER_ID, self::WEBSITE_ID);

        $this->assertTrue($result['available'], 'الاكتشاف لازم يكون متاح مع منافسين متتبعين');
        $this->assertSame(2, $result['discovered']);
        $this->assertSame(0, $result['duplicates']);
        $this->assertSame(2, $result['drafts_generated']);

        $pdo = $this->db();
        $rows = $pdo->query("SELECT * FROM outreach_prospects WHERE user_id = " . self::USER_ID)->fetchAll(PDO::FETCH_ASSOC);
        $this->assertCount(2, $rows);

        $domains = array_column($rows, 'domain');
        $this->assertContains('rival-travel.com', $domains);
        $this->assertContains('another-agency.net', $domains);

        foreach ($rows as $row) {
            $this->assertSame('prospect', $row['status']);
            // الأمان: ممنوع أي بيانات تواصل شخصية من اكتشاف تلقائي
            $this->assertNull($row['contact_email']);
            $this->assertNull($row['contact_name']);
            $this->assertStringContainsString('relevance_score=', (string) $row['notes']);
            preg_match('/relevance_score=(\d+)/', (string) $row['notes'], $m);
            $this->assertNotEmpty($m, 'relevance_score لازم يكون مكتوب في notes');
            $score = (int) $m[1];
            $this->assertGreaterThanOrEqual(0, $score);
            $this->assertLessThanOrEqual(100, $score);
        }

        // مسودات (draft) محفوظة - مش أي حاجة اتبعتت
        $emails = $pdo->query("SELECT * FROM outreach_emails WHERE prospect_id IN
                                (SELECT id FROM outreach_prospects WHERE user_id = " . self::USER_ID . ")")->fetchAll(PDO::FETCH_ASSOC);
        $this->assertCount(2, $emails);
        foreach ($emails as $email) {
            $this->assertSame('draft', $email['status']);
        }
    }

    public function testDiscoverIsIdempotent(): void
    {
        $c1 = $this->addCompetitor('idem-rival.com', 'Idem Rival', 70);
        $this->addSnapshot($c1, 'homepage');

        $service = $this->service();
        $first = $service->discoverForWebsite(self::USER_ID, self::WEBSITE_ID);
        $this->assertSame(1, $first['discovered']);

        $second = $service->discoverForWebsite(self::USER_ID, self::WEBSITE_ID);
        $this->assertSame(0, $second['discovered'], 'إعادة الاكتشاف مينفعش تضيف مرشحين مكررين');
        $this->assertSame(1, $second['duplicates']);
    }

    public function testOwnDomainIsSkipped(): void
    {
        $this->addCompetitor('mytravel-owntest.com', 'Me Myself', 90);

        $result = $this->service()->discoverForWebsite(self::USER_ID, self::WEBSITE_ID);

        $this->assertTrue($result['available']);
        $this->assertSame(0, $result['discovered']);
        $this->assertSame(1, $result['skipped_own']);

        $pdo = $this->db();
        $count = $pdo->query("SELECT COUNT(*) FROM outreach_prospects WHERE user_id = " . self::USER_ID)->fetchColumn();
        $this->assertSame('0', (string) $count);
    }

    public function testLinkAcquiredDomainIsSkipped(): void
    {
        $this->addCompetitor('linked-rival.com', 'Linked Rival', 60);

        // دومين اتعمل منه رابط فعلًا قبل كده (لأي موقع للمستخدم)
        $pdo = $this->db();
        $stmt = $pdo->prepare("INSERT INTO outreach_prospects
                    (user_id, website_id, domain, status, notes)
                    VALUES (?, ?, 'linked-rival.com', 'link_acquired', 'رابط حقيقي سابق')");
        $stmt->execute([self::USER_ID, self::OTHER_WEBSITE_ID]);

        $result = $this->service()->discoverForWebsite(self::USER_ID, self::WEBSITE_ID);

        $this->assertTrue($result['available']);
        $this->assertSame(0, $result['discovered']);
        $this->assertSame(1, $result['already_linked']);
    }

    public function testInsufficientDataWhenNoCompetitors(): void
    {
        $result = $this->service()->discoverForWebsite(self::OTHER_USER_ID, self::OTHER_WEBSITE_ID);

        $this->assertFalse($result['available']);
        $this->assertStringContainsString('insufficient_data', (string) $result['reason']);
        $this->assertSame(0, $result['discovered']);
    }

    public function testWebsiteMustBeOwned(): void
    {
        // موقع ملك مستخدم تاني - مينفعش نكتشف ليه
        $result = $this->service()->discoverForWebsite(self::USER_ID, self::OTHER_WEBSITE_ID);
        $this->assertFalse($result['available']);
        $this->assertSame('website_not_found', $result['reason']);
    }

    public function testControllerDiscoverRequiresAuth(): void
    {
        $_SERVER['auth_user'] = null;
        unset($_SERVER['auth_user']);
        $_GET = [];

        $controller = new OutreachController();
        $response = $controller->discover();

        $this->assertFalse($response['success']);
        $this->assertSame(401, $response['code']);
    }

    public function testControllerDiscoverHappyPath(): void
    {
        $c1 = $this->addCompetitor('ctrl-rival.com', 'Ctrl Rival', 75);
        $this->addSnapshot($c1, 'blog');

        // مصادقة عبر $_SERVER['auth_user'] (نفس ما يقرأه Controller::loadAuthenticatedUser)
        $user = (new User())->find(self::USER_ID);
        if ($user === null) {
            $this->markTestSkipped('مستخدم الاختبار مش موجود');
        }
        $_SERVER['auth_user'] = $user->toArray();
        $_GET = ['website_id' => (string) self::WEBSITE_ID];

        $controller = new OutreachController();

        // حقن service بـ fake generator (منع أي استدعاء AI فعلي)
        $ref = new ReflectionProperty(OutreachController::class, 'discoveryService');
        $ref->setAccessible(true);
        $ref->setValue($controller, $this->service());

        $response = $controller->discover();

        $this->assertTrue($response['success'], 'discover لازم ينجح مع منافس متتبع: ' . ($response['error'] ?? ''));
        $this->assertTrue($response['data']['available']);
        $this->assertSame(1, $response['data']['discovered']);
        $this->assertSame(1, $response['data']['drafts_generated']);

        $_SERVER['auth_user'] = null;
        unset($_SERVER['auth_user']);
    }

    public function testRateLimitEnforcedByCiRateLimiter(): void
    {
        $key = 'user:' . self::USER_ID;
        $last = null;
        for ($i = 0; $i < 11; $i++) {
            $last = CiRateLimiter::hit('discovery_run', $key);
        }
        $this->assertFalse($last['allowed']);
        $this->assertSame(0, $last['remaining']);
        $this->assertGreaterThan(0, $last['retry_after']);
    }
}
