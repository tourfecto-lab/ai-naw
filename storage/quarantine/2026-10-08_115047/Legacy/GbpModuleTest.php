<?php

/**
 * Tourfecto - GBP Module Upgrade Integration Test
 * بيغطي: Setup Wizard status، Tenant Isolation، Validation قبل الإرسال،
 * وسلوك الفشل الآمن (بدون ما يدّعي اتصال حقيقي) لما مفيش Google
 * Credentials حقيقية متاحة - زي ما مطلوب بالضبط في قسم "Testing" بالسبيك.
 * تشغيل: php tests/Integration/GbpModuleTest.php
 * @version 1.0.0
 * @since 2026-08-09 (GBP Module Upgrade)
 */

if (!class_exists('Database')) {
    require_once __DIR__ . '/../bootstrap.php';
}

// كلاسات إضافية لازمة لموديول GBP ومش متضمنة في bootstrap.php المشترك -
// كل واحدة محاطة بـ file_exists عشان لو bootstrap.php اتحدّث لاحقًا
// وضمّها، منحصلش على تعريف مزدوج.
$appPath = defined('TOURFECTO_APP') ? TOURFECTO_APP : (defined('APP_PATH') ? APP_PATH : __DIR__ . '/../../app');

foreach ([
    '/Models/Website.php',
    '/Models/PlatformConnection.php',
    '/Services/OAuth/GoogleOAuthClient.php',
    '/Services/Reputation/GoogleBusinessAPI.php',
    '/Services/Reputation/GoogleReviewSyncService.php',
    '/Services/System/SystemSettingsService.php',
    '/Helpers/enterprise_helpers.php',
    '/Services/GoogleBusiness/GbpSetupStatusService.php',
    '/Services/GoogleBusiness/GbpSyncService.php',
    '/Services/GoogleBusiness/GbpProfileService.php',
    '/Services/GoogleBusiness/GbpPhotoService.php',
    '/Services/GoogleBusiness/GbpMediaUploadHandler.php',
    '/Services/GoogleBusiness/GbpInsightsService.php',
    '/Services/GoogleBusiness/GbpAIInsightsService.php',
    '/Core/Contracts/QueueJobInterface.php',
    '/Jobs/GbpBackgroundSyncJob.php',
] as $relative) {
    $full = $appPath . $relative;
    if (file_exists($full)) {
        require_once $full;
    }
}

class GbpModuleTest
{
    private $testResults = [];
    private $passed = 0;
    private $failed = 0;

    /** @var Database */
    private $db;

    private $userAId;
    private $userBId;
    private $websiteAId;
    private $websiteBId;
    private $connectionAId;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->userAId = $this->createTestUser('gbp_test_a_');
        $this->userBId = $this->createTestUser('gbp_test_b_');
        $this->websiteAId = $this->createTestWebsite($this->userAId);
        $this->websiteBId = $this->createTestWebsite($this->userBId);
        // اتصال وهمي (Fixture) لمستخدم A بس - توكن مش حقيقي عمدًا، عشان
        // نتأكد إن أي محاولة استخدام فعلي بترفض بأمان من غير ما تدّعي نجاح.
        $this->connectionAId = $this->createFixtureConnection($this->userAId, $this->websiteAId);
    }

    public function runAll(): void
    {
        echo "\n⭐ GBP Module Upgrade - Integration Tests\n";
        echo "==========================================\n";
        echo "ملحوظة: مفيش Google Credentials حقيقية متاحة في بيئة الاختبار -\n";
        echo "الاختبارات دي بتتأكد من سلوك الفشل الآمن + العزل بين Tenants + الـ Validation، مش من نجاح اتصال فعلي بجوجل.\n";

        $this->testSetupWizardStatus();
        $this->testTenantIsolation();
        $this->testConnectionNotFoundIsSafe();
        $this->testPhotoUploadValidation();
        $this->testProfileUpdateFailsGracefullyWithoutRealToken();
        $this->testInsightsFailGracefullyWhenNotConnected();
        $this->testAiInsightsNeverClaimsFakeSuccess();
        $this->testAttributesFailGracefullyWithoutRealToken();
        $this->testBackgroundSyncJobHandlesMissingConnectionSafely();

        $this->cleanup();
        $this->printSummary();
    }

    /** Setup Wizard status لازم يرجع بدون Exception حتى لو الإعدادات ناقصة، وبحالة "missing" واضحة */
    private function testSetupWizardStatus(): void
    {
        $this->startTest('Setup Wizard - System Status');
        try {
            $service = new GbpSetupStatusService();
            $status = $service->systemStatus();
            $keys = ['google_maps', 'oauth_client', 'business_profile_api'];
            $ok = true;
            foreach ($keys as $k) {
                if (!isset($status[$k]['status']) || !isset($status[$k]['detail'])) {
                    $ok = false;
                }
            }
            if ($ok) {
                $this->pass('systemStatus() يرجع الحقول الثلاثة المطلوبة بدون Exception');
            } else {
                $this->fail('systemStatus() ناقص حقول متوقعة');
            }
        } catch (Throwable $e) {
            $this->fail('systemStatus() رمى Exception: ' . $e->getMessage());
        }
    }

    /** أهم اختبار أمني: Tenant B لازم ميشوفش اتصالات/مواقع Tenant A أبدًا */
    private function testTenantIsolation(): void
    {
        $this->startTest('Multi-Tenant Isolation');
        try {
            $service = new GbpSetupStatusService();

            $connectionsForA = $service->connectionsForUser($this->userAId);
            $connectionsForB = $service->connectionsForUser($this->userBId);

            $aHasOwn = count(array_filter($connectionsForA, fn ($c) => $c['website_id'] === $this->websiteAId)) > 0;
            $bSeesA = count(array_filter($connectionsForB, fn ($c) => $c['website_id'] === $this->websiteAId)) > 0;

            if ($aHasOwn && !$bSeesA) {
                $this->pass('Tenant B لا يستطيع رؤية اتصالات Tenant A (العزل شغال)');
            } else {
                $this->fail('خطأ عزل بيانات! Tenant B قدر يشوف بيانات Tenant A');
            }

            $websitesForB = $service->websitesWithConnectionState($this->userBId);
            $bSeesAWebsite = count(array_filter($websitesForB, fn ($w) => $w['website_id'] === $this->websiteAId)) > 0;
            if (!$bSeesAWebsite) {
                $this->pass('websitesWithConnectionState() معزولة بالـ Tenant بشكل صحيح');
            } else {
                $this->fail('خطأ عزل: Tenant B شاف موقع Tenant A في websitesWithConnectionState()');
            }
        } catch (Throwable $e) {
            $this->fail('اختبار العزل رمى Exception: ' . $e->getMessage());
        }
    }

    /** findConnection على موقع مش مربوط لازم يرجع null، مش Exception */
    private function testConnectionNotFoundIsSafe(): void
    {
        $this->startTest('Sync Service - Connection Not Found');
        try {
            $sync = new GbpSyncService();
            $result = $sync->findConnection($this->websiteBId, $this->userBId); // B مفهوش اتصال
            if ($result === null) {
                $this->pass('findConnection() يرجع null بأمان لما مفيش اتصال');
            } else {
                $this->fail('findConnection() المفروض يرجع null');
            }
        } catch (Throwable $e) {
            $this->fail('findConnection() رمى Exception بدل ما يرجع null: ' . $e->getMessage());
        }
    }

    /** Validation الصور لازم يشتغل من غير ما يحتاج اتصال حقيقي بجوجل */
    private function testPhotoUploadValidation(): void
    {
        $this->startTest('Photo Upload Validation (no network needed)');
        try {
            $service = new GbpPhotoService();

            $tooLarge = $service->validateUpload(['error' => UPLOAD_ERR_OK, 'size' => 6 * 1024 * 1024, 'tmp_name' => '']);
            $noFile = $service->validateUpload(['error' => UPLOAD_ERR_NO_FILE, 'size' => 0, 'tmp_name' => '']);

            if (!$tooLarge['valid'] && !$noFile['valid']) {
                $this->pass('validateUpload() يرفض الملفات الكبيرة/الغير موجودة بشكل صحيح');
            } else {
                $this->fail('validateUpload() قبل ملف مفروض يترفض');
            }
        } catch (Throwable $e) {
            $this->fail('validateUpload() رمى Exception: ' . $e->getMessage());
        }
    }

    /**
     * بدون Google Credentials حقيقية، أي محاولة تحديث بروفايل فعلي لازم
     * تفشل برسالة واضحة (يحتاج Reconnect) - مش تدّعي نجاح وهمي.
     */
    private function testProfileUpdateFailsGracefullyWithoutRealToken(): void
    {
        $this->startTest('Profile Update - Safe Failure Without Real Token');
        try {
            $service = new GbpProfileService();
            $result = $service->updateProfile($this->websiteAId, $this->userAId, ['phone' => 'not-a-valid-phone!!!']);

            if ($result['success'] === false && !empty($result['error'])) {
                $this->pass('updateProfile() برقم هاتف غير صحيح رفض الطلب بدل ما يبعته لجوجل: ' . $result['error']);
            } else {
                $this->fail('updateProfile() قبل رقم هاتف غير صحيح أو ادّعى نجاح وهمي');
            }
        } catch (Throwable $e) {
            $this->fail('updateProfile() رمى Exception غير متوقع: ' . $e->getMessage());
        }
    }

    /** لموقع مش مربوط أصلًا، الـ Insights لازم يرجع "Not Connected" مش بيانات وهمية */
    private function testInsightsFailGracefullyWhenNotConnected(): void
    {
        $this->startTest('Insights - No Fake Data When Not Connected');
        try {
            $service = new GbpInsightsService();
            $result = $service->getInsights($this->websiteBId, $this->userBId, 30, true); // B مفهوش اتصال

            if ($result['success'] === false && stripos($result['error'], 'Not Connected') !== false) {
                $this->pass('getInsights() رجع "Not Connected" بدل بيانات وهمية');
            } else {
                $this->fail('getInsights() المفروض يرجع Not Connected بوضوح');
            }
        } catch (Throwable $e) {
            $this->fail('getInsights() رمى Exception: ' . $e->getMessage());
        }
    }

    /** AI Insights لازم يفشل بوضوح (مش يخترع أرقام) لو مفيش بيانات حقيقية */
    private function testAiInsightsNeverClaimsFakeSuccess(): void
    {
        $this->startTest('AI Insights - No Invented Numbers Without Real Data');
        try {
            $ai = new GbpAIInsightsService();
            $result = $ai->generateInsights($this->websiteBId, $this->userBId); // B مفهوش اتصال ولا بيانات

            if ($result['success'] === false && empty($result['insights'])) {
                $this->pass('generateInsights() رجع فشل واضح بدل اختراع insights وهمية');
            } else {
                $this->fail('generateInsights() المفروض يفشل بوضوح لما مفيش بيانات حقيقية');
            }
        } catch (Throwable $e) {
            $this->fail('generateInsights() رمى Exception: ' . $e->getMessage());
        }
    }

    /** Round 5: Attributes لازم تفشل بأمان (Reconnect) من غير توكن حقيقي، مش تدّعي نجاح */
    private function testAttributesFailGracefullyWithoutRealToken(): void
    {
        $this->startTest('Attributes - Safe Failure Without Real Token');
        try {
            $service = new GbpProfileService();
            $result = $service->getAttributes($this->websiteAId, $this->userAId); // A عنده اتصال Fixture (توكن وهمي)

            if ($result['success'] === false && !empty($result['error'])) {
                $this->pass('getAttributes() فشل بأمان مع توكن وهمي: ' . $result['error']);
            } else {
                $this->fail('getAttributes() المفروض يفشل مع توكن غير حقيقي');
            }
        } catch (Throwable $e) {
            $this->fail('getAttributes() رمى Exception غير متوقع: ' . $e->getMessage());
        }
    }

    /** Round 5: GbpBackgroundSyncJob لازم يرمي Exception واضح لو الـ payload ناقص (Queue Worker هيسجّله كـ failed مش كريش صامت) */
    private function testBackgroundSyncJobHandlesMissingConnectionSafely(): void
    {
        $this->startTest('Background Sync Job - Missing Payload Safety');
        try {
            $job = new GbpBackgroundSyncJob();
            $threw = false;
            try {
                $job->handle([]); // payload فاضي عمدًا
            } catch (Throwable $e) {
                $threw = true;
            }

            if ($threw) {
                $this->pass('GbpBackgroundSyncJob::handle() برمي Exception واضح لما الـ payload ناقص');
            } else {
                $this->fail('GbpBackgroundSyncJob::handle() المفروض يرمي Exception مع payload فاضي');
            }
        } catch (Throwable $e) {
            $this->fail('اختبار Background Sync Job فشل: ' . $e->getMessage());
        }
    }

    // ============================================
    // Fixtures
    // ============================================

    private function createTestUser(string $prefix): int
    {
        $sql = "INSERT INTO users (company_name, email, password, phone, is_active)
                VALUES (:company_name, :email, :password, :phone, :is_active)";
        return (int) $this->db->query($sql, [
            ':company_name' => 'GBP Test Co ' . $prefix,
            ':email' => $prefix . uniqid() . '@example.com',
            ':password' => password_hash('Test@12345', PASSWORD_ARGON2ID),
            ':phone' => '+966500000002',
            ':is_active' => 1,
        ]);
    }

    private function createTestWebsite(int $userId): int
    {
        $sql = "INSERT INTO websites (user_id, main_url, company_name, industry, is_verified)
                VALUES (:user_id, :main_url, :company_name, :industry, :is_verified)";
        return (int) $this->db->query($sql, [
            ':user_id' => $userId,
            ':main_url' => 'https://gbp-test-' . uniqid() . '.example.com',
            ':company_name' => 'GBP Test Website',
            ':industry' => 'hospitality',
            ':is_verified' => 1,
        ]);
    }

    /** اتصال Fixture بتوكن وهمي (مش حقيقي) - يوضح إننا "لا نتظاهر بنجاح اتصال حقيقي" */
    private function createFixtureConnection(int $userId, int $websiteId): int
    {
        $sql = "INSERT INTO platform_connections
                    (website_id, user_id, platform, access_token, refresh_token, token_expires_at,
                     external_account_id, external_location_id, external_location_name, status)
                VALUES (:website_id, :user_id, 'google_business', :access_token, :refresh_token, :expires_at,
                        :account_id, :location_id, :location_name, 'connected')";
        $encryption = new Encryption();
        return (int) $this->db->query($sql, [
            ':website_id' => $websiteId,
            ':user_id' => $userId,
            ':access_token' => $encryption->encrypt('FAKE_TEST_TOKEN_NOT_REAL'),
            ':refresh_token' => $encryption->encrypt('FAKE_TEST_REFRESH_NOT_REAL'),
            ':expires_at' => date('Y-m-d H:i:s', time() - 3600), // منتهي عمدًا عشان يجرّب Refresh ويفشل بأمان
            ':account_id' => 'test_account',
            ':location_id' => 'test_location',
            ':location_name' => 'GBP Test Location (Fixture)',
        ]);
    }

    private function cleanup(): void
    {
        $this->db->query("DELETE FROM gbp_sync_logs WHERE website_id IN (:a, :b)", [':a' => $this->websiteAId, ':b' => $this->websiteBId]);
        $this->db->query("DELETE FROM gbp_photos WHERE website_id IN (:a, :b)", [':a' => $this->websiteAId, ':b' => $this->websiteBId]);
        $this->db->query("DELETE FROM platform_connections WHERE website_id IN (:a, :b)", [':a' => $this->websiteAId, ':b' => $this->websiteBId]);
        $this->db->query("DELETE FROM websites WHERE id IN (:a, :b)", [':a' => $this->websiteAId, ':b' => $this->websiteBId]);
        $this->db->query("DELETE FROM users WHERE id IN (:a, :b)", [':a' => $this->userAId, ':b' => $this->userBId]);
    }

    private function startTest(string $name): void
    {
        echo "\n  ▶ {$name}\n";
    }

    private function pass(string $message): void
    {
        echo "    ✅ {$message}\n";
        $this->passed++;
        $this->testResults[] = ['status' => 'PASS', 'message' => $message];
    }

    private function fail(string $message): void
    {
        echo "    ❌ {$message}\n";
        $this->failed++;
        $this->testResults[] = ['status' => 'FAIL', 'message' => $message];
    }

    private function printSummary(): void
    {
        $total = $this->passed + $this->failed;
        $percentage = $total > 0 ? round(($this->passed / $total) * 100, 2) : 0;
        echo "\n" . str_repeat('=', 50) . "\n";
        echo "📊 GBP Module Test Summary\n";
        echo str_repeat('=', 50) . "\n";
        echo "  ✅ Passed: {$this->passed}\n";
        echo "  ❌ Failed: {$this->failed}\n";
        echo "  📝 Total: {$total}\n";
        echo "  📈 Success Rate: {$percentage}%\n";
        echo str_repeat('=', 50) . "\n\n";
    }
}

if (basename(__FILE__) === basename($_SERVER['PHP_SELF'] ?? '')) {
    $test = new GbpModuleTest();
    $test->runAll();
}
