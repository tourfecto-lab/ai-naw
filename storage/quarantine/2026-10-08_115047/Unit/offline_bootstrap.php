<?php

/**
 * Tourfecto - Offline Test Bootstrap
 * تهيئة خفيفة للاختبارات التي لا تتطلب قاعدة بيانات (pure logic) -
 * تتعامل مع دالة env() المستخدمة في ملفات التكوين بدون تحميل MySQL.
 * @version 1.0.0
 * @date 2026-08-17
 */

define('TOURFECTO_ROOT', dirname(__DIR__, 2));
define('TOURFECTO_APP', TOURFECTO_ROOT . '/app');
define('TOURFECTO_STORAGE', TOURFECTO_ROOT . '/storage');
define('TOURFECTO_TESTS', dirname(__DIR__));
define('TOURFECTO_TEST_ENV', 'testing');

// دالة env() محايدة - الاختبارات التي لا تحتاج DB لا تقرأ تكوين MySQL
if (!function_exists('env')) {
    function env(string $key, $default = null)
    {
        static $values = [
            'APP_ENV' => 'testing',
            'APP_DEBUG' => 'true',
            'APP_URL' => 'http://localhost:8000',
        ];
        if (isset($values[$key])) {
            return $values[$key];
        }
        $real = getenv($key);
        return $real === false ? $default : $real;
    }
}

// إنشاء مجلدات التخزين إن لزم
foreach ([TOURFECTO_STORAGE . '/logs', TOURFECTO_STORAGE . '/cache/test'] as $dir) {
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
}
