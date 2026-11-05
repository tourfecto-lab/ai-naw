<?php

/**
 * CrmReportService: تقرير Win/Loss وأهداف المبيعات.
 * بيتأكد إن الأرقام محسوبة من الصفقات الفعلية فقط، وإن بيانات حساب ما بتظهرش في حساب تاني،
 * وإن الفترة المحددة بتقطع الصفقات خارجها. بيتخطى تلقائيًا لو DB أو جداول CRM مش متاحة.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/Core/Model.php';
require_once __DIR__ . '/../../app/Models/CrmSalesGoal.php';
require_once __DIR__ . '/../../app/Services/Crm/CrmReportService.php';

final class CrmReportServiceTest extends TestCase
{
    private const OWNER_A = 998100;
    private const OWNER_B = 998101;

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
            foreach (['crm_deals', 'crm_sales_goals'] as $table) {
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
        foreach ([self::OWNER_A, self::OWNER_B] as $id) {
            $pdo->exec("INSERT INTO users (id, email, password, company_name, created_at)
                        VALUES ({$id}, 'crm-report-{$id}@tourfecto.test', 'x', 'CRM report test', NOW())
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
        $pdo = self::$pdo;
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        $pdo->exec('DELETE FROM crm_deals WHERE owner_user_id IN (' . self::OWNER_A . ',' . self::OWNER_B . ')');
        $pdo->exec('DELETE FROM crm_sales_goals WHERE user_id IN (' . self::OWNER_A . ',' . self::OWNER_B . ')');
        $pdo->exec('DELETE FROM users WHERE id IN (' . self::OWNER_A . ',' . self::OWNER_B . ')');
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    }

    private function deal(int $owner, string $status, float $value, ?string $closedAt, ?string $reason = null): void
    {
        $pdo = self::$pdo;
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        $stmt = $pdo->prepare(
            'INSERT INTO crm_deals (owner_user_id, stage_id, title, value, status, lost_reason, closed_at)
             VALUES (?, 1, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$owner, 'deal-' . $status, $value, $status, $reason, $closedAt]);
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    }

    public function testWinLossCountsValuesAndReasonsForThePeriod(): void
    {
        $this->deal(self::OWNER_A, 'won', 1000, '2026-03-05 10:00:00');
        $this->deal(self::OWNER_A, 'won', 500, '2026-03-31 23:30:00');
        $this->deal(self::OWNER_A, 'lost', 300, '2026-03-10 09:00:00', 'السعر');
        $this->deal(self::OWNER_A, 'lost', 200, '2026-03-12 09:00:00', 'السعر');
        $this->deal(self::OWNER_A, 'lost', 100, '2026-03-15 09:00:00', 'اختفى');
        $this->deal(self::OWNER_A, 'lost', 50, '2026-03-16 09:00:00', '   ');

        $r = (new CrmReportService())->winLoss(self::OWNER_A, '2026-03-01', '2026-03-31');

        $this->assertSame(2, $r['won_deals']);
        $this->assertSame(4, $r['lost_deals']);
        $this->assertSame(6, $r['total_closed']);
        $this->assertSame(33.3, $r['win_rate']);
        $this->assertSame(1500.0, $r['won_value']);
        $this->assertSame(650.0, $r['lost_value']);
        $this->assertSame(850.0, $r['net_value']);
        $this->assertSame(750.0, $r['average_won_value']);
        // السبب الفاضي ما بيتعدّش
        $this->assertEqualsCanonicalizing(['السعر' => 2, 'اختفى' => 1], $r['loss_reasons']);
        $this->assertSame(2, $r['loss_reasons']['السعر']);
        $this->assertCount(2, $r['loss_reasons']);
    }

    public function testWinLossIgnoresOpenDealsOtherPeriodsAndOtherAccounts(): void
    {
        $this->deal(self::OWNER_A, 'open', 9999, null);
        $this->deal(self::OWNER_A, 'won', 400, '2026-02-28 23:59:59');   // قبل الفترة
        $this->deal(self::OWNER_A, 'won', 400, '2026-04-01 00:00:00');   // بعد الفترة
        $this->deal(self::OWNER_B, 'won', 777, '2026-03-10 10:00:00');   // حساب تاني
        $this->deal(self::OWNER_A, 'won', 100, '2026-03-10 10:00:00');

        $r = (new CrmReportService())->winLoss(self::OWNER_A, '2026-03-01', '2026-03-31');

        $this->assertSame(1, $r['total_closed']);
        $this->assertSame(100.0, $r['won_value']);
    }

    public function testWinLossReturnsNullRatesWhenThereIsNoData(): void
    {
        $r = (new CrmReportService())->winLoss(self::OWNER_A, '2026-03-01', '2026-03-31');

        $this->assertSame(0, $r['total_closed']);
        $this->assertNull($r['win_rate']);
        $this->assertNull($r['average_won_value']);
        $this->assertSame([], $r['loss_reasons']);
    }

    public function testSalesGoalProgressComesFromWonDealsOfThatMonth(): void
    {
        $svc = new CrmReportService();
        $svc->setGoal(self::OWNER_A, '2026-03', 2000);
        $this->deal(self::OWNER_A, 'won', 500, '2026-03-05 10:00:00');
        $this->deal(self::OWNER_A, 'won', 500, '2026-04-05 10:00:00');
        $this->deal(self::OWNER_A, 'lost', 900, '2026-03-06 10:00:00');

        $goals = $svc->salesGoals(self::OWNER_A);

        $this->assertCount(1, $goals);
        $this->assertSame('2026-03', $goals[0]['period']);
        $this->assertSame(500.0, $goals[0]['achieved_value']);
        $this->assertSame(25.0, $goals[0]['progress_percent']);
    }

    public function testSetGoalUpdatesTheSameMonthInsteadOfDuplicating(): void
    {
        $svc = new CrmReportService();
        $svc->setGoal(self::OWNER_A, '2026-05', 1000);
        $svc->setGoal(self::OWNER_A, '2026-05', 3000);

        $goals = $svc->salesGoals(self::OWNER_A);
        $this->assertCount(1, $goals);
        $this->assertSame(3000.0, $goals[0]['target_value']);
    }

    public function testSetGoalRejectsBadPeriodAndNegativeTarget(): void
    {
        $svc = new CrmReportService();
        foreach ([['2026-5', 100.0], ['May 2026', 100.0], ['2026-05', -1.0]] as [$period, $target]) {
            try {
                $svc->setGoal(self::OWNER_A, $period, $target);
                $this->fail("كان لازم يرفض {$period} / {$target}");
            } catch (Exception $e) {
                $this->assertSame(422, $e->getCode());
            }
        }
    }

    public function testDeleteGoalIsScopedToItsOwner(): void
    {
        $svc = new CrmReportService();
        $goal = $svc->setGoal(self::OWNER_A, '2026-06', 100);

        try {
            $svc->deleteGoal(self::OWNER_B, (int) $goal['id']);
            $this->fail('حساب تاني ما كانش المفروض يقدر يحذف الهدف');
        } catch (Exception $e) {
            $this->assertSame(404, $e->getCode());
        }
        $this->assertCount(1, $svc->salesGoals(self::OWNER_A));
        $this->assertTrue($svc->deleteGoal(self::OWNER_A, (int) $goal['id']));
    }
}
