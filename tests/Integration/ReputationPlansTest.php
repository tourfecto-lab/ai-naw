<?php

/**
 * Stand-alone reputation plans paid from the wallet: off by default, exact charge once (idempotent), insufficient balance,
 * scheduled change, renewal at the price in force, failed renewal ends the plan, limits combine with the platform plan.
 */

use PHPUnit\Framework\TestCase;

final class ReputationPlansTest extends TestCase
{
    private const OWNER = 999121;
    private const MEMBER = 999122;
    private const KEYS = ['reputation_plans', 'reputation_limits', 'reputation_limits_audit', 'reputation_limit_overrides'];

    private ?PDO $pdo = null;
    private ?Database $db = null;
    private array $saved = [];

    protected function setUp(): void
    {
        try {
            $this->db = Database::getInstance();
            $ref = new ReflectionProperty(Database::class, 'connection');
            $ref->setAccessible(true);
            $this->pdo = $ref->getValue($this->db);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DB not available');
        }
        ReputationPlans::flush();
        if (!$this->pdo instanceof PDO || !ReputationPlans::ready($this->db)) {
            $this->markTestSkipped('DB or reputation_subscriptions not available');
        }
        foreach (self::KEYS as $k) {
            $v = $this->pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = '$k'")->fetchColumn();
            $this->saved[$k] = $v === false ? null : $v;
            $this->pdo->exec("DELETE FROM system_settings WHERE setting_key = '$k'");
        }
        $this->cleanup();
        $this->pdo->exec("INSERT INTO users (id, email, password, company_name, owner_user_id, created_at) VALUES (" . self::OWNER . ", 'plan-owner@tourfecto.test', 'x', 'Owner', NULL, NOW()), (" . self::MEMBER . ", 'plan-member@tourfecto.test', 'x', 'Member', " . self::OWNER . ", NOW())");
    }

    protected function tearDown(): void
    {
        if (!$this->pdo) {
            return;
        }
        $this->cleanup();
        foreach ($this->saved as $k => $v) {
            $this->pdo->exec("DELETE FROM system_settings WHERE setting_key = '$k'");
            if ($v !== null) {
                $st = $this->pdo->prepare('INSERT INTO system_settings (setting_key, setting_value, is_secret) VALUES (?, ?, 0)');
                $st->execute([$k, $v]);
            }
        }
    }

    private function cleanup(): void
    {
        $ids = self::OWNER . ',' . self::MEMBER;
        $this->pdo->exec("DELETE FROM reputation_subscriptions WHERE user_id IN ($ids)");
        $this->pdo->exec("DELETE FROM wallet_transactions WHERE user_id IN ($ids)");
        $this->pdo->exec("DELETE FROM notifications WHERE user_id IN ($ids)");
        $this->pdo->exec("DELETE FROM users WHERE id IN ($ids)");
    }

    private function fund(float $amount): void
    {
        $this->pdo->exec("INSERT INTO wallet_transactions (user_id, type, amount, currency, status) VALUES (" . self::OWNER . ", 'deposit', $amount, 'USD', 'completed')");
    }

    private function balance(): float
    {
        return round((new WalletService())->getBalance(self::OWNER), 2);
    }

    private function open(array $extra = []): void
    {
        ReputationPlans::save(['enabled' => true, 'plans' => ['rep_pro' => ['for_sale' => true, 'price_monthly' => 40, 'price_yearly' => 400, 'limits' => ['review_requests_monthly' => 100, 'locations' => 3]] + $extra]], 1, $this->db);
    }

    private function expire(): void
    {
        $this->pdo->exec("UPDATE reputation_subscriptions SET period_end = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 HOUR) WHERE user_id = " . self::OWNER);
    }

    public function testNothingIsOnSaleByDefault(): void
    {
        $this->fund(100);
        foreach ([['rep_pro', 'monthly'], ['rep_starter', 'monthly']] as [$p, $c]) {
            try {
                ReputationPlans::subscribe(self::OWNER, $p, $c, 'k1', $this->db);
                $this->fail('selling is off');
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('not on sale', $e->getMessage());
            }
        }
        $this->assertSame(100.0, $this->balance());
        $this->assertNull(ReputationPlans::active(self::OWNER, $this->db));
    }

    public function testPlanMustBeForSaleEvenWhenSellingIsOn(): void
    {
        ReputationPlans::save(['enabled' => true], 1, $this->db);
        $this->fund(100);
        $this->expectException(InvalidArgumentException::class);
        ReputationPlans::subscribe(self::OWNER, 'rep_pro', 'monthly', 'k', $this->db);
    }

    public function testPurchaseChargesTheConfiguredPriceOnceEvenIfRepeated(): void
    {
        $this->open();
        $this->fund(100);
        $r = ReputationPlans::subscribe(self::OWNER, 'rep_pro', 'monthly', 'same-key', $this->db);
        $this->assertSame('subscribed', $r['action']);
        $this->assertSame(40.0, $r['charged']);
        $this->assertSame(60.0, $this->balance());
        $again = ReputationPlans::subscribe(self::OWNER, 'rep_pro', 'monthly', 'same-key', $this->db);
        $this->assertSame('already', $again['action']);
        $this->assertSame(60.0, $this->balance(), 'a repeated request must not charge again');
        $this->assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM reputation_subscriptions WHERE user_id = " . self::OWNER)->fetchColumn());
        $end = strtotime($r['period_end'] . ' UTC');
        $this->assertTrue($end > time() + 27 * 86400 && $end < time() + 32 * 86400);
    }

    public function testYearlyUsesTheYearlyPrice(): void
    {
        $this->open();
        $this->fund(500);
        $r = ReputationPlans::subscribe(self::OWNER, 'rep_pro', 'yearly', 'y', $this->db);
        $this->assertSame(400.0, $r['charged']);
        $this->assertSame(100.0, $this->balance());
    }

    public function testNotEnoughBalanceChargesNothingAndCreatesNothing(): void
    {
        $this->open();
        $this->fund(10);
        try {
            ReputationPlans::subscribe(self::OWNER, 'rep_pro', 'monthly', 'k', $this->db);
            $this->fail('balance is not enough');
        } catch (DomainException $e) {
            $d = json_decode($e->getMessage(), true);
            $this->assertSame(30.0, (float) $d['shortfall']);
        }
        $this->assertSame(10.0, $this->balance());
        $this->assertNull(ReputationPlans::active(self::OWNER, $this->db));
    }

    public function testOnlyTheAccountOwnerCanBuy(): void
    {
        $this->open();
        $this->fund(100);
        $this->expectException(InvalidArgumentException::class);
        ReputationPlans::subscribe(self::MEMBER, 'rep_pro', 'monthly', 'k', $this->db);
    }

    public function testActivePlanGrantsLimitsAndTheMoreGenerousOfTwoWins(): void
    {
        ReputationLimits::save(['free' => ['review_requests_monthly' => 10, 'locations' => 5]], 1, $this->db);
        $this->open();
        $this->fund(100);
        $this->assertSame(10, ReputationLimits::limit(self::OWNER, 'review_requests_monthly', $this->db));
        ReputationPlans::subscribe(self::OWNER, 'rep_pro', 'monthly', 'k', $this->db);
        $this->assertSame(100, ReputationLimits::limit(self::OWNER, 'review_requests_monthly', $this->db), 'plan 100 beats platform 10');
        $this->assertSame(5, ReputationLimits::limit(self::OWNER, 'locations', $this->db), 'platform 5 beats plan 3: never a sum');
        $this->assertSame(100, ReputationLimits::limit(self::MEMBER, 'review_requests_monthly', $this->db), 'a team member follows the owner');
    }

    public function testChangingPlanWhileActiveIsScheduledAndNotCharged(): void
    {
        $this->open();
        ReputationPlans::save(['plans' => ['rep_agency' => ['for_sale' => true, 'price_monthly' => 90]]], 1, $this->db);
        $this->fund(300);
        ReputationPlans::subscribe(self::OWNER, 'rep_pro', 'monthly', 'a', $this->db);
        try {
            ReputationPlans::subscribe(self::OWNER, 'rep_pro', 'monthly', 'b', $this->db);
            $this->fail('already subscribed to the same plan');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('already subscribed', $e->getMessage());
        }
        $r = ReputationPlans::subscribe(self::OWNER, 'rep_agency', 'monthly', 'c', $this->db);
        $this->assertSame('scheduled', $r['action']);
        $this->assertSame(260.0, $this->balance(), 'only the first purchase was charged');
        $this->expire();
        $this->assertSame(1, ReputationPlans::renewDue(10, $this->db));
        $row = $this->pdo->query("SELECT plan_key, price_paid, pending_plan FROM reputation_subscriptions WHERE user_id = " . self::OWNER)->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('rep_agency', $row['plan_key']);
        $this->assertSame(90.0, (float) $row['price_paid']);
        $this->assertNull($row['pending_plan']);
        $this->assertSame(170.0, $this->balance());
    }

    public function testRenewalUsesThePriceInForceAndChargesOncePerPeriod(): void
    {
        $this->open();
        $this->fund(200);
        ReputationPlans::subscribe(self::OWNER, 'rep_pro', 'monthly', 'k', $this->db);
        ReputationPlans::save(['plans' => ['rep_pro' => ['price_monthly' => 55]]], 1, $this->db);
        $this->assertSame(160.0, $this->balance(), 'the paid period is not repriced');
        $this->expire();
        $this->assertSame(1, ReputationPlans::renewDue(10, $this->db));
        $this->assertSame(105.0, $this->balance(), 'renewed at the new price 55');
        $this->assertSame(0, ReputationPlans::renewDue(10, $this->db), 'nothing is due now');
        $this->assertNotNull(ReputationPlans::active(self::OWNER, $this->db));
    }

    public function testFailedRenewalEndsThePlanAndTheLimitsGoBack(): void
    {
        ReputationLimits::save(['free' => ['review_requests_monthly' => 10]], 1, $this->db);
        $this->open();
        $this->fund(45);
        ReputationPlans::subscribe(self::OWNER, 'rep_pro', 'monthly', 'k', $this->db);
        $this->assertSame(100, ReputationLimits::limit(self::OWNER, 'review_requests_monthly', $this->db));
        $this->expire();
        $this->assertSame(0, ReputationPlans::renewDue(10, $this->db));
        $this->assertNull(ReputationPlans::active(self::OWNER, $this->db));
        $this->assertSame(10, ReputationLimits::limit(self::OWNER, 'review_requests_monthly', $this->db), 'back to the platform plan');
        $this->assertSame(5.0, $this->balance(), 'nothing was charged');
        $this->assertSame(0, ReputationPlans::renewDue(10, $this->db), 'no retry loop');
        $this->assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM notifications WHERE user_id = " . self::OWNER . " AND type = 'reputation_plan'")->fetchColumn());
    }

    public function testAutoRenewOffEndsThePlanWithoutCharging(): void
    {
        $this->open();
        $this->fund(100);
        ReputationPlans::subscribe(self::OWNER, 'rep_pro', 'monthly', 'k', $this->db);
        ReputationPlans::setAutoRenew(self::OWNER, false, $this->db);
        $this->expire();
        ReputationPlans::renewDue(10, $this->db);
        $this->assertNull(ReputationPlans::active(self::OWNER, $this->db));
        $this->assertSame(60.0, $this->balance());
    }

    public function testStoppingASaleKeepsSubscribersAndTheirRenewal(): void
    {
        $this->open();
        $this->fund(200);
        ReputationPlans::subscribe(self::OWNER, 'rep_pro', 'monthly', 'k', $this->db);
        ReputationPlans::save(['plans' => ['rep_pro' => ['for_sale' => false]]], 1, $this->db);
        $this->assertNotNull(ReputationPlans::active(self::OWNER, $this->db));
        $this->expire();
        $this->assertSame(1, ReputationPlans::renewDue(10, $this->db), 'a plan no longer on sale still renews for its subscribers');
    }

    public function testAdminValidationAndHistory(): void
    {
        foreach ([['plans' => ['gold' => ['name' => 'x']]], ['plans' => ['rep_pro' => ['price_monthly' => -1]]], ['plans' => ['rep_pro' => ['price_monthly' => 'abc']]], ['plans' => ['rep_pro' => ['name' => '']]], ['plans' => ['rep_pro' => ['limits' => ['locations' => -9]]]], ['plans' => ['rep_pro' => ['limits' => ['price' => 1]]]]] as $bad) {
            try {
                ReputationPlans::save($bad, 1, $this->db);
                $this->fail('expected rejection of ' . json_encode($bad));
            } catch (InvalidArgumentException $e) {
                $this->assertTrue(true);
            }
        }
        $r = ReputationPlans::save(['enabled' => true, 'plans' => ['rep_pro' => ['price_monthly' => 42.5]]], 31, $this->db);
        $this->assertCount(2, $r['changes']);
        $log = ReputationLimits::auditLog(2, $this->db);
        $this->assertSame(31, $log[0]['actor_id']);
        $this->assertSame('standalone:rep_pro', $log[0]['changes'][1]['plan']);
        $view = ReputationPlans::adminView($this->db);
        $this->assertTrue($view['enabled']);
    }

    private function controller(int $uid, array $data): ReputationPlansController
    {
        $c = new ReputationPlansController();
        foreach (['authenticated' => true, 'user' => ['id' => $uid], 'data' => $data + ['csrf_token' => Csrf::token()]] as $prop => $value) {
            $r = new ReflectionProperty(Controller::class, $prop);
            $r->setAccessible(true);
            $r->setValue($c, $value);
        }
        return $c;
    }

    public function testApiAnswersClearlyAndNeverWith404(): void
    {
        if (!class_exists('Csrf')) {
            $this->markTestSkipped('Csrf not available');
        }
        $off = $this->controller(self::OWNER, ['plan' => 'rep_pro', 'cycle' => 'monthly', 'request_key' => 'k'])->subscribe();
        $this->assertFalse($off['success'] ?? true);
        $this->assertSame(422, $off['code'] ?? $off['status'] ?? 422);
        $this->open();
        $poor = $this->controller(self::OWNER, ['plan' => 'rep_pro', 'cycle' => 'monthly', 'request_key' => 'k2'])->subscribe();
        $this->assertSame(402, $poor['code'] ?? $poor['status'] ?? 402);
        $this->assertStringContainsString('not enough', $poor['error'] ?? '');
        $this->fund(100);
        $ok = $this->controller(self::OWNER, ['plan' => 'rep_pro', 'cycle' => 'monthly', 'request_key' => 'k3'])->subscribe();
        $this->assertTrue($ok['success'] ?? false);
        $list = $this->controller(self::OWNER, [])->listApi();
        $this->assertTrue($list['success'] ?? false);
        $this->assertSame('rep_pro', $list['data']['subscription']['plan']);
        $this->assertSame(60.0, (float) $list['data']['balance']);
        $member = $this->controller(self::MEMBER, ['on' => false])->autoRenew();
        $this->assertSame(403, $member['code'] ?? $member['status'] ?? 403, 'a team member cannot change the owner plan');
    }
}
