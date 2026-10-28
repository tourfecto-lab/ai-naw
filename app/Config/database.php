<?php

/**
 * Tourfecto - تكوين قاعدة البيانات
 * @version 1.0.0
 * @author Tourfecto Team
 * @copyright 2026 Tourfecto
 */

// ============================================
// إعدادات الاتصال بقاعدة البيانات
// ============================================
// ملاحظة أمان (2026-07-14): تمت إزالة القيم الافتراضية الحقيقية
// (اسم قاعدة بيانات/يوزر/باسورد) من الكود المصدري. كانت موجودة هنا
// كـ fallback صريح، وهو ثغرة أمنية لأن أي شخص يطّلع على الكود (نسخة
// احتياطية، أرشيف مرفوع، git) كان يرى بيانات الاتصال الحقيقية بقاعدة
// البيانات حتى لو ملف .env سليم. الاتصال الآن يعتمد حصريًا على .env؛
// إذا كانت القيم غير موجودة فيه سيفشل الاتصال بوضوح بدل الاتصال بقاعدة
// بيانات افتراضية مكشوفة القيم. يجب أيضًا تغيير كلمة السر الحقيقية في
// MySQL نفسه لأنها كانت مكشوفة في هذا الملف سابقًا.
define('DB_HOST', env('DB_HOST') ?: 'localhost');
define('DB_PORT', env('DB_PORT') ?: 3306);
define('DB_NAME', env('DB_NAME'));
define('DB_USER', env('DB_USER'));
define('DB_PASS', env('DB_PASS'));

if (!DB_NAME || !DB_USER) {
    throw new RuntimeException(
        'Database configuration missing: DB_NAME/DB_USER must be set in .env. '
        . 'Hardcoded fallback credentials were removed for security reasons on 2026-07-14.'
    );
}
if (!extension_loaded('pdo_mysql')) {
    throw new RuntimeException(
        'Tourfecto cannot start because the PHP PDO MySQL extension (pdo_mysql) is not enabled. ' .
        'Enable pdo_mysql on the production server and reload PHP-FPM/Apache.'
    );
}

define('DB_CHARSET', env('DB_CHARSET') ?: 'utf8mb4');
define('DB_COLLATION', env('DB_COLLATION') ?: 'utf8mb4_unicode_ci');
define('DB_PREFIX', env('DB_PREFIX') ?: 'tf_');

// ============================================
// إعدادات PDO المتقدمة
// ============================================
define('DB_OPTIONS', array_filter([
    // معالجة الأخطاء
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false, // منع SQL Injection
    PDO::ATTR_STRINGIFY_FETCHES => false,

    // الاتصال المستمر
    PDO::ATTR_PERSISTENT => false,

    // الترميز (pdo_mysql is required above, so these constants are guaranteed).
    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
    PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,

    // المهلة
    PDO::ATTR_TIMEOUT => 30,

    // SSL (للأمان) - تُضاف فقط لو محددة فعليًا في .env، عشان متفعلش SSL بالغلط
    PDO::MYSQL_ATTR_SSL_CA => env('DB_SSL_CA') ?: null,
    PDO::MYSQL_ATTR_SSL_CERT => env('DB_SSL_CERT') ?: null,
    PDO::MYSQL_ATTR_SSL_KEY => env('DB_SSL_KEY') ?: null,
], function ($value) {
    return $value !== null;
}));

// ============================================
// إعدادات النسخ الاحتياطي
// ============================================
define('DB_BACKUP_ENABLED', true);
define('DB_BACKUP_PATH', TOURFECTO_STORAGE . '/backups/database');
define('DB_BACKUP_INTERVAL', 86400); // 24 ساعة
define('DB_BACKUP_KEEP_DAYS', 30);
define('DB_BACKUP_COMPRESS', true);

// ============================================
// إعدادات أداء قاعدة البيانات
// ============================================
define('DB_QUERY_LOG_ENABLED', APP_DEBUG);
define('DB_SLOW_QUERY_THRESHOLD', 1.0); // ثانية
define('DB_MAX_QUERY_LOG', 100);
define('DB_PAGINATION_LIMIT', 50);
define('DB_MAX_JOIN_LIMIT', 10);

// ============================================
// إعدادات الاتصال بقواعد البيانات المتعددة
// ============================================
define('DB_CONNECTIONS', [
    'default' => [
        'host' => DB_HOST,
        'port' => DB_PORT,
        'database' => DB_NAME,
        'username' => DB_USER,
        'password' => DB_PASS,
        'charset' => DB_CHARSET,
        'collation' => DB_COLLATION
    ],
    'readonly' => [
        'host' => env('DB_READONLY_HOST') ?: DB_HOST,
        'port' => env('DB_READONLY_PORT') ?: DB_PORT,
        'database' => DB_NAME,
        'username' => env('DB_READONLY_USER') ?: DB_USER,
        'password' => env('DB_READONLY_PASS') ?: DB_PASS,
        'charset' => DB_CHARSET,
        'collation' => DB_COLLATION
    ]
]);

// ============================================
// إعدادات إعادة المحاولة
// ============================================
define('DB_MAX_RETRIES', 3);
define('DB_RETRY_DELAY', 1); // ثانية
