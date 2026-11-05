<?php

/**
 * حذف الشركات وجهات الاتصال في الـCRM: العزل بين الحسابات، فك الارتباط بدل المسح للصفقات،
 * مسح الـLeads المرتبطة بجهة الاتصال بعد تأكيد صريح، ومسح قيم الحقول المخصصة.
 * بيتخطى تلقائيًا لو DB أو جداول CRM مش متاحة.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/Core/Model.php';
require_once __DIR__ . '/../../app/Models/CrmCompany.php';
require_once __DIR__ . '/../../app/Models/CrmContact.php';
require_once __DIR__ . '/../../app/Models/ActivityLog.php';
require_once __DIR__ . '/../../app/Services/Crm/CrmPaginationHelper.php';
require_once __DIR__ . '/../../app/Services/Crm/CrmCompanyService.php';
require_once __DIR__ . '/../../app/Services/Crm/CrmContactService.php';

final class CrmDeleteServiceTest extends TestCase
{
    private const OWNER = 998500;
    private const OTHER = 998501;

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
                foreach ([$app . '/Config/app.php', $app . '/Config/database.php'] as $cfg) {
                    if (file_exists($cfg)) {
                        require_once $cfg;
                    }
                }
            }
            if (!class_exists('Database') && file_exists($app . '/Core/Database.php')) {
                require_once $app . '/Core/Database.php';
            }
            $ref = new ReflectionProperty(Database::class, 'connection');
            $ref->setAccessible(true);
            $conn = $ref->getValue(Database::getInstance());
            if (!$conn instanceof PDO) {
                return null;
            }
            foreach (['crm_companies', 'crm_contacts', 'crm_leads', 'crm_deals', 'crm_custom_field_values'] as $table) {
                if (!$conn->query("SHOW TABLES LIKE '{$table}'")->fetchAll()) {
                    return null;
                }
            }
            self::$pdo = $conn;
        } catch (\Throwable $e) {
            self::$pdo = null;
        }
        return self::$pdo;
    }

    protected function setUp(): void
    {
        $pdo = $this->db();
        if ($pdo === null) {
            $this->markTestSkipped('DB أو جداول CRM غير متاحة');
        }
        $this->cleanup();
        foreach ([self::OWNER, self::OTHER] as $id) {
            $pdo->exec("INSERT INTO users (id, email, password, company_name, created_at)
                        VALUES ({$id}, 'crm-del-{$id}@tourfecto.test', 'x', 'CRM delete test', NOW())
                        ON DUPLICATE KEY UPDATE email = email");
        }
    }

    protected function tearDown(): void
    {
        if (self::$pdo !== null) {
            $this->cleanup();
        }
    }

    private function cleanup(): void
    {
        $ids = self::OWNER . ',' . self::OTHER;
        $pdo = self::$pdo;
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        $pdo->exec("DELETE FROM crm_custom_field_values WHERE user_id IN ({$ids})");
        $pdo->exec("DELETE FROM crm_custom_fields WHERE user_id IN ({$ids})");
        $pdo->exec("DELETE FROM crm_deals WHERE owner_user_id IN ({$ids})");
        $pdo->exec("DELETE FROM crm_leads WHERE contact_id IN (SELECT id FROM crm_contacts WHERE user_id IN ({$ids}))");
        $pdo->exec("DELETE FROM crm_contacts WHERE user_id IN ({$ids})");
        $pdo->exec("DELETE FROM crm_companies WHERE user_id IN ({$ids})");
        $pdo->exec("DELETE FROM users WHERE id IN ({$ids})");
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    }

    private function company(int $owner = self::OWNER): int
    {
        self::$pdo->prepare("INSERT INTO crm_companies (user_id, name) VALUES (?, 'Acme')")->execute([$owner]);
        return (int) self::$pdo->lastInsertId();
    }

    private function contact(int $owner = self::OWNER, ?int $companyId = null): int
    {
        self::$pdo->prepare("INSERT INTO crm_contacts (user_id, company_id, name) VALUES (?, ?, 'Sara')")->execute([$owner, $companyId]);
        return (int) self::$pdo->lastInsertId();
    }

    private function lead(int $contactId): int
    {
        self::$pdo->prepare('INSERT INTO crm_leads (contact_id, owner_user_id) VALUES (?, ?)')->execute([$contactId, self::OWNER]);
        return (int) self::$pdo->lastInsertId();
    }

    private function deal(?int $contactId, ?int $companyId, int $owner = self::OWNER): int
    {
        self::$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        self::$pdo->prepare("INSERT INTO crm_deals (owner_user_id, contact_id, company_id, stage_id, title) VALUES (?, ?, ?, 1, 'd')")
            ->execute([$owner, $contactId, $companyId]);
        $id = (int) self::$pdo->lastInsertId(); // قبل أي statement تاني عشان ما يتصفّرش
        self::$pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        return $id;
    }

    private function rows(string $sql): int
    {
        return (int) self::$pdo->query($sql)->fetchColumn();
    }

    public function testDeleteCompanyUnlinksContactsAndDealsButKeepsThem(): void
    {
        $companyId = $this->company();
        $contactId = $this->contact(self::OWNER, $companyId);
        $dealId = $this->deal($contactId, $companyId);

        $r = (new CrmCompanyService())->delete(self::OWNER, $companyId);

        $this->assertSame(['contacts_unlinked' => 1, 'deals_unlinked' => 1], $r);
        $this->assertSame(0, $this->rows("SELECT COUNT(*) FROM crm_companies WHERE id = {$companyId}"));
        $this->assertSame(1, $this->rows("SELECT COUNT(*) FROM crm_contacts WHERE id = {$contactId} AND company_id IS NULL"));
        $this->assertSame(1, $this->rows("SELECT COUNT(*) FROM crm_deals WHERE id = {$dealId} AND company_id IS NULL"));
    }

    public function testDeleteCompanyRemovesItsCustomFieldValuesOnly(): void
    {
        $companyId = $this->company();
        $otherCompanyId = $this->company();
        self::$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        self::$pdo->exec("INSERT INTO crm_custom_fields (id, user_id, entity_type, field_key, label, field_type) VALUES (998600, " . self::OWNER . ", 'company', 'k', 'K', 'text')");
        foreach ([$companyId, $otherCompanyId] as $id) {
            self::$pdo->exec("INSERT INTO crm_custom_field_values (user_id, entity_type, entity_id, field_id, value) VALUES (" . self::OWNER . ", 'company', {$id}, 998600, 'v')");
        }
        self::$pdo->exec('SET FOREIGN_KEY_CHECKS=1');

        (new CrmCompanyService())->delete(self::OWNER, $companyId);

        $this->assertSame(0, $this->rows("SELECT COUNT(*) FROM crm_custom_field_values WHERE entity_id = {$companyId} AND entity_type = 'company'"));
        $this->assertSame(1, $this->rows("SELECT COUNT(*) FROM crm_custom_field_values WHERE entity_id = {$otherCompanyId} AND entity_type = 'company'"));
    }

    public function testOtherAccountCannotDeleteCompanyOrContact(): void
    {
        $companyId = $this->company();
        $contactId = $this->contact();

        foreach ([
            fn() => (new CrmCompanyService())->delete(self::OTHER, $companyId),
            fn() => (new CrmContactService())->delete(self::OTHER, $contactId, true),
        ] as $attack) {
            try {
                $attack();
                $this->fail('حساب تاني ما كانش المفروض يقدر يحذف');
            } catch (Exception $e) {
                $this->assertSame(404, $e->getCode());
            }
        }
        $this->assertSame(1, $this->rows("SELECT COUNT(*) FROM crm_companies WHERE id = {$companyId}"));
        $this->assertSame(1, $this->rows("SELECT COUNT(*) FROM crm_contacts WHERE id = {$contactId}"));
    }

    public function testDeleteContactWithoutDependentsNeedsNoConfirmation(): void
    {
        $contactId = $this->contact();

        $r = (new CrmContactService())->delete(self::OWNER, $contactId);

        $this->assertSame(['leads_deleted' => 0, 'deals_unlinked' => 0], $r);
        $this->assertSame(0, $this->rows("SELECT COUNT(*) FROM crm_contacts WHERE id = {$contactId}"));
    }

    public function testDeleteContactWithLeadsOrDealsIsRefusedWith409UntilConfirmed(): void
    {
        $contactId = $this->contact();
        $this->lead($contactId);
        $this->deal($contactId, null);
        $svc = new CrmContactService();

        try {
            $svc->delete(self::OWNER, $contactId);
            $this->fail('كان لازم يرفض الحذف بدون تأكيد');
        } catch (Exception $e) {
            $this->assertSame(409, $e->getCode());
            $this->assertStringContainsString('1 عميل محتمل', $e->getMessage());
            $this->assertStringContainsString('1 صفقة', $e->getMessage());
        }
        $this->assertSame(1, $this->rows("SELECT COUNT(*) FROM crm_contacts WHERE id = {$contactId}"));
        $this->assertSame(1, $this->rows("SELECT COUNT(*) FROM crm_leads WHERE contact_id = {$contactId}"));
    }

    public function testConfirmedDeleteRemovesLeadsAndKeepsDealsUnlinked(): void
    {
        $contactId = $this->contact();
        $this->lead($contactId);
        $dealId = $this->deal($contactId, null);

        $r = (new CrmContactService())->delete(self::OWNER, $contactId, true);

        $this->assertSame(['leads_deleted' => 1, 'deals_unlinked' => 1], $r);
        $this->assertSame(0, $this->rows("SELECT COUNT(*) FROM crm_contacts WHERE id = {$contactId}"));
        $this->assertSame(0, $this->rows("SELECT COUNT(*) FROM crm_leads WHERE contact_id = {$contactId}"));
        $this->assertSame(1, $this->rows("SELECT COUNT(*) FROM crm_deals WHERE id = {$dealId} AND contact_id IS NULL"));
    }
}
