<?php

/**
 * Tourfecto - Wallet Card Top-Up Integration Test
 *
 * بيفحص مسار شحن المحفظة بالبطاقة (Stripe wallet_topup):
 *   1) applyCardTopUp() بيضيف الرصيد فورًا وبيقفل حالة المعاملة completed.
 *   2) نفس مرجع البوابة (gateway reference) مايعملش إيداع مكرر (idempotent).
 *   3) المبلغ سالب أو صفر بيتُرفض.
 *   4) بعد الشحن، الاشتراك من الرصيد (subscribeWithBalance) بيشتغل فعليًا
 *      لو الرصيد كافي.
 *
 * بيتخطى تلقائيًا لو قاعدة البيانات غير متاحة.
 *
 * @version 1.0.0
 * @date 2026-08-24
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/Services/Subscription/BillingRules.php';

final class WalletCardTopUpIntegrationTest extends TestCase
{
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
            if (!class_exists('ActivityLog') && file_exists($app . '/Models/ActivityLog.php')) {
                require_once $app . '/Models/ActivityLog.php';
            }
            if (!class_exists('Notification') && file_exists($app . '/Models/Notification.php')) {
                require_once $app . '/Models/Notification.php';
            }

            $db = Database::getInstance();
            $ref = new ReflectionProperty(Database::class, 'connection');
            $ref->setAccessible(true);
            $conn = $ref->getValue($db);
            if (!$conn instanceof PDO) {
                throw new RuntimeException('no active PDO connection');
            }
            self::$pdo = $conn;
        } catch (Throwable $e) {
            self::$pdo = null;
        }
        return self::$pdo;
    }

    private function requireDb(): PDO
    {
        $pdo = $this->db();
        if ($pdo === null) {
            $this->markTestSkipped('MySQL غير متاح في هذه البيئة - شغّل الاختبار على سيرفر بقاعدة بيانات حقيقية.');
        }
        return $pdo;
    }

    private function loadWalletService(): void
    {
        $svc = dirname(__DIR__, 2) . '/app/Services/Subscription/WalletService.php';
        if (!class_exists('WalletService') && file_exists($svc)) {
            require_once $svc;
        }
        if (!class_exists('WalletService')) {
            $this->markTestSkipped('WalletService غير محمّلة في هذه البيئة.');
        }
    }

    /**
     * إنشاء مستخدم حقيقي مؤقت في جدول users (مطلوب لأن subscriptions
     * عنده FOREIGN KEY على users.id - مش ممكن نستخدم ID وهمي).
     * @return array [user_id, email] ليتسنى تنظيف البيانات في النهاية
     */
    private function createTestUser(PDO $pdo): array
    {
        $email = 'wallet_test_' . bin2hex(random_bytes(6)) . '@tourfecto.test';
        $pdo->prepare(
            "INSERT INTO users (company_name, email, password_hash, status, created_at, updated_at)
             VALUES ('Wallet Test', ?, 'not-used', 'active', NOW(), NOW())"
        )->execute([$email]);
        return [(int) $pdo->lastInsertId(), $email];
    }

    private function deleteTestUser(PDO $pdo, int $userId): void
    {
        $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$userId]);
    }

    public function testCardTopUpCreditsBalanceImmediately(): void
    {
        $pdo = $this->requireDb();
        $this->loadWalletService();

        $userId = $this->createTestUser($pdo)[0];
        $pdo->exec("DELETE FROM wallet_transactions WHERE user_id = {$userId}");

        $svc = new WalletService();
        $result = $svc->applyCardTopUp($userId, 100.0, 'USD', 'cs_test_' . bin2hex(random_bytes(6)));

        $this->assertTrue($result['success']);
        $this->assertEqualsWithDelta(100.0, $result['balance'], 0.001);
        $this->assertGreaterThan(0, (int) $result['transaction_id']);

        // حالة المعاملة في DB = completed
        $row = $pdo->query("SELECT status, payment_method FROM wallet_transactions WHERE id = " . (int) $result['transaction_id'])->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('completed', $row['status']);
        $this->assertSame('card', $row['payment_method']);

        $pdo->exec("DELETE FROM wallet_transactions WHERE user_id = {$userId}");
        $this->deleteTestUser($pdo, $userId);
    }

    public function testCardTopUpIsIdempotentPerGatewayReference(): void
    {
        $pdo = $this->requireDb();
        $this->loadWalletService();

        $userId = $this->createTestUser($pdo)[0];
        $pdo->exec("DELETE FROM wallet_transactions WHERE user_id = {$userId}");

        $svc = new WalletService();
        $ref = 'cs_test_idem_' . bin2hex(random_bytes(6));

        $first = $svc->applyCardTopUp($userId, 50.0, 'USD', $ref);
        $this->assertTrue($first['success']);

        // نفس المرجع تاني -> ما يضفش رصيد جديد
        $second = $svc->applyCardTopUp($userId, 50.0, 'USD', $ref);
        $this->assertTrue($second['success']);
        $this->assertTrue(!empty($second['already_applied']));
        $this->assertEqualsWithDelta(50.0, $second['balance'], 0.001);

        $pdo->exec("DELETE FROM wallet_transactions WHERE user_id = {$userId}");
        $this->deleteTestUser($pdo, $userId);
    }

    public function testCardTopUpRejectsNonPositiveAmount(): void
    {
        $pdo = $this->requireDb();
        $this->loadWalletService();

        $userId = $this->createTestUser($pdo)[0];
        $svc = new WalletService();

        $zero = $svc->applyCardTopUp($userId, 0, 'USD', 'cs_zero');
        $this->assertFalse($zero['success']);

        $negative = $svc->applyCardTopUp($userId, -10, 'USD', 'cs_neg');
        $this->assertFalse($negative['success']);

        $this->deleteTestUser($pdo, $userId);
    }

    public function testTopUpThenSubscribeWithBalance(): void
    {
        $pdo = $this->requireDb();
        $this->loadWalletService();

        // باقة متاحة فعلًا من جدول plan_pricing_display
        $plan = $pdo->query(
            'SELECT plan_key, price_monthly FROM plan_pricing_display WHERE is_active = 1 ORDER BY price_monthly ASC LIMIT 1'
        )->fetch(PDO::FETCH_ASSOC);
        if (empty($plan)) {
            $this->markTestSkipped('لا توجد باقات مفعّلة في plan_pricing_display.');
        }

        [$userId, $testEmail] = $this->createTestUser($pdo);
        $pdo->exec("DELETE FROM wallet_transactions WHERE user_id = {$userId}");
        $pdo->exec("DELETE FROM subscriptions WHERE user_id = {$userId}");

        $svc = new WalletService();
        $price = (float) $plan['price_monthly'];

        // اشحن برصيد أكبر من سعر الباقة
        $topUp = $svc->applyCardTopUp($userId, $price + 50, 'USD', 'cs_t_' . bin2hex(random_bytes(6)));
        $this->assertTrue($topUp['success']);

        // الاشتراك من الرصيد ينفع
        $sub = $svc->subscribeWithBalance($userId, $plan['plan_key'], 'monthly');
        $this->assertTrue($sub['success'], isset($sub['error']) ? $sub['error'] : 'subscribe failed');
        $this->assertSame('active', $sub['subscription']->getAttribute('status'));

        // الاشتراك الناجح لازم يعمل فاتورة مسجلة بحالة paid
        $invoice = $pdo->query(
            "SELECT status, payment_method, amount FROM invoices WHERE user_id = {$userId} ORDER BY id DESC LIMIT 1"
        )->fetch(PDO::FETCH_ASSOC);
        $this->assertNotEmpty($invoice, 'الاشتراك نجح لكن مفيش فاتورة اتسجلت');
        $this->assertSame('paid', $invoice['status']);

        $pdo->exec("DELETE FROM wallet_transactions WHERE user_id = {$userId}");
        $pdo->exec("DELETE FROM subscriptions WHERE user_id = {$userId}");
        $pdo->exec("DELETE FROM invoices WHERE user_id = {$userId}");
        $this->deleteTestUser($pdo, $userId);
    }
}
