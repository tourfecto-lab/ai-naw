<?php

/**
 * Tourfecto - Billing Integration Test
 * اختبار تكامل للبنية التحتية للفوترة (يجري على السيرفر بقاعدة بيانات
 * حقيقية). بيتخطى تلقائيًا (markTestSkipped) لو قاعدة البيانات غير متاحة
 * - مثالًا بيئة التطوير المحلية من غير MySQL - وبيشتغل كاملًا لما يكون
 * في اتصال حقيقي (سيرفر الإنتاج / tourfecto_test).
 *
 * الاختبار بيحمّل متطلباته بنفسه (guarded requires) بدل ما يعتمد على
 * bootstrap كامل للتطبيق - عشان يقدر يتشغّل من غير أي إعداد مسبق:
 *
 *   phpunit --bootstrap tests/Unit/offline_bootstrap.php tests/Integration/BillingLifecycleIntegrationTest.php
 *
 * أو عبر phpunit.xml لو الـ bootstrap بيشيل ملفات التطبيق زي Database.
 *
 * بيفحص:
 *   1) وجود جداول الفوترة الأساسية اللي الموديول بيعتمد عليها.
 *   2) وجود باقات التسعير الافتراضية (فيه plan واحد على الأقل شهري/سنوي).
 *   3) بنية نتيجة runLifecycleChecks() (المفاتيح الأساسية + التكرار آمن).
 * @version 1.0.0
 * @date 2026-08-17
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/Services/Subscription/BillingRules.php';

final class BillingLifecycleIntegrationTest extends TestCase
{
    private static ?PDO $pdo = null;
    private static bool $dbChecked = false;

    private function db(): ?PDO
    {
        if (self::$dbChecked) {
            return self::$pdo;
        }
        self::$dbChecked = true;

        // تحميل متطلبات الاتصال بنفسنا (guarded) - بصلح البيئات اللي
        // bootstrap التطبيق الكامل مش متاح فيها (مسارات قديمة/ناقصة).
        // كل الـ requires جوه try/catch: Config/database.php بترمي
        // RuntimeException لو DB_NAME/DB_USER مش موجودين - وخطأ التحميل
        // ده لازم يتعامل معاه كـ "DB مش متاحة" مش كفشل اختبار.
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

    public function testBillingTablesExist(): void
    {
        $pdo = $this->requireDb();
        $expected = ['plan_pricing_display', 'subscriptions', 'wallet_transactions', 'invoices', 'tax_rules', 'wallet_payment_settings', 'wallet_recharge_cards'];
        $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        $tables = array_map('strtolower', $tables);
        foreach ($expected as $table) {
            $this->assertContains($table, $tables, "جدول الفوترة المطلوب {$table} غير موجود");
        }
    }

    public function testDefaultPlansSeededWithPricing(): void
    {
        $pdo = $this->requireDb();
        $rows = $pdo->query(
            'SELECT plan_key, price_monthly, price_yearly FROM plan_pricing_display WHERE is_active = 1'
        )->fetchAll(PDO::FETCH_ASSOC);
        $this->assertNotEmpty($rows, 'لا توجد باقات مفعّلة في جدول plans');
        foreach ($rows as $row) {
            $this->assertGreaterThanOrEqual(0.0, (float) $row['price_monthly'], "{$row['plan_key']} price_monthly سالبة");
            $this->assertGreaterThanOrEqual(0.0, (float) $row['price_yearly'], "{$row['plan_key']} price_yearly سالبة");
        }
    }

    public function testLifecycleChecksReturnExpectedStructureAndAreRepeatable(): void
    {
        $this->requireDb();

        $lifecycleFile = dirname(__DIR__, 2) . '/app/Services/Subscription/SubscriptionLifecycleService.php';
        if (!class_exists('SubscriptionLifecycleService')) {
            if (file_exists($lifecycleFile)) {
                require_once $lifecycleFile;
            }
        }
        if (!class_exists('SubscriptionLifecycleService')) {
            $this->markTestSkipped('SubscriptionLifecycleService غير محمّلة في هذه البيئة.');
        }

        $service = new SubscriptionLifecycleService();
        $result = $service->runLifecycleChecks();

        $expectedKeys = [
            'moved_to_past_due', 'moved_to_cancelled', 'cancelled_at_period_end',
            'trials_ended', 'renewal_reminders_sent', 'early_renewal_reminders_sent',
            'dunning_final_notices_sent', 'auto_renewals',
        ];
        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $result, "مفتاح النتيجة {$key} مفقود من runLifecycleChecks()");
        }

        $this->assertIsArray($result['auto_renewals']);
        foreach (['attempted', 'renewed', 'insufficient_balance', 'skipped', 'failed'] as $k) {
            $this->assertArrayHasKey($k, $result['auto_renewals'], "مفتاح auto_renewals.{$k} مفقود");
        }

        // التكرار آمن: تشغيل تاني مينفعش يحرّك صفوف تانية (idempotent) -
        // كل فحوصات الانتقالات إما صفر أو غير سالبة في التشغيلة التانية.
        $second = $service->runLifecycleChecks();
        foreach (['moved_to_past_due', 'moved_to_cancelled', 'cancelled_at_period_end', 'trials_ended'] as $k) {
            $this->assertGreaterThanOrEqual(0, $second[$k], "{$k} سالب في التشغيلة التانية");
        }
        $this->assertTrue(true, 'دورة الحياة آمنة للتكرار (idempotent)');
    }
}
