<?php

/**
 * Tourfecto - Test Bootstrap
 * تهيئة بيئة الاختبار
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

// ============================================
// 2. تحميل ملف التكوين
// ============================================
require_once TOURFECTO_APP . '/Config/app.php';
require_once TOURFECTO_APP . '/Config/database.php';
require_once TOURFECTO_APP . '/Config/gemini.php';
require_once TOURFECTO_APP . '/Config/encryption.php';

// ============================================
// 3. تحميل الكلاسات الأساسية
// ============================================
require_once TOURFECTO_APP . '/Core/Database.php';
require_once TOURFECTO_APP . '/Core/Encryption.php';
require_once TOURFECTO_APP . '/Core/Logger.php';
require_once TOURFECTO_APP . '/Core/Validator.php';
require_once TOURFECTO_APP . '/Core/Cache.php';

// ============================================
// 4. تحميل خدمات التطبيق
// ============================================
require_once TOURFECTO_APP . '/Services/AI/TourfectoAIEngine.php';
require_once TOURFECTO_APP . '/Services/AI/GeminiClient.php';
require_once TOURFECTO_APP . '/Services/AI/PromptBuilder.php';
require_once TOURFECTO_APP . '/Services/AI/ResponseParser.php';
require_once TOURFECTO_APP . '/Services/AI/SemanticCache.php';

// ============================================
// 5. تحميل دوال المساعدة
// ============================================
require_once TOURFECTO_APP . '/Helpers/functions.php';
require_once TOURFECTO_APP . '/Helpers/validation.php';
require_once TOURFECTO_APP . '/Helpers/formatting.php';

// ============================================
// 6. إنشاء مجلدات الاختبار
// ============================================
$testDirs = [
    TOURFECTO_STORAGE . '/logs',
    TOURFECTO_STORAGE . '/cache/test',
    TOURFECTO_STORAGE . '/uploads/temp'
];

foreach ($testDirs as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
}

// ============================================
// 7. تحميل مكتبات الاختبار
// ============================================
// في حالة استخدام PHPUnit، سيتم تحميله تلقائياً

// ============================================
// 8. دوال مساعدة للاختبارات
// ============================================

/**
 * إنشاء مستخدم اختباري
 * @return array
 */
function createTestUser(): array
{
    return [
        'company_name' => 'Test Company',
        'email' => 'test_' . uniqid() . '@example.com',
        'password' => password_hash('Test@123', PASSWORD_ARGON2ID),
        'phone' => '+966500000001',
        'country' => 'SA',
        'language' => 'ar',
        'role' => 'user',
        'is_active' => 1
    ];
}

/**
 * إنشاء موقع اختباري
 * @param int $userId
 * @return array
 */
function createTestWebsite(int $userId): array
{
    return [
        'user_id' => $userId,
        'main_url' => 'https://test-' . uniqid() . '.com',
        'company_name' => 'Test Travel Agency',
        'industry' => 'tourism',
        'target_language' => 'ar',
        'target_country' => 'SA',
        'is_verified' => 1
    ];
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
