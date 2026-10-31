<?php

/**
 * Reputation limits per plan (admin controlled): unlimited by default, validation, audit trail, tier resolution,
 * monthly review request cap, location cap, feature switches, per-customer override, tenant isolation.
 */

use PHPUnit\Framework\TestCase;

final class ReputationLimitsTest extends TestCase
{
    private const UID = 999061;
    private const MEMBER = 999062;
    private const OTHER = 999063;
    private const WID = 999061;
    private const SETTING_KEYS = ['reputation_limits', 'reputation_limits_audit', 'reputation_limit_overrides'];

    private ?PDO $pdo = null;
    private ?Database $db = null;
    private int $planId = 0;
    private bool $planCreated = false;
    private array $savedSettings = [];

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
        if (!$this->pdo instanceof PDO) {
            $this->markTestSkipped('DB not available');
        }
        foreach (self::SETTING_KEYS as $k) {
            $r = $this->pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = '$k'")->fetchColumn();
            $this->savedSettings[$k] = $r === false ? null : $r;
            $this->pdo->exec("DELETE FROM system_settings WHERE setting_key = '$k'");
        }
        $this->cleanup();
        $this->pdo->exec("INSERT INTO users (id, email, password, company_name, owner_user_id, created_at) VALUES
            (" . self::UID . ", 'lim-owner@tourfecto.test', 'x', 'Owner', NULL, NOW()),
            (" . self::MEMBER . ", 'lim-member@tourfecto.test', 'x', 'Member', " . self::UID . ", NOW()),
            (" . self::OTHER . ", 'lim-other@tourfecto.test', 'x', 'Other', NULL, NOW())");
        $this->pdo->exec("INSERT INTO websites (id, user_id, main_url, company_name, industry, is_verified, created_at) VALUES (" . self::WID . ", " . self::UID . ", 'https://lim.test', 'T', 'tourism', 1, NOW())");
        $id = $this->pdo->query("SELECT id FROM subscription_plans WHERE plan_code = 'professional_monthly' LIMIT 1")->fetchColumn();
        if (!$id) {
            $this->pdo->exec("INSERT INTO subscription_plans (plan_code, name, price, currency, billing_cycle, ai_analysis_limit, ai_message_limit, review_auto_reply_limit, features_json, is_active, sort_order) VALUES ('professional_monthly', 'Pro', 10, 'USD', 'monthly', 10, 10, 50, '{}', 1, 999)");
            $id = $this->pdo->lastInsertId();
            $this->planCreated = true;
        }
        $this->planId = (int) $id;
        $this->pdo->exec("INSERT INTO subscriptions (user_id, plan_id, status, price, currency, current_period_start, current_period_end) VALUES (" . self::UID . ", {$this->planId}, 'active', 10, 'USD', NOW(), DATE_ADD(NOW(), INTERVAL 30 DAY))");
    }

    protected function tearDown(): void
    {
        if (!$this->pdo) {
            return;
        }
        $this->cleanup();
        if ($this->planCreated) {
            $this->pdo->exec("DELETE FROM subscription_plans WHERE id = {$this->planId}");
        }
        foreach (self::SETTING_KEYS as $k) {
            $this->pdo->exec("DELETE FROM system_settings WHERE setting_key = '$k'");
            if ($this->savedSettings[$k] !== null) {
                $st = $this->pdo->prepare("INSERT INTO system_settings (setting_key, setting_value, is_secret) VALUES (?, ?, 0)");
                $st->execute([$k, $this->savedSettings[$k]]);
            }
        }
    }

    private function cleanup(): void
    {
        $ids = self::UID . ',' . self::MEMBER . ',' . self::OTHER;
        $this->pdo->exec("DELETE FROM review_requests WHERE user_id IN ($ids)");
        $this->pdo->exec("DELETE FROM platform_connections WHERE user_id IN ($ids)");
        $this->pdo->exec("DELETE FROM billing_usage_reservations WHERE user_id IN ($ids)");
        $this->pdo->exec("DELETE FROM subscriptions WHERE user_id IN ($ids)");
        $this->pdo->exec("DELETE FROM websites WHERE id = " . self::WID);
        $this->pdo->exec("DELETE FROM users WHERE id IN ($ids)");
    }

    private function request(int $uid, string $status = 'sent', string $createdAt = 'NOW()'): void
    {
        $this->pdo->exec("INSERT INTO review_requests (user_id, website_id, guest_name, channel, service_end_date, delay_hours, review_link, destination_platform, status, scheduled_send_at, attempts, source, created_at)
            VALUES ($uid, " . self::WID . ", 'G', 'email', NOW(), 0, 'https://x.test', 'other', '$status', NOW(), 0, 'manual', $createdAt)");
    }

    public function testDefaultsAreUnlimitedSoNothingChangesForExistingCustomers(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->request(self::UID);
        }
        ReputationLimits::assertCanCreateRequest(self::UID, $this->db);
        ReputationLimits::assertCanConnectLocation(self::UID, 12345, $this->db);
        ReputationLimits::assertFeature(self::UID, 'team_assignment', $this->db);
        ReputationLimits::assertFeature(self::UID, 'csv_import', $this->db);
        $this->assertSame('professional', ReputationLimits::tierFor(self::UID, $this->db));
        $this->assertSame(-1, ReputationLimits::limit(self::UID, 'review_requests_monthly', $this->db));
    }

    public function testSaveValidatesAndRejectsBadInput(): void
    {
        foreach ([
            ['professional' => ['review_requests_monthly' => -5]],
            ['professional' => ['review_requests_monthly' => 'abc']],
            ['professional' => ['review_requests_monthly' => 1.5]],
            ['professional' => ['review_requests_monthly' => 99999999]],
            ['gold' => ['locations' => 1]],
            ['professional' => ['price' => 5]],
        ] as $bad) {
            try {
                ReputationLimits::save($bad, 1, $this->db);
                $this->fail('expected InvalidArgumentException for ' . json_encode($bad));
            } catch (InvalidArgumentException $e) {
                $this->assertTrue(true);
            }
        }
        $this->assertSame(-1, ReputationLimits::config($this->db)['professional']['review_requests_monthly']);
    }

    public function testMonthlyRequestCapBlocksAtTheLimitAndIgnoresFailedAndLastMonth(): void
    {
        ReputationLimits::save(['professional' => ['review_requests_monthly' => 2]], 1, $this->db);
        $this->request(self::UID, 'sent');
        $this->request(self::UID, 'failed');
        $this->request(self::UID, 'sent', "DATE_SUB(DATE_FORMAT(NOW(), '%Y-%m-01'), INTERVAL 1 DAY)");
        ReputationLimits::assertCanCreateRequest(self::UID, $this->db);
        $this->request(self::UID, 'scheduled');
        try {
            ReputationLimits::assertCanCreateRequest(self::UID, $this->db);
            $this->fail('expected the limit to block');
        } catch (ReputationLimitReached $e) {
            $this->assertSame('review_requests_monthly', $e->feature);
            $this->assertStringContainsString('2 review requests', $e->getMessage());
        }
    }

    public function testTeamMembersShareTheOwnersLimitAndOtherAccountsAreNotCounted(): void
    {
        ReputationLimits::save(['professional' => ['review_requests_monthly' => 1]], 1, $this->db);
        $this->request(self::UID);
        $this->assertSame(self::UID, (int) $this->pdo->query("SELECT owner_user_id FROM users WHERE id = " . self::MEMBER)->fetchColumn());
        try {
            ReputationLimits::assertCanCreateRequest(self::MEMBER, $this->db);
            $this->fail('member must hit the owner limit');
        } catch (ReputationLimitReached $e) {
            $this->assertTrue(true);
        }
        $this->request(self::OTHER);
        $this->assertSame(1, ReputationLimits::usage(self::UID, $this->db)['review_requests_monthly'], 'another account must not add to this one');
    }

    public function testZeroMeansNotAllowedAndFreeTierAppliesWithoutSubscription(): void
    {
        ReputationLimits::save(['free' => ['review_requests_monthly' => 0, 'csv_import' => false]], 1, $this->db);
        $this->assertSame('free', ReputationLimits::tierFor(self::OTHER, $this->db));
        try {
            ReputationLimits::assertCanCreateRequest(self::OTHER, $this->db);
            $this->fail('free tier with 0 must block');
        } catch (ReputationLimitReached $e) {
            $this->assertStringContainsString('Upgrade your plan', $e->getMessage());
        }
        try {
            ReputationLimits::assertFeature(self::OTHER, 'csv_import', $this->db);
            $this->fail('csv import is off for the free tier');
        } catch (ReputationLimitReached $e) {
            $this->assertSame('csv_import', $e->feature);
        }
        ReputationLimits::assertCanCreateRequest(self::UID, $this->db);
    }

    public function testLocationCapAllowsReconnectingAnExistingWebsite(): void
    {
        ReputationLimits::save(['professional' => ['locations' => 1]], 1, $this->db);
        $this->pdo->exec("INSERT INTO platform_connections (website_id, user_id, platform, status) VALUES (" . self::WID . ", " . self::UID . ", 'google_business', 'connected')");
        ReputationLimits::assertCanConnectLocation(self::UID, self::WID, $this->db);
        try {
            ReputationLimits::assertCanConnectLocation(self::UID, 424242, $this->db);
            $this->fail('a second location must be blocked');
        } catch (ReputationLimitReached $e) {
            $this->assertSame('locations', $e->feature);
        }
    }

    public function testPerCustomerOverrideBeatsThePlanSetting(): void
    {
        ReputationLimits::save(['professional' => ['review_requests_monthly' => 1]], 1, $this->db);
        $this->request(self::UID);
        if (!$this->pdo->query("SHOW COLUMNS FROM subscriptions LIKE 'feature_overrides'")->fetch()) {
            // production has this column (FeatureResolver reads it); the test migration chain does not create it
            $this->pdo->exec('ALTER TABLE subscriptions ADD COLUMN feature_overrides TEXT NULL');
        }
        $this->pdo->exec("UPDATE subscriptions SET feature_overrides = '{\"reputation_review_requests_monthly\": 50}' WHERE user_id = " . self::UID);
        ReputationLimits::assertCanCreateRequest(self::UID, $this->db);
        $this->assertSame(50, ReputationLimits::limit(self::UID, 'review_requests_monthly', $this->db));
    }

    public function testAuditTrailRecordsWhoChangedWhat(): void
    {
        $res = ReputationLimits::save(['starter' => ['locations' => 3, 'team_assignment' => false]], 77, $this->db);
        $this->assertCount(2, $res['changes']);
        $same = ReputationLimits::save(['starter' => ['locations' => 3]], 77, $this->db);
        $this->assertSame([], $same['changes'], 'saving the same value is not a change');
        $log = ReputationLimits::auditLog(10, $this->db);
        $this->assertCount(1, $log);
        $this->assertSame(77, $log[0]['actor_id']);
        $this->assertSame(-1, $log[0]['changes'][0]['from']);
    }

    public function testUsageReportListsAccountsWithTheirLimit(): void
    {
        ReputationLimits::save(['professional' => ['review_requests_monthly' => 5]], 1, $this->db);
        $this->request(self::UID);
        $this->request(self::UID);
        $rows = array_values(array_filter(ReputationLimits::usageReport(100, $this->db), fn ($r) => $r['user_id'] === self::UID));
        $this->assertCount(1, $rows);
        $this->assertSame(2, $rows[0]['requests']);
        $this->assertSame(5, $rows[0]['limit']);
        $this->assertSame('professional', $rows[0]['plan']);
    }

    public function testCreateRequestEntryPointEnforcesTheLimit(): void
    {
        $svc = new ReviewRequestService();
        ReputationLimits::save(['professional' => ['review_requests_monthly' => 0]], 1, $this->db);
        try {
            $svc->createRequest(self::UID, self::WID, 'Guest', null, date('Y-m-d'), 'email', 'guest@example.com');
            $this->fail('expected the plan limit');
        } catch (ReputationLimitReached $e) {
            $this->assertSame('review_requests_monthly', $e->feature);
        }
        ReputationLimits::save(['professional' => ['review_requests_monthly' => -1]], 1, $this->db);
        try {
            $svc->createRequest(self::UID, self::WID, 'Guest', null, date('Y-m-d'), 'email', 'guest@example.com');
        } catch (ReputationLimitReached $e) {
            $this->fail('unlimited must not raise the plan limit');
        } catch (Throwable $e) {
            $this->assertTrue(true, 'any other failure (channel not configured in the test DB) is fine');
        }
    }

    public function testSummaryGivesUsedAndLimitForTheCustomerPage(): void
    {
        ReputationLimits::save(['professional' => ['review_requests_monthly' => 10]], 1, $this->db);
        $this->request(self::UID);
        $this->request(self::UID);
        $sum = ReputationLimits::summary(self::UID, $this->db);
        $this->assertSame('professional', $sum['plan']);
        $this->assertSame(['used' => 2, 'limit' => 10], $sum['review_requests_monthly']);
        $this->assertSame(['used' => 0, 'limit' => 0], $sum['sms_requests_monthly'], 'SMS off by default');
        $this->assertSame(-1, $sum['locations']['limit']);
    }

    public function testAlertsAtEightyAndHundredPercentOncePerMonth(): void
    {
        ReputationLimits::save(['professional' => ['review_requests_monthly' => 5]], 1, $this->db);
        $keys = "setting_key LIKE 'rep_limit_alert_" . self::UID . "_%'";
        $this->pdo->exec("DELETE FROM system_settings WHERE $keys");
        try {
            for ($i = 0; $i < 3; $i++) {
                $this->request(self::UID);
            }
            $this->assertSame([], ReputationLimits::maybeAlert(self::UID, $this->db), '60% is below the first step');
            $this->request(self::UID);
            $this->assertSame(['review_requests_monthly:80'], ReputationLimits::maybeAlert(self::UID, $this->db));
            $this->assertSame([], ReputationLimits::maybeAlert(self::UID, $this->db), 'the same step is not repeated');
            $this->request(self::UID);
            $this->assertSame(['review_requests_monthly:100'], ReputationLimits::maybeAlert(self::UID, $this->db));
            $this->assertSame([], ReputationLimits::maybeAlert(self::UID, $this->db));
            $this->assertSame(2, (int) $this->pdo->query("SELECT COUNT(*) FROM notifications WHERE user_id = " . self::UID . " AND type = 'reputation_limit'")->fetchColumn());
        } finally {
            $this->pdo->exec("DELETE FROM system_settings WHERE $keys");
            $this->pdo->exec("DELETE FROM notifications WHERE user_id = " . self::UID);
        }
    }

    public function testNoAlertWhenUnlimitedOrNotAllowed(): void
    {
        $this->request(self::UID);
        $this->assertSame([], ReputationLimits::maybeAlert(self::UID, $this->db), 'unlimited');
        $this->assertSame([], ReputationLimits::maybeAlert(self::OTHER, $this->db), 'SMS is 0 (not allowed): nothing to warn about');
    }

    public function testUsageReportShowsTheMonthsRealAiCostPerAccount(): void
    {
        $this->request(self::UID);
        $ids = [];
        foreach ([['review_reply', 0.003], ['sentiment', 0.001], ['seo_audit', 9.0]] as [$feature, $cost]) {
            $this->pdo->exec("INSERT INTO api_usage_logs (user_id, api_type, feature, model, tokens_used, cost_in_usd) VALUES (" . self::UID . ", 'gemini', '$feature', 'gemini-3.8-flash', 100, $cost)");
            $ids[] = $this->pdo->lastInsertId();
        }
        try {
            $rows = array_values(array_filter(ReputationLimits::usageReport(100, $this->db), fn ($r) => $r['user_id'] === self::UID));
            $this->assertSame(2, $rows[0]['ai_calls'], 'only reputation features count, not other services');
            $this->assertEquals(0.004, $rows[0]['ai_cost']);
        } finally {
            $this->pdo->exec('DELETE FROM api_usage_logs WHERE id IN (' . implode(',', $ids) . ')');
        }
    }

    public function testUsageReportIncludesAnAccountThatOnlyUsesAiReplies(): void
    {
        $this->pdo->exec("INSERT INTO api_usage_logs (user_id, api_type, feature, model, tokens_used, cost_in_usd) VALUES (" . self::OTHER . ", 'gemini', 'review_reply', 'gemini-3.8-flash', 100, 0.0125)");
        $id = $this->pdo->lastInsertId();
        try {
            $rows = array_values(array_filter(ReputationLimits::usageReport(100, $this->db), fn ($r) => $r['user_id'] === self::OTHER));
            $this->assertCount(1, $rows, 'no review request was sent, but its AI cost must still be reported');
            $this->assertSame(0, $rows[0]['requests']);
            $this->assertSame(1, $rows[0]['ai_calls']);
            $this->assertEquals(0.0125, $rows[0]['ai_cost']);
        } finally {
            $this->pdo->exec("DELETE FROM api_usage_logs WHERE id = $id");
        }
    }

    public function testCustomerExceptionBeatsThePlanInBothDirectionsAndCanBeRemoved(): void
    {
        ReputationLimits::save(['professional' => ['review_requests_monthly' => 2, 'team_assignment' => false]], 1, $this->db);
        $this->request(self::UID);
        $this->request(self::UID);
        $this->request(self::UID);
        $r = ReputationLimits::saveOverride(self::UID, ['review_requests_monthly' => 50, 'team_assignment' => true], 9, $this->db);
        $this->assertCount(2, $r['changes']);
        ReputationLimits::assertCanCreateRequest(self::UID, $this->db); // 3 used, plan says 2, exception says 50
        ReputationLimits::assertFeature(self::UID, 'team_assignment', $this->db);
        ReputationLimits::assertCanCreateRequest(self::MEMBER, $this->db); // the member follows the owner's exception
        ReputationLimits::saveOverride(self::UID, ['review_requests_monthly' => 1], 9, $this->db); // restriction below the plan
        try {
            ReputationLimits::assertCanCreateRequest(self::UID, $this->db);
            $this->fail('the restriction must apply');
        } catch (ReputationLimitReached $e) {
            $this->assertStringContainsString('limit of 1', $e->getMessage());
        }
        ReputationLimits::saveOverride(self::UID, ['review_requests_monthly' => null, 'team_assignment' => null], 9, $this->db);
        $this->assertSame([], ReputationLimits::overrides($this->db));
        $this->assertSame(2, ReputationLimits::limit(self::UID, 'review_requests_monthly', $this->db), 'back to the plan');
        $this->assertFalse(ReputationLimits::limit(self::UID, 'team_assignment', $this->db));
    }

    public function testCustomerExceptionValidationAndAudit(): void
    {
        foreach ([['review_requests_monthly' => -3], ['review_requests_monthly' => 'x'], ['price' => 1]] as $bad) {
            try {
                ReputationLimits::saveOverride(self::UID, $bad, 9, $this->db);
                $this->fail('expected rejection of ' . json_encode($bad));
            } catch (InvalidArgumentException $e) {
                $this->assertTrue(true);
            }
        }
        try {
            ReputationLimits::saveOverride(self::MEMBER, ['locations' => 5], 9, $this->db);
            $this->fail('a team member is not an account');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('team member', $e->getMessage());
        }
        try {
            ReputationLimits::saveOverride(424242, ['locations' => 5], 9, $this->db);
            $this->fail('unknown customer');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('not found', $e->getMessage());
        }
        ReputationLimits::saveOverride(self::UID, ['locations' => 5], 42, $this->db);
        $log = ReputationLimits::auditLog(3, $this->db);
        $this->assertSame(42, $log[0]['actor_id']);
        $this->assertSame('customer:lim-owner@tourfecto.test', $log[0]['changes'][0]['plan']);
        $this->assertSame('plan', $log[0]['changes'][0]['from']);
        $view = ReputationLimits::overridesView($this->db);
        $this->assertSame('lim-owner@tourfecto.test', $view[0]['email']);
        $this->assertSame(['locations' => 5], $view[0]['values']);
    }
}
