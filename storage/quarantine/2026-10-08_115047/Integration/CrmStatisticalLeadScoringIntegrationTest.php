<?php

/**
 * Tourfecto - CRM Statistical Lead Scoring Integration Test (بند 6)
 * بيفحص الطبقة الإحصائية الشفافة فوق التقييم Rule-based:
 *   1) فاصل Wilson صحيح لقيم معلومة.
 *   2) sourceConversionStats تحسب معدل التحويل التجريبي لكل مصدر من
 *      سجل الحساب (قرارات نهائية فقط).
 *   3) scoreLead تحفظ conv_probability/score_confidence/signals additively.
 *   4) عينة أقل من الحد الأدنى → conv_probability = null + ثقة low + رسالة
 *      صريحة (لا اختراع رقم).
 *   5) عزل تينانت: lead لصاحب حساب تاني → 403، و lead مش موجود → 404.
 *
 * محتاج الميجريشن: 2026_08_28_000008_add_stat_lead_scoring.sql
 * بيتخطى تلقائيًا لو DB غير متاحة.
 * @version 1.0.0  @date 2026-08-28
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/Models/CrmLead.php';
require_once __DIR__ . '/../../app/Models/ActivityLog.php';
require_once __DIR__ . '/../../app/Services/Crm/CrmPermissionService.php';
require_once __DIR__ . '/../../app/Services/Crm/CrmStatisticalLeadScoringService.php';

final class CrmStatisticalLeadScoringIntegrationTest extends TestCase
{
    private const USER_ID = 999750;
    private const OTHER_USER_ID = 999751;

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
                ] as $cfg) {
                    if (file_exists($cfg)) {
                        require_once $cfg;
                    }
                }
            }
            if (!class_exists('Database') && file_exists($app . '/Core/Database.php')) {
                require_once $app . '/Core/Database.php';
            }
            if (!class_exists('Logger') && file_exists($app . '/Core/Logger.php')) {
                require_once $app . '/Core/Logger.php';
            }

            $db = Database::getInstance();
            $ref = new ReflectionProperty(Database::class, 'connection');
            $ref->setAccessible(true);
            $conn = $ref->getValue($db);
            if (!$conn instanceof PDO) {
                self::$pdo = null;
                return null;
            }

            foreach (['users', 'crm_contacts', 'crm_leads', 'crm_deals', 'crm_pipeline_stages'] as $table) {
                $found = $conn->query("SHOW TABLES LIKE '{$table}'")->fetchAll();
                if (empty($found)) {
                    self::$pdo = null;
                    return null;
                }
            }

            // التأكد أن أعمدة الطبقة الإحصائية موجودة (بعد الميجريشن 000008)
            $cols = $conn->query("SHOW COLUMNS FROM crm_leads LIKE 'conv_probability'")->fetchAll();
            if (empty($cols)) {
                self::$pdo = null;
                return null;
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
            $this->markTestSkipped('DB غير متاحة أو الميجريشن 000008 غير مُطبَّق - راجع تعليق أعلى الملف');
        }

        $this->cleanup();

        foreach ([self::USER_ID, self::OTHER_USER_ID] as $uid) {
            $pdo->exec("INSERT INTO users (id, email, password, company_name, created_at)
                        VALUES ($uid, 'stat-lead-" . $uid . "@tourfecto.test', 'x', 'Stat Travel', NOW())
                        ON DUPLICATE KEY UPDATE email = email");
        }
    }

    private function cleanup(): void
    {
        $pdo = $this->db();
        if ($pdo === null) {
            return;
        }
        $pdo->exec("DELETE FROM crm_deals WHERE owner_user_id IN (" . self::USER_ID . ", " . self::OTHER_USER_ID . ")");
        $pdo->exec("DELETE FROM crm_leads WHERE owner_user_id IN (" . self::USER_ID . ", " . self::OTHER_USER_ID . ")");
        $pdo->exec("DELETE FROM crm_contacts WHERE user_id IN (" . self::USER_ID . ", " . self::OTHER_USER_ID . ")");
    }

    private function addContact(int $userId, string $source): int
    {
        $pdo = $this->db();
        $stmt = $pdo->prepare("INSERT INTO crm_contacts (user_id, name, email, phone, source) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$userId, 'Contact ' . uniqid(), uniqid() . '@c.test', '0100', $source]);
        return (int) $pdo->lastInsertId();
    }

    private function addLead(int $userId, int $contactId, string $status): int
    {
        $pdo = $this->db();
        $stmt = $pdo->prepare("INSERT INTO crm_leads (contact_id, owner_user_id, status, score) VALUES (?, ?, ?, 0)");
        $stmt->execute([$contactId, $userId, $status]);
        return (int) $pdo->lastInsertId();
    }

    private function addDeal(int $userId, int $leadId, string $status): void
    {
        $pdo = $this->db();
        $stmt = $pdo->prepare("INSERT INTO crm_deals (owner_user_id, lead_id, stage_id, title, value, status) VALUES (?, ?, 1, ?, 100, ?)");
        $stmt->execute([$userId, $leadId, 'Deal ' . uniqid(), $status]);
    }

    private function service(): CrmStatisticalLeadScoringService
    {
        return new CrmStatisticalLeadScoringService();
    }

    // ================================================================
    // الاختبارات
    // ================================================================

    public function testWilsonIntervalIsCorrectForKnownValues(): void
    {
        $svc = $this->service();

        // 50 نجاحًا من 100 → فاصل (0.4038, 0.5962) تقريبًا عند 95%
        $iv = $svc->wilsonInterval(50, 100);
        $this->assertGreaterThan(0.40, $iv['lower']);
        $this->assertLessThan(0.41, $iv['lower']);
        $this->assertGreaterThan(0.59, $iv['upper']);
        $this->assertLessThan(0.60, $iv['upper']);

        // 0 نجاحًا → النهاية الدنيا 0 (Wilson لا ينهار عند 0)
        $zero = $svc->wilsonInterval(0, 20);
        $this->assertSame(0.0, $zero['lower']);
        $this->assertGreaterThan(0.0, $zero['upper']);

        // 20/20 → النهاية العليا 1
        $full = $svc->wilsonInterval(20, 20);
        $this->assertSame(1.0, $full['upper']);
        $this->assertLessThan(1.0, $full['lower']);
    }

    public function testSourceConversionStatsComputesRatesPerSource(): void
    {
        // مصدر website: 8 محوّلين من 10 قرارات نهائية
        for ($i = 0; $i < 10; $i++) {
            $c = $this->addContact(self::USER_ID, 'website');
            $this->addLead(self::USER_ID, $c, $i < 8 ? 'converted' : 'disqualified');
        }
        // مصدر referral: 1 محوّل من 3 قرارات (عينة غير كافية)
        for ($i = 0; $i < 3; $i++) {
            $c = $this->addContact(self::USER_ID, 'referral');
            $this->addLead(self::USER_ID, $c, $i < 1 ? 'converted' : 'disqualified');
        }
        // lead بدون قرار نهائي - لا يدخل في المقام
        $c = $this->addContact(self::USER_ID, 'website');
        $this->addLead(self::USER_ID, $c, 'new');

        $stats = $this->service()->sourceConversionStats(self::USER_ID);

        $this->assertSame('statistical', $stats['basis']);
        $this->assertSame(10, $stats['min_sample']);

        $this->assertSame(13, $stats['overall']['total']);
        $this->assertSame(9, $stats['overall']['converted']);
        $this->assertEqualsWithDelta(9 / 13, (float) $stats['overall']['rate'], 0.0001);

        $website = null;
        $referral = null;
        foreach ($stats['per_source'] as $row) {
            if ($row['source'] === 'website') {
                $website = $row;
            }
            if ($row['source'] === 'referral') {
                $referral = $row;
            }
        }
        $this->assertNotNull($website);
        $this->assertSame(10, $website['total']);
        $this->assertSame(8, $website['converted']);
        $this->assertSame(0.8, (float) $website['rate']);
        $this->assertTrue($website['reliable']);
        $this->assertSame('moderate', $website['confidence']);

        $this->assertNotNull($referral);
        $this->assertSame(3, $referral['total']);
        $this->assertFalse($referral['reliable']);
        $this->assertSame('low', $referral['confidence']);
    }

    public function testScoreLeadPersistsStatisticalLayer(): void
    {
        for ($i = 0; $i < 12; $i++) {
            $c = $this->addContact(self::USER_ID, 'whatsapp');
            $this->addLead(self::USER_ID, $c, $i < 6 ? 'converted' : 'disqualified');
        }
        $c = $this->addContact(self::USER_ID, 'whatsapp');
        $leadId = $this->addLead(self::USER_ID, $c, 'new');

        $lead = $this->service()->scoreLead($leadId, self::USER_ID);

        $this->assertSame(0.5, (float) $lead->getAttribute('conv_probability'));
        $this->assertSame('moderate', $lead->getAttribute('score_confidence'));
        $signals = json_decode((string) $lead->getAttribute('score_signals_json'), true);
        $this->assertIsArray($signals);
        $this->assertSame('whatsapp', $signals['source']);
        $this->assertSame(12, $signals['sample']);
        $this->assertTrue($signals['reliable']);
        // لا تُعدَّل حقول التقييم Rule-based القديمة
        $this->assertSame(0, (int) $lead->getAttribute('score'));
    }

    public function testInsufficientDataYieldsNullProbability(): void
    {
        $c = $this->addContact(self::USER_ID, 'coldcall');
        $leadId = $this->addLead(self::USER_ID, $c, 'new');

        $lead = $this->service()->scoreLead($leadId, self::USER_ID);

        $this->assertNull($lead->getAttribute('conv_probability'));
        $this->assertSame('low', $lead->getAttribute('score_confidence'));
        $signals = json_decode((string) $lead->getAttribute('score_signals_json'), true);
        $this->assertTrue($signals['insufficient']);
        $this->assertStringContainsString('بيانات غير كافية', $signals['label']);
    }

    public function testScoreLeadRespectsTenantIsolation(): void
    {
        $c = $this->addContact(self::USER_ID, 'website');
        $leadId = $this->addLead(self::USER_ID, $c, 'converted');

        try {
            $this->service()->scoreLead($leadId, self::OTHER_USER_ID);
            $this->fail('كان يجب رفض الوصول لـ lead لا يخصّ الحساب الآخر');
        } catch (Exception $e) {
            $this->assertSame(403, $e->getCode());
        }

        try {
            $this->service()->scoreLead(999999999, self::USER_ID);
            $this->fail('كان يجب إرجاع 404 لـ lead غير موجود');
        } catch (Exception $e) {
            $this->assertSame(404, $e->getCode());
        }
    }

    public function testStatsAreIsolatedPerTenant(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $c = $this->addContact(self::USER_ID, 'website');
            $this->addLead(self::USER_ID, $c, 'converted');
        }
        for ($i = 0; $i < 3; $i++) {
            $c = $this->addContact(self::OTHER_USER_ID, 'website');
            $this->addLead(self::OTHER_USER_ID, $c, 'converted');
        }

        $stats = $this->service()->sourceConversionStats(self::USER_ID);
        $this->assertSame(5, $stats['overall']['total']);
        $this->assertSame(5, $stats['overall']['converted']);
    }
}
