<?php

/**
 * TOURFECTO AI CEO (المرحلة 6): رحلة العميل والعملاء الواقفين، مشاكل الدفع، التجديدات اللي هتتلغي، رسائل الدعم،
 * وإن التقرير كله بيشتغل على قاعدة البيانات الحقيقية (كل قسم بيرجع بيانات مش null).
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/Services/AiCeo/CustomerSuccess.php';
require_once __DIR__ . '/../../app/Services/AiCeo/PlatformDecisions.php';

final class CustomerSuccessIntegrationTest extends TestCase
{
    private const NEW_USER = 999880;
    private const PAYING = 999881;
    private ?Database $db = null;

    protected function setUp(): void
    {
        try {
            $app = dirname(__DIR__, 2) . '/app';
            if (!defined('APP_ENV')) foreach (['app.php', 'database.php', 'encryption.php'] as $cfg) if (file_exists($app . '/Config/' . $cfg)) require_once $app . '/Config/' . $cfg;
            if (!class_exists('Database')) require_once $app . '/Core/Database.php';
            foreach (['Admin/SaasMetricsService', 'Admin/CustomerHealthService'] as $c) if (!class_exists(basename($c))) require_once $app . '/Services/' . $c . '.php';
            $this->db = Database::getInstance();
        } catch (Throwable $e) {
            $this->markTestSkipped('DB غير متاحة: ' . $e->getMessage());
        }
        $this->clean();
    }

    protected function tearDown(): void
    {
        if ($this->db) $this->clean();
    }

    private function clean(): void
    {
        $ids = [self::NEW_USER, self::PAYING];
        $this->db->query('DELETE FROM payment_transactions WHERE user_id IN (?, ?)', $ids);
        $this->db->query('DELETE FROM contact_submissions WHERE email LIKE ?', ['%@cs-phpunit.test']);
        $this->db->query('DELETE FROM subscriptions WHERE user_id IN (?, ?)', $ids);
        $this->db->query('DELETE FROM users WHERE id IN (?, ?)', $ids);
        $this->db->query("DELETE FROM system_settings WHERE setting_key LIKE 'cs\\_%'");
    }

    private function seed(): void
    {
        $this->db->query("INSERT INTO users (id, email, password, company_name, role, created_at) VALUES (?, 'new@cs-phpunit.test', 'x', 'CS New Co', 'user', NOW() - INTERVAL 5 DAY), (?, 'paying@cs-phpunit.test', 'x', 'CS Paying Co', 'user', NOW() - INTERVAL 40 DAY)", [self::NEW_USER, self::PAYING]);
        $this->db->query("INSERT INTO subscriptions (user_id, plan_name, plan_type, status, price, currency, start_date, created_at, payment_gateway, cancel_at_period_end, current_period_end)
            VALUES (?, 'starter', 'monthly', 'active', 49.00, 'USD', NOW() - INTERVAL 20 DAY, NOW() - INTERVAL 20 DAY, 'stripe', 1, NOW() + INTERVAL 10 DAY)", [self::PAYING]);
        foreach (['failed', 'failed', 'succeeded'] as $st) {
            $this->db->query("INSERT INTO payment_transactions (internal_transaction_id, user_id, amount, currency, payment_method, gateway, status) VALUES (?, ?, 49, 'USD', 'card', 'stripe', ?)", ['CS-' . bin2hex(random_bytes(8)), self::PAYING, $st]);
        }
        foreach (['WhatsApp is not connecting', 'واتساب مش شغال', 'whatsapp error again', 'thanks!'] as $i => $msg) {
            $this->db->query("INSERT INTO contact_submissions (name, email, message, user_id, status, created_at) VALUES ('CS', ?, ?, ?, ?, NOW() - INTERVAL ? DAY)", ["m{$i}@cs-phpunit.test", $msg, self::PAYING, $i === 3 ? 'replied' : 'new', 4 - $i]);
        }
    }

    public function testReportFindsTheProblems(): void
    {
        $cs = new CustomerSuccess($this->db);
        $before = $cs->report();
        $this->seed();
        $r = $cs->report();
        foreach (['funnel', 'payments', 'renewals', 'cancellations', 'support', 'opportunities', 'services', 'forecast'] as $k) $this->assertNotNull($r[$k], $k . ' should load on the real schema');

        $this->assertSame($before['funnel']['stages']['signed_up'] + 2, $r['funnel']['stages']['signed_up']);
        $stuck = array_column($r['funnel']['stuck'], 'next_step', 'user_id');
        $this->assertSame('website', $stuck[self::NEW_USER] ?? null, 'new customer without a website is stuck at step 1');
        $this->assertSame(($before['payments']['by_status']['failed']['count'] ?? 0) + 2, $r['payments']['by_status']['failed']['count']);
        $this->assertSame($before['renewals']['cancelling'] + 1, $r['renewals']['cancelling']);
        $this->assertSame($before['support']['unanswered'] + 3, $r['support']['unanswered']);
        $this->assertContains('whatsapp', $r['support']['recurring']);
        $this->assertSame(null, $r['support']['cost_usd']);

        $set = $cs->saveSettings(['support_minutes' => '10', 'support_cost_hour' => '12']);
        $this->assertSame(10.0, $set['support_minutes']);
        $this->assertSame(true, $cs->support(90)['cost_usd'] > 0);
        try { $cs->saveSettings(['support_minutes' => 'abc']); $this->fail('expected invalid'); } catch (InvalidArgumentException $e) { $this->assertSame(1, 1); }

        $snap = ['success' => ['stuck_onboarding' => 1, 'biggest_drop' => null, 'payments_failed' => 2, 'payments_stuck' => 0, 'renewals_cancelling' => 1, 'mrr_cancelling' => 49,
            'support_unanswered' => 3, 'support_oldest' => null, 'recurring_themes' => ['whatsapp'], 'upsell' => 0, 'cross_sell' => 0]];
        $keys = array_column(PlatformDecisions::decide($snap), 'key');
        foreach (['cs_onboarding_stuck', 'cs_payment_problems', 'cs_renewals_cancelling', 'cs_support_unanswered', 'cs_recurring_complaints'] as $k) $this->assertContains($k, $keys);
        $this->assertNotContains('cs_upsell_ready', $keys);
    }
}
