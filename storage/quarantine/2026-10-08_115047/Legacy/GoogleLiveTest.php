<?php

/**
 * Tourfecto - GBP LIVE Google Integration Test Harness
 * @version 1.0.0
 * @since 2026-08-14 (GBP Module Upgrade - Round 8: Professional Finalization / Phase AU)
 *
 * ⚠️ الملف ده مختلف تمامًا عن tests/Integration/GbpModuleTest.php:
 * بيستخدم حساب Google Business Profile *حقيقي* فعليًا (مش Fixtures ولا
 * Mocks) - يعني بيعمل طلبات فعلية لسيرفرات Google. عشان كده:
 *
 * - ما بيشتغلش تلقائيًا أبدًا (مش جزء من أي CI/build عادي).
 * - بيشتغل بس لو GBP_LIVE_TEST=true في البيئة (.env أو environment variable).
 * - محتاج توكن OAuth حقيقي متخزّن مسبقًا لموقع اختباري حقيقي (شوف الإعداد تحت).
 * - لو الشروط دي مش متوفرة: كل اختبار بيتسجل كـ SKIP، مش PASS - أبدًا
 *   منقولش "نجح" لحاجة معملناش عليها طلب حقيقي.
 * - أي عملية كتابة (Update/Upload/Publish/Delete) بتستخدم موقع/بيانات
 *   Test واضحة، وبتتشال في النهاية (Cleanup) قد الإمكان.
 *
 * الإعداد المطلوب قبل التشغيل:
 *   export GBP_LIVE_TEST=true
 *   export GBP_LIVE_TEST_WEBSITE_ID=123   (website_id عنده اتصال Google Business حقيقي شغال بالفعل)
 *   export GBP_LIVE_TEST_USER_ID=456      (user_id بتاع نفس الموقع)
 *
 * التشغيل:
 *   php tests/Integration/GoogleLiveTest.php
 */

if (!class_exists('Database')) {
    require_once __DIR__ . '/../bootstrap.php';
}

$appPath = defined('TOURFECTO_APP') ? TOURFECTO_APP : (defined('APP_PATH') ? APP_PATH : __DIR__ . '/../../app');
foreach ([
    '/Models/Website.php',
    '/Models/PlatformConnection.php',
    '/Services/OAuth/GoogleOAuthClient.php',
    '/Services/Reputation/GoogleBusinessAPI.php',
    '/Services/Reputation/GoogleReviewSyncService.php',
    '/Helpers/enterprise_helpers.php',
    '/Services/GoogleBusiness/GbpSetupStatusService.php',
    '/Services/GoogleBusiness/GbpSyncService.php',
    '/Services/GoogleBusiness/GbpProfileService.php',
] as $relative) {
    $full = $appPath . $relative;
    if (file_exists($full)) {
        require_once $full;
    }
}

class GoogleLiveTest
{
    private $passed = 0;
    private $failed = 0;
    private $skipped = 0;
    private $enabled;
    private $websiteId;
    private $userId;
    /** @var PlatformConnection|null */
    private $connection;
    /** @var GoogleBusinessAPI|null */
    private $api;

    public function __construct()
    {
        $this->enabled = (env('GBP_LIVE_TEST') === 'true' || getenv('GBP_LIVE_TEST') === 'true');
        $this->websiteId = (int) (env('GBP_LIVE_TEST_WEBSITE_ID') ?: getenv('GBP_LIVE_TEST_WEBSITE_ID') ?: 0);
        $this->userId = (int) (env('GBP_LIVE_TEST_USER_ID') ?: getenv('GBP_LIVE_TEST_USER_ID') ?: 0);
    }

    public function runAll(): void
    {
        echo "\n🔴 GBP LIVE Google Integration Test\n";
        echo "=====================================\n";

        if (!$this->enabled) {
            echo "GBP_LIVE_TEST != true → LIVE GOOGLE TEST NOT AVAILABLE IN CURRENT ENVIRONMENT\n";
            echo "كل الاختبارات هتتسجل SKIP (مش PASS).\n\n";
        }

        $this->testOAuthConfigured();
        $this->testConnectionExists();
        $this->testTokenRefresh();
        $this->testAccountDiscovery();
        $this->testProfileRead();
        $this->testAttributesDiscovery();
        $this->testInsightsRead();
        $this->testReviewsRead();
        // ملحوظة: ما بنعملش Test فعلي لـ Photo Upload / Post Create /
        // Post Delete هنا تلقائيًا حتى لو الشروط متوفرة - دول عمليات
        // كتابة حقيقية على حساب Google فعلي، ومطلوب موافقة صريحة إضافية
        // (GBP_LIVE_TEST_ALLOW_WRITES=true) قبل ما نجرّبها، عشان منغيّرش
        // بيانات العميل الحقيقية من غير قصد.
        $this->testWriteOperationsGate();

        $this->printSummary();
    }

    private function testOAuthConfigured(): void
    {
        $this->start('OAuth Client Configured');
        if (!$this->enabled) {
            $this->skip('GBP_LIVE_TEST != true');
            return;
        }

        $oauth = new GoogleOAuthClient();
        if ($oauth->isConfigured()) {
            $this->pass('GOOGLE_CLIENT_ID/SECRET مضبوطين');
        } else {
            $this->fail('OAuth Client غير مضبوط - مينفعش نكمل أي اختبار حي تاني');
        }
    }

    private function testConnectionExists(): void
    {
        $this->start('Real Connection Exists');
        if (!$this->enabled) {
            $this->skip('GBP_LIVE_TEST != true');
            return;
        }
        if (!$this->websiteId || !$this->userId) {
            $this->skip('GBP_LIVE_TEST_WEBSITE_ID/USER_ID غير مضبوطين');
            return;
        }

        $sync = new GbpSyncService();
        $this->connection = $sync->findConnection($this->websiteId, $this->userId);

        if ($this->connection && $this->connection->getAttribute('status') === 'connected') {
            $this->pass('لقينا اتصال Google Business حقيقي متصل للموقع ده');
        } else {
            $this->fail('مفيش اتصال Google Business حقيقي "connected" لـ website_id/user_id المحددين');
        }
    }

    private function testTokenRefresh(): void
    {
        $this->start('Token Refresh (Live)');
        if (!$this->enabled || !$this->connection) {
            $this->skip('محتاج اتصال حقيقي من الاختبار اللي فات');
            return;
        }

        try {
            $reviewSync = new GoogleReviewSyncService();
            $accessToken = $reviewSync->getValidAccessToken($this->connection);
            if (!empty($accessToken)) {
                $this->pass('getValidAccessToken() رجّع توكن فعلي (منعرضش قيمته هنا أبدًا)');
                $this->api = new GoogleBusinessAPI(
                    $accessToken,
                    $this->connection->getAttribute('external_account_id'),
                    $this->connection->getAttribute('external_location_id')
                );
            } else {
                $this->fail('getValidAccessToken() رجّع قيمة فاضية');
            }
        } catch (Throwable $e) {
            // منعرضش $e->getMessage() لو فيه احتمال يحتوي تفاصيل حساسة - بس بنأكد إنه Exception متوقع
            $this->fail('فشل تجديد التوكن - status=reconnect_required على الأرجح');
        }
    }

    private function testAccountDiscovery(): void
    {
        $this->start('Account Discovery (accounts.list)');
        if (!$this->enabled || !$this->api) {
            $this->skip('محتاج API client جاهز من اختبار Token Refresh');
            return;
        }

        $result = $this->api->listAccounts();
        if ($result['success'] ?? false) {
            $this->pass('accounts.list رجع نتيجة - EXECUTION VERIFIED');
        } else {
            $this->fail('accounts.list فشل: ' . ($result['error_code'] ?? 'unknown'));
        }
    }

    private function testProfileRead(): void
    {
        $this->start('Profile Read (locations.get)');
        if (!$this->enabled || !$this->api) {
            $this->skip('محتاج API client جاهز');
            return;
        }

        $result = $this->api->getLocation();
        if ($result['success'] ?? false) {
            $this->pass('locations.get رجع بيانات بروفايل حقيقية - EXECUTION VERIFIED');
        } else {
            $this->fail('locations.get فشل: ' . ($result['error_code'] ?? 'unknown'));
        }
    }

    private function testAttributesDiscovery(): void
    {
        $this->start('Attributes Discovery (attributes.list) — الجزء اللي معملوش Live Test قبل كده');
        if (!$this->enabled || !$this->api) {
            $this->skip('محتاج API client جاهز');
            return;
        }

        $result = $this->api->listAvailableAttributes();
        if ($result['success'] ?? false) {
            $count = count($result['available_attributes'] ?? []);
            $this->pass("attributes.list رجع {$count} attribute متاح لتصنيف النشاط ده - EXECUTION VERIFIED (أول تأكيد حي لـ Round 7's rewrite)");
        } else {
            $this->fail('attributes.list فشل: ' . ($result['error_code'] ?? 'unknown') . ' - محتاج مراجعة الـ endpoint/path فورًا لو ده حصل');
        }
    }

    private function testInsightsRead(): void
    {
        $this->start('Insights Read (fetchMultiDailyMetricsTimeSeries)');
        if (!$this->enabled || !$this->api) {
            $this->skip('محتاج API client جاهز');
            return;
        }

        $result = $this->api->fetchDailyMetrics(
            null,
            GoogleBusinessAPI::SUPPORTED_METRICS,
            date('Y-m-d', strtotime('-8 days')),
            date('Y-m-d', strtotime('-1 day'))
        );
        if ($result['success'] ?? false) {
            $this->pass('fetchMultiDailyMetricsTimeSeries رجع بيانات - EXECUTION VERIFIED');
        } else {
            $this->fail('fetchMultiDailyMetricsTimeSeries فشل: ' . ($result['error_code'] ?? 'unknown'));
        }
    }

    private function testReviewsRead(): void
    {
        $this->start('Reviews Read (accounts.locations.reviews.list)');
        if (!$this->enabled || !$this->api) {
            $this->skip('محتاج API client جاهز');
            return;
        }

        $result = $this->api->getReviews();
        if ($result['success'] ?? false) {
            $this->pass('Reviews list رجع نتيجة - EXECUTION VERIFIED');
        } else {
            $this->fail('Reviews list فشل: ' . ($result['error_code'] ?? 'unknown'));
        }
    }

    private function testWriteOperationsGate(): void
    {
        $this->start('Write Operations (Photo Upload / Post Create / Delete)');
        $allowWrites = (env('GBP_LIVE_TEST_ALLOW_WRITES') === 'true' || getenv('GBP_LIVE_TEST_ALLOW_WRITES') === 'true');

        if (!$allowWrites) {
            $this->skip('GBP_LIVE_TEST_ALLOW_WRITES != true - عمليات الكتابة على حساب حقيقي محتاجة تفعيل صريح إضافي، عمدًا');
            return;
        }

        // لو حد فعّل GBP_LIVE_TEST_ALLOW_WRITES فعلاً بوعي، منطق الكتابة
        // الفعلي (رفع صورة اختبارية بعنوان واضح + حذفها فورًا، إنشاء
        // Draft post بعنوان اختباري واضح + حذفه) لازم يتضاف هنا صراحة
        // وقت التنفيذ الفعلي مع حساب اختباري حقيقي متاح - مش هنكتبه دلوقتي
        // من غير حساب نقدر نتأكد بيه إن التنظيف (cleanup) شغال صح 100%،
        // عشان منسيبش صور/منشورات تجريبية على حساب Google حقيقي بالغلط.
        $this->skip('منطق الكتابة الفعلي (Upload/Publish/Delete اختباري) لسه محتاج تنفيذ مباشر مع حساب اختباري حقيقي متاح وقت الاختبار - مش هنخمّنه هنا');
    }

    private function start(string $name): void
    {
        echo "\n  ▶ {$name}\n";
    }
    private function pass(string $msg): void
    {
        echo "    ✅ EXECUTION VERIFIED: {$msg}\n";
        $this->passed++;
    }
    private function fail(string $msg): void
    {
        echo "    ❌ FAILED: {$msg}\n";
        $this->failed++;
    }
    private function skip(string $reason): void
    {
        echo "    ⏭️  SKIP: {$reason}\n";
        $this->skipped++;
    }

    private function printSummary(): void
    {
        echo "\n" . str_repeat('=', 50) . "\n";
        echo "📊 Live Google Test Summary\n";
        echo str_repeat('=', 50) . "\n";
        echo "  ✅ Passed (Execution Verified): {$this->passed}\n";
        echo "  ❌ Failed: {$this->failed}\n";
        echo "  ⏭️  Skipped: {$this->skipped}\n";
        if (!$this->enabled) {
            echo "\n⚠️  LIVE GOOGLE TEST NOT AVAILABLE IN CURRENT ENVIRONMENT\n";
        }
        echo str_repeat('=', 50) . "\n\n";
    }
}

if (basename(__FILE__) === basename($_SERVER['PHP_SELF'] ?? '')) {
    $test = new GoogleLiveTest();
    $test->runAll();
}
