<?php

/**
 * Tourfecto - Test Bootstrap
 * تهيئة بيئة الاختبار لـ PHPUnit
 * @version 1.0.0
 * @author Tourfecto Team
 * @copyright 2026 Tourfecto
 */

// ============================================
// 1. تعريف ثوابت الاختبار
// ============================================
define('TOURFECTO_ROOT', dirname(__DIR__));
define('TOURFECTO_APP', TOURFECTO_ROOT . '/app');
define('TOURFECTO_STORAGE', TOURFECTO_ROOT . '/storage');
define('TOURFECTO_TESTS', __DIR__);
define('TOURFECTO_TEST_ENV', 'testing');
define('TOURFECTO_TEST_DB', 'tourfecto_test');

// ============================================
// 1.5. تعريف دالة env() قبل تحميل ملفات التكوين
// ملفات التكوين (Config/app.php, database.php) تستدعي env() مباشرة،
// لكن الدالة معرّفة في app/Helpers/functions.php اللي بتتحمّل بعدهم
// في الترتيب الأصلي - فكان bootstrap ده بيفشل دايمًا بـ
// "Call to undefined function env()". تعريف محايد هنا يحل المشكلة
// ويخلي phpunit.xml يشتغل فعليًا (يرجع قيم متغيرات البيئة الحقيقية
// إن وجدت، وإلا الافتراضي من phpunit.xml).
// ============================================
if (!function_exists('env')) {
    function env(string $key, $default = null)
    {
        $real = getenv($key);
        return $real === false ? $default : $real;
    }
}

// ============================================
// 2. تحميل ملفات التكوين
// ============================================
require_once TOURFECTO_APP . '/Config/app.php';
require_once TOURFECTO_APP . '/Config/database.php';
require_once TOURFECTO_APP . '/Config/gemini.php';
require_once TOURFECTO_APP . '/Config/whatsapp.php';
require_once TOURFECTO_APP . '/Config/openai.php';
require_once TOURFECTO_APP . '/Config/deepseek.php';
require_once TOURFECTO_APP . '/Config/kimi.php';
require_once TOURFECTO_APP . '/Config/encryption.php';
require_once TOURFECTO_APP . '/Config/constants.php';

// ============================================
// 3. تحميل الكلاسات الأساسية
// ============================================
require_once TOURFECTO_APP . '/Core/Database.php';
require_once TOURFECTO_APP . '/Core/Encryption.php';
require_once TOURFECTO_APP . '/Core/Logger.php';
require_once TOURFECTO_APP . '/Core/Validator.php';
require_once TOURFECTO_APP . '/Core/Cache.php';
require_once TOURFECTO_APP . '/Core/Router.php';
require_once TOURFECTO_APP . '/Core/Controller.php';
require_once TOURFECTO_APP . '/Core/Model.php';

// ============================================
// 4. تحميل النماذج (Models)
// ============================================
require_once TOURFECTO_APP . '/Models/User.php';
require_once TOURFECTO_APP . '/Models/Subscription.php';
require_once TOURFECTO_APP . '/Models/Website.php';
require_once TOURFECTO_APP . '/Models/AIReport.php';
require_once TOURFECTO_APP . '/Models/Review.php';
require_once TOURFECTO_APP . '/Models/ChatMessage.php';
require_once TOURFECTO_APP . '/Models/BotSetting.php';
require_once TOURFECTO_APP . '/Models/ApiUsageLog.php';

// ============================================
// 5. تحميل خدمات التطبيق
// ============================================
require_once TOURFECTO_APP . '/Services/AI/TourfectoAIEngine.php';
require_once TOURFECTO_APP . '/Services/AI/GeminiClient.php';
require_once TOURFECTO_APP . '/Services/AI/PromptBuilder.php';
require_once TOURFECTO_APP . '/Services/AI/ResponseParser.php';
require_once TOURFECTO_APP . '/Services/AI/SemanticCache.php';

require_once TOURFECTO_APP . '/Services/Reputation/ReputationManager.php';
require_once TOURFECTO_APP . '/Services/Reputation/SentimentAnalyzer.php';
require_once TOURFECTO_APP . '/Services/Reputation/ReplyGenerator.php';

require_once TOURFECTO_APP . '/Services/Chat/ChatManager.php';
require_once TOURFECTO_APP . '/Services/Chat/MessageProcessor.php';
require_once TOURFECTO_APP . '/Services/Chat/AutoReplyEngine.php';
require_once TOURFECTO_APP . '/Services/Chat/ApprovalSystem.php';

require_once TOURFECTO_APP . '/Services/Subscription/SubscriptionValidator.php';
require_once TOURFECTO_APP . '/Services/Subscription/UsageTracker.php';
require_once TOURFECTO_APP . '/Services/Subscription/BillingManager.php';
require_once TOURFECTO_APP . '/Services/PlanFeature.php';

require_once TOURFECTO_APP . '/Services/Security/GDPRCompliance.php';
require_once TOURFECTO_APP . '/Services/Security/DataEncryption.php';
require_once TOURFECTO_APP . '/Services/Security/RateLimiter.php';
require_once TOURFECTO_APP . '/Services/Security/CSRFProtection.php';

// ============================================
// 6. تحميل الوسائط (Middleware)
// ============================================
require_once TOURFECTO_APP . '/Middleware/AuthMiddleware.php';
require_once TOURFECTO_APP . '/Middleware/SubscriptionMiddleware.php';
require_once TOURFECTO_APP . '/Middleware/RateLimitMiddleware.php';
require_once TOURFECTO_APP . '/Middleware/CORSMiddleware.php';
require_once TOURFECTO_APP . '/Middleware/LoggingMiddleware.php';

// ============================================
// 7. تحميل المتحكمات (Controllers)
// ============================================
require_once TOURFECTO_APP . '/Controllers/AIController.php';
require_once TOURFECTO_APP . '/Controllers/ReputationController.php';
require_once TOURFECTO_APP . '/Controllers/ChatController.php';
require_once TOURFECTO_APP . '/Controllers/SubscriptionController.php';
require_once TOURFECTO_APP . '/Controllers/WebhookController.php';
require_once TOURFECTO_APP . '/Controllers/DashboardController.php';

// ============================================
// 8. تحميل دوال المساعدة
// ============================================
require_once TOURFECTO_APP . '/Helpers/functions.php';
require_once TOURFECTO_APP . '/Helpers/validation.php';
require_once TOURFECTO_APP . '/Helpers/formatting.php';
require_once TOURFECTO_APP . '/Helpers/array_helper.php';

// ============================================
// 9. تحميل الاستثناءات (Exceptions)
// ============================================
require_once TOURFECTO_APP . '/Exceptions/DatabaseException.php';
require_once TOURFECTO_APP . '/Exceptions/APIException.php';
require_once TOURFECTO_APP . '/Exceptions/AuthException.php';
require_once TOURFECTO_APP . '/Exceptions/SubscriptionException.php';
require_once TOURFECTO_APP . '/Exceptions/ValidationException.php';

// ============================================
// 10. تحميل بيانات الاختبار (Fixtures)
// ============================================
require_once TOURFECTO_TESTS . '/Fixtures/FixtureLoader.php';

// ============================================
// 11. إنشاء مجلدات الاختبار
// ============================================
$testDirs = [
    TOURFECTO_STORAGE . '/logs',
    TOURFECTO_STORAGE . '/cache/test',
    TOURFECTO_STORAGE . '/uploads/temp',
    TOURFECTO_STORAGE . '/backups/test'
];

foreach ($testDirs as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
}

// ============================================
// 12. إعداد قاعدة بيانات الاختبار
// ============================================
function setupTestDatabase(): void
{
    $db = Database::getInstance();

    try {
        // إنشاء قاعدة بيانات الاختبار
        $sql = "CREATE DATABASE IF NOT EXISTS " . TOURFECTO_TEST_DB . " 
                CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci";
        $db->query($sql);

        // استخدام قاعدة بيانات الاختبار
        $sql = "USE " . TOURFECTO_TEST_DB;
        $db->query($sql);

        echo "✅ Test database created/selected: " . TOURFECTO_TEST_DB . "\n";

    } catch (Exception $e) {
        echo "❌ Failed to setup test database: " . $e->getMessage() . "\n";
    }
}

// ============================================
// 13. تحميل مخطط قاعدة البيانات
// ============================================
function loadDatabaseSchema(): void
{
    $db = Database::getInstance();
    $schemaFile = TOURFECTO_ROOT . '/database/schema.sql';

    if (!file_exists($schemaFile)) {
        echo "❌ Schema file not found: " . $schemaFile . "\n";
        return;
    }

    try {
        // قراءة ملف الـ SQL
        $sql = file_get_contents($schemaFile);

        // إزالة كتل DELIMITER (الإجراءات المخزنة والمشغلات) - الاختبارات
        // لا تحتاجها وتكسر التقسيم العادي على الفاصلة المنقوطة.
        $sql = preg_replace('/DELIMITER\s*\/\/.*?DELIMITER\s*;/s', '', $sql);

        // تقسيم الاستعلامات
        $queries = explode(';', $sql);

        foreach ($queries as $query) {
            $query = trim($query);
            if (empty($query)) {
                continue;
            }
            // تجاهل إنشاء/اختيار قاعدة بيانات معينة - الاختبارات تشتغل
            // على قاعدة بيانات الاختبار المحددة في phpunit.xml.
            if (strpos($query, 'CREATE DATABASE') !== false
                || preg_match('/^USE\s+`?[a-z0-9_]+`?$/i', $query)) {
                continue;
            }

            // SET AUTOCOMMIT = 0 في السكيما (مخصصة لتصدير البيانات يدويًا)
            // بتعطّل الـ autocommit على اتصال الاختبار المشترك كله، فيفتح
            // معاملة ضمنية تفضل معلقة - وأي beginTransaction() بعدها بيفشل
            // بـ "There is already an active transaction". نتجاهلها هنا
            // ونعيد تفعيل الـ autocommit بعد نهاية التحميل.
            if (stripos($query, 'SET AUTOCOMMIT') === 0) {
                continue;
            }

            $db->query($query);
        }

        // إعادة تفعيل الـ autocommit بعد تحميل السكيما - الاختبارات بتعتمد
        // على سلوك الـ autocommit الافتراضي (كل استعلام يثبت لوحده).
        $db->query('SET AUTOCOMMIT = 1');

        echo "✅ Database schema loaded successfully\n";

    } catch (Exception $e) {
        echo "❌ Failed to load schema: " . $e->getMessage() . "\n";
    }
}

// ============================================
// 13.5. تطبيق الميجريشنز المطلوبة للاختبارات (بعد السكيما الأساسية)
// سكيما المخطط الأساسي (schema.sql) بتغطي الجداول القديمة، لكن بعض
// اختبارات الـ integration بتحتاج جداول من ميجريشنز أحدث (booking engine,
// wallet, tax_rules...). هنطّبقهم على قاعدة بيانات الاختبار بس (idempotent).
// ============================================
function applyTestMigrations(): void
{
    $db = Database::getInstance();

    $migrations = [
        '2026_08_08_000009_create_crm_products_and_deal_items_tables.sql',
        '2026_08_25_000001_add_website_binding_to_crm_products.sql',
        '2026_08_21_000001_create_booking_engine_tables.sql',
        '2026_08_12_000047_create_payment_transactions_table.sql',
        '2026_08_21_000002_add_booking_payment_link.sql',
        '2026_08_26_000001_add_booking_id_to_crm_deals.sql',
        '2026_08_26_000002_agency_commissions.sql',
        '2026_08_21_000010_create_email_marketing_tables.sql',
        '2026_08_21_000011_email_marketing_contacts.sql',
        '2026_08_22_000012_email_marketing_template_studio.sql',
        '2026_08_22_000013_email_marketing_automations.sql',
        '2026_08_22_000014_email_marketing_advanced.sql',
        '2026_07_22_000023_create_wallet_system.sql',
        '2026_07_27_000035_create_wallet_recharge_cards.sql',
        '2026_08_12_000049_create_tax_rules_table.sql',
        '2026_07_22_000022_create_plan_pricing_display_table.sql',
        '2026_07_13_000001_create_jobs_table.sql',
        // Outreach / Competitor Intelligence / Ads attribution tables
        // (كلها idempotent - CREATE TABLE IF NOT EXISTS). مطلوبة لاختبارات
        // Outreach Discovery و Ads Attribution.
        '2026_08_08_000042_create_competitor_intelligence_tables.sql',
        '2026_08_08_000048_outreach_agent.sql',
        '2026_08_14_000048_create_ci_rate_limits.sql',
        '2026_08_15_000070_create_missing_base_tables.sql',
        '2026_08_15_000050_add_ads_autopilot_and_tracking_tables.sql',
        '2026_08_15_000060_add_ads_alerts.sql',
        '2026_08_28_000001_add_booking_ad_attribution.sql',
        '2026_08_28_000002_add_voided_commission_status.sql',
        '2026_08_28_000003_create_ad_creative_assets.sql',
        '2026_08_28_000004_create_ad_ab_tests.sql',
        '2026_08_28_000005_add_variant_performance_date.sql',
        '2026_08_28_000006_add_rule_alert_creative_types.sql',
        '2026_08_28_000007_create_ad_recommendations.sql',
        '2026_08_28_000008_add_stat_lead_scoring.sql',
        '2026_07_15_000014_create_revenue_intelligence_tables.sql',
        '2026_08_29_000001_add_product_dimension_to_rev_revenue_records.sql',
        '2026_08_29_000002_email_marketing_segment_and_automation_tracking.sql',
        '2026_08_29_000003_ci_keyword_rankings_product_prices_battlecards.sql',
        '2026_08_29_000004_seo_multi_crawl_rank_tracking_reports.sql',
        // Item 2 (2026-08-31): Backlink monitoring table (idempotent).
        '2026_08_31_000001_create_monitored_backlinks.sql',
        // Module 2 (2026-08-31): White-Label agency invitation table (idempotent).
        '2026_08_31_000002_agency_invitations.sql',
        // Module 3 (2026-08-31): إصلاح انحراف enum حالة النشر (published/publish_failed).
        '2026_08_31_000003_fix_ai_articles_publish_status.sql',
        // Phase 6 keyword intelligence columns (enriched_at/search_intent/...) —
        // تستخدمها KeywordResearchService (G4). غير idempotent لكن حلقة الميجريشن
        // بتتخطى ملف فاشل بأمان، فالإضافة آمنة للتشغيل المتكرر.
        '2026_08_08_000045_keyword_intelligence.sql',
        // Module 4 (2026-08-31): أعمدة توليد الفيديو في media_items
        // (aspect_ratio/duration_seconds/provider_ref/poll_attempts) — الطلب
        // الأول بيضيفها، ولو اتكرر بيتخطى بأمان (حلقة try/catch).
        '2026_08_07_000040_add_ai_video_generation_and_publishing.sql',
        // بند 1 (2026-09-01): webhook تتبع التسليم — delivery_webhook_enabled
        // + delivery_webhook_secret في email_smtp_settings.
        '2026_09_01_000001_email_delivery_webhook.sql',
        // بند 2 (2026-09-01): Double Opt-In — حالة pending_optin في الـ ENUM
        // + عمود optin_token في email_subscribers.
        '2026_09_01_000002_email_double_optin.sql',
    ];

    // إصلاح انحرافات السكيما المحلية: schema.sql القديمة بتحتوي أسماء
    // أعمدة قديمة (platform/sentiment_label/auto_reply_generated...) بينما
    // الكود الحالي بيستخدم source_platform/sentiment/ai_generated_reply...
    // والجداول الحديثة (notifications, subscription_plans...) مش موجودة
    // فيها. الملف idempotent (IF EXISTS/IF NOT EXISTS) - نتأكد إنه بيتطبق
    // دايماً بعد تحميل السكيما عشان الاختبارات والسيرفر الحي يتوافقوا.
    $divergenceFix = TOURFECTO_ROOT . '/database/fix_local_schema_divergences.sql';
    if (file_exists($divergenceFix)) {
        $sql = file_get_contents($divergenceFix);
        $queries = explode(';', $sql);
        foreach ($queries as $query) {
            // إزالة أسطر التعليقات (--) من بداية القطعة: القطعة الواحدة
            // ممكن تبدأ بتعليق وبعدها SQL حقيقي (مثل قسم websites اللي
            // فيه توثيق قبل ALTER). تجاهلها كلها كان بيسقط الـ ALTER
            // بالكامل لأن الشرط القديم كان يشترط ان القطعة كلها تعليق.
            $query = preg_replace('/^--.*?$/m', '', $query);
            $query = trim($query);
            if (empty($query)) {
                continue;
            }
            // كل query بـ try/catch مستقلة: فشل واحدة (مثلاً عمود مش موجود
            // لسه) مينفعش يوقف باقي الإصلاحات اللي بعده.
            try {
                $db->query($query);
            } catch (Exception $e) {
                echo "⚠️ Divergence fix step skipped: " . $e->getMessage() . "\n";
            }
        }
        echo "✅ Local schema divergence fix applied\n";
    }

    $migrationsDir = TOURFECTO_ROOT . '/database/migrations';

    foreach ($migrations as $file) {
        $path = $migrationsDir . '/' . $file;
        if (!file_exists($path)) {
            continue;
        }
        try {
            $sql = file_get_contents($path);
            $queries = explode(';', $sql);
            foreach ($queries as $query) {
                $query = trim($query);
                if (empty($query)) {
                    continue;
                }
                $db->query($query);
            }
        } catch (Exception $e) {
            echo "⚠️ Migration {$file} skipped: " . $e->getMessage() . "\n";
        }
    }

    echo "✅ Test migrations applied successfully\n";
}

// ============================================
// 14. تحميل بيانات الاختبار الافتراضية
// ============================================
function loadTestFixtures(): void
{
    try {
        $loader = new FixtureLoader();
        $loader->loadAll(true);
        echo "✅ Test fixtures loaded successfully\n";

    } catch (Exception $e) {
        echo "❌ Failed to load fixtures: " . $e->getMessage() . "\n";
    }
}

// ============================================
// 15. دوال مساعدة للاختبارات
// ============================================

/**
 * إنشاء مستخدم اختباري
 * @param array $overrides - بيانات مخصصة
 * @return int
 */
function createTestUser(array $overrides = []): int
{
    $db = Database::getInstance();

    $defaults = [
        'company_name' => 'Test Company',
        'email' => 'test_' . uniqid() . '@example.com',
        'password_hash' => password_hash('Test@123', PASSWORD_ARGON2ID),
        'phone' => '+966500000001',
        'country' => 'SA',
        'language' => 'ar',
        'timezone' => 'Asia/Riyadh',
        'role' => 'user',
        'is_active' => 1,
        'email_verified' => 1
    ];

    $data = array_merge($defaults, $overrides);

    $sql = "INSERT INTO users (
        company_name, email, password_hash, phone, country, language,
        timezone, role, is_active, email_verified
    ) VALUES (
        :company_name, :email, :password_hash, :phone, :country, :language,
        :timezone, :role, :is_active, :email_verified
    )";

    return (int) $db->query($sql, $data);
}

/**
 * إنشاء موقع اختباري
 * @param int $userId
 * @param array $overrides
 * @return int
 */
function createTestWebsite(int $userId, array $overrides = []): int
{
    $db = Database::getInstance();

    $defaults = [
        'user_id' => $userId,
        'main_url' => 'https://test-' . uniqid() . '.com',
        'company_name' => 'Test Travel Agency',
        'industry' => 'tourism',
        'target_language' => 'ar',
        'target_country' => 'SA',
        'is_verified' => 1
    ];

    $data = array_merge($defaults, $overrides);

    $sql = "INSERT INTO websites (
        user_id, main_url, company_name, industry, target_language,
        target_country, is_verified
    ) VALUES (
        :user_id, :main_url, :company_name, :industry, :target_language,
        :target_country, :is_verified
    )";

    return (int) $db->query($sql, $data);
}

/**
 * إنشاء اشتراك اختباري
 * @param int $userId
 * @param string $plan
 * @param array $overrides
 * @return int
 */
function createTestSubscription(int $userId, string $plan = 'starter', array $overrides = []): int
{
    $db = Database::getInstance();

    $plans = [
        'starter' => ['ai_credits' => 50, 'chat_credits' => 100, 'review_credits' => 10, 'competitor_limit' => 5, 'price' => 49.00],
        'professional' => ['ai_credits' => 200, 'chat_credits' => 500, 'review_credits' => 50, 'competitor_limit' => 20, 'price' => 99.00],
        'enterprise' => ['ai_credits' => 1000, 'chat_credits' => 2000, 'review_credits' => 200, 'competitor_limit' => 100, 'price' => 299.00]
    ];

    $planData = $plans[$plan] ?? $plans['starter'];

    $defaults = [
        'user_id' => $userId,
        'plan_name' => $plan,
        'plan_type' => 'monthly',
        'status' => 'active',
        'price' => $planData['price'],
        'currency' => 'USD',
        'ai_credits' => $planData['ai_credits'],
        'ai_credits_used' => 0,
        'chat_credits' => $planData['chat_credits'],
        'chat_credits_used' => 0,
        'review_credits' => $planData['review_credits'],
        'review_credits_used' => 0,
        'competitor_analysis_limit' => $planData['competitor_limit'],
        'competitor_analysis_used' => 0,
        'auto_pilot' => 0,
        'start_date' => date('Y-m-d H:i:s'),
        'expiry_date' => date('Y-m-d H:i:s', strtotime('+1 month'))
    ];

    $data = array_merge($defaults, $overrides);

    $sql = "INSERT INTO subscriptions (
        user_id, plan_name, plan_type, status, price, currency,
        ai_credits, ai_credits_used, chat_credits, chat_credits_used,
        review_credits, review_credits_used, competitor_analysis_limit,
        competitor_analysis_used, auto_pilot, start_date, expiry_date
    ) VALUES (
        :user_id, :plan_name, :plan_type, :status, :price, :currency,
        :ai_credits, :ai_credits_used, :chat_credits, :chat_credits_used,
        :review_credits, :review_credits_used, :competitor_analysis_limit,
        :competitor_analysis_used, :auto_pilot, :start_date, :expiry_date
    )";

    return (int) $db->query($sql, $data);
}

/**
 * تنظيف بيانات الاختبار
 * @param string $table
 * @param string $condition
 */
function cleanTestData(string $table, string $condition = '1=1'): void
{
    $db = Database::getInstance();
    $sql = "DELETE FROM {$table} WHERE {$condition}";
    $db->query($sql);
}

/**
 * الحصول على وقت التنفيذ
 * @return float
 */
function getExecutionTime(): float
{
    static $startTime;
    if ($startTime === null) {
        $startTime = microtime(true);
        return 0;
    }
    $endTime = microtime(true);
    $duration = $endTime - $startTime;
    $startTime = null;
    return $duration;
}

/**
 * تنسيق وقت التنفيذ
 * @param float $time
 * @return string
 */
function formatExecutionTime(float $time): string
{
    if ($time < 1) {
        return round($time * 1000, 2) . 'ms';
    }
    return round($time, 2) . 's';
}

/**
 * إنشاء Request محاكي
 * @param string $method
 * @param string $uri
 * @param array $data
 * @param array $headers
 * @return array
 */
function createMockRequest(string $method, string $uri, array $data = [], array $headers = []): array
{
    return [
        'method' => $method,
        'uri' => $uri,
        'data' => $data,
        'headers' => array_merge([
            'Content-Type' => 'application/json',
            'Accept' => 'application/json'
        ], $headers),
        'ip' => '127.0.0.1',
        'user_agent' => 'PHPUnit Test'
    ];
}

/**
 * محاكاة الاستجابة
 * @param array $data
 * @param int $statusCode
 * @return array
 */
function createMockResponse(array $data = [], int $statusCode = 200): array
{
    return [
        'success' => $statusCode >= 200 && $statusCode < 300,
        'data' => $data,
        'status_code' => $statusCode,
        'headers' => ['Content-Type' => 'application/json']
    ];
}

// ============================================
// 16. تشغيل تهيئة الاختبار
// ============================================
echo "\n🧪 Tourfecto Test Bootstrap\n";
echo "============================\n\n";

// إعداد قاعدة البيانات
setupTestDatabase();
loadDatabaseSchema();
applyTestMigrations();
loadTestFixtures();

echo "\n✅ Bootstrap completed successfully!\n";
echo "📝 " . date('Y-m-d H:i:s') . "\n\n";

// ============================================
// 17. تعريف ثوابت إضافية للاختبار
// ============================================
define('TEST_USER_ID', 1);
define('TEST_WEBSITE_ID', 1);
define('TEST_SUBSCRIPTION_ID', 1);
