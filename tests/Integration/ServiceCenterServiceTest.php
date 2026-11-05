<?php

/**
 * ServiceCenterService: نطاق الصلاحيات (مالك/أدمن/موظف)، وتسجيل شروط القبول بدليل مكتوب.
 * بيتخطى تلقائيًا لو DB أو الجداول مش متاحة.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/Services/ServiceCenter/ServiceCenterCatalog.php';
require_once __DIR__ . '/../../app/Services/ServiceCenter/ServiceCenterService.php';

final class ServiceCenterServiceTest extends TestCase
{
    private const OWNER = 998700;
    private const MANAGER = 998701;
    private const MEMBER = 998702;
    private const OTHER_OWNER = 998703;

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
            if (!$conn instanceof PDO || !$conn->query("SHOW TABLES LIKE 'service_center_checks'")->fetchAll()) {
                return null;
            }
            $cols = array_column($conn->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_ASSOC), 'Field');
            if (!in_array('owner_user_id', $cols, true) || !in_array('workspace_role', $cols, true)) {
                return null;
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
            $this->markTestSkipped('DB أو جداول مركز الخدمات غير متاحة');
        }
        $this->cleanup();
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach ([
            [self::OWNER, 'NULL', 'NULL'],
            [self::MANAGER, self::OWNER, "'manager'"],
            [self::MEMBER, self::OWNER, "'sales'"],
            [self::OTHER_OWNER, 'NULL', 'NULL'],
        ] as [$id, $owner, $role]) {
            $pdo->exec("INSERT INTO users (id, email, password, company_name, owner_user_id, workspace_role, created_at)
                        VALUES ({$id}, 'svc-{$id}@tourfecto.test', 'x', 'SC test', {$owner}, {$role}, NOW())
                        ON DUPLICATE KEY UPDATE email = email");
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    }

    protected function tearDown(): void
    {
        if (self::$pdo !== null) {
            $this->cleanup();
        }
    }

    private function cleanup(): void
    {
        $ids = implode(',', [self::OWNER, self::MANAGER, self::MEMBER, self::OTHER_OWNER]);
        self::$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        self::$pdo->exec("DELETE FROM service_center_checks WHERE user_id IN ({$ids})");
        self::$pdo->exec("DELETE FROM users WHERE id IN ({$ids})");
        self::$pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    }

    private function svc(): ServiceCenterService
    {
        return new ServiceCenterService(self::$pdo);
    }

    public function testOwnerScopeCanManage(): void
    {
        $scope = $this->svc()->scope(self::OWNER);

        $this->assertSame(self::OWNER, $scope['owner_id']);
        $this->assertSame('owner', $scope['role']);
        $this->assertTrue($scope['can_manage']);
    }

    public function testTeamMembersWorkInTheOwnersAccount(): void
    {
        $manager = $this->svc()->scope(self::MANAGER);
        $member = $this->svc()->scope(self::MEMBER);

        $this->assertSame(self::OWNER, $manager['owner_id']);
        $this->assertTrue($manager['can_manage']);
        $this->assertSame(self::OWNER, $member['owner_id']);
        $this->assertFalse($member['can_manage']);
    }

    public function testWriteOrFinanceNeedsAManagerRole(): void
    {
        foreach ([[true, false], [false, true]] as [$write, $finance]) {
            try {
                $this->svc()->scope(self::MEMBER, $write, $finance);
                $this->fail('الموظف العادي ما كانش المفروض يعدّي');
            } catch (RuntimeException $e) {
                $this->assertSame(403, $e->getCode());
            }
        }
        $this->assertTrue($this->svc()->scope(self::MANAGER, true, true)['can_manage']);
    }

    public function testUnknownUserIsUnauthorized(): void
    {
        try {
            $this->svc()->scope(999999999);
            $this->fail('مستخدم غير موجود');
        } catch (RuntimeException $e) {
            $this->assertSame(401, $e->getCode());
        }
    }

    public function testRecordCheckStoresEvidenceUnderTheOwnersAccount(): void
    {
        $this->svc()->recordCheck(self::MANAGER, 'crm', 'page', 'Opened /crm and created a lead as manager');

        $row = self::$pdo->query("SELECT user_id, checked_by, evidence FROM service_center_checks WHERE service_key = 'crm' AND check_key = 'page'")->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(self::OWNER, (int) $row['user_id']);
        $this->assertSame(self::MANAGER, (int) $row['checked_by']);
    }

    public function testRecordCheckRejectsBadInput(): void
    {
        $bad = [
            ['crm', 'not_a_check', 'Opened the page and verified it works'],
            ['crm', 'page', 'too short'],
            ['crm', 'page', str_repeat('x', 501)],
        ];
        foreach ($bad as [$service, $check, $evidence]) {
            try {
                $this->svc()->recordCheck(self::OWNER, $service, $check, $evidence);
                $this->fail('كان لازم يرفض: ' . $check . ' / ' . strlen($evidence));
            } catch (InvalidArgumentException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
        $this->expectException(InvalidArgumentException::class);
        $this->svc()->recordCheck(self::OWNER, 'unknown-service', 'page', 'Opened the page and verified it works');
    }

    public function testMemberCannotRecordChecks(): void
    {
        try {
            $this->svc()->recordCheck(self::MEMBER, 'crm', 'page', 'Opened the page and verified it works');
            $this->fail('الموظف العادي ما يسجّلش شروط قبول');
        } catch (RuntimeException $e) {
            $this->assertSame(403, $e->getCode());
        }
    }

    public function testRecordCheckUpdatesTheSameCheckAndRemoveDeletesIt(): void
    {
        $this->svc()->recordCheck(self::OWNER, 'crm', 'page', 'First evidence for the page check');
        $this->svc()->recordCheck(self::OWNER, 'crm', 'page', 'Second evidence for the page check');

        $rows = self::$pdo->query('SELECT evidence FROM service_center_checks WHERE user_id = ' . self::OWNER)->fetchAll(PDO::FETCH_COLUMN);
        $this->assertSame(['Second evidence for the page check'], $rows);

        $this->assertSame(['removed' => true], $this->svc()->recordCheck(self::OWNER, 'crm', 'page', '', true));
        $this->assertSame(0, (int) self::$pdo->query('SELECT COUNT(*) FROM service_center_checks WHERE user_id = ' . self::OWNER)->fetchColumn());
    }

    public function testChecksOfOneAccountAreInvisibleToAnother(): void
    {
        $this->svc()->recordCheck(self::OWNER, 'crm', 'page', 'Verified by the first account only');

        $other = self::$pdo->query('SELECT COUNT(*) FROM service_center_checks WHERE user_id = ' . self::OTHER_OWNER)->fetchColumn();
        $this->assertSame(0, (int) $other);

        // حذف حساب تاني ما يلمسش
        $this->svc()->recordCheck(self::OTHER_OWNER, 'crm', 'page', '', true);
        $this->assertSame(1, (int) self::$pdo->query('SELECT COUNT(*) FROM service_center_checks WHERE user_id = ' . self::OWNER)->fetchColumn());
    }
}
