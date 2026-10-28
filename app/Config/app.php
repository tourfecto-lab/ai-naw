<?php

/**
 * Tourfecto - تكوين التطبيق الأساسي
 * @version 1.0.0
 * @author Tourfecto Team
 * @copyright 2026 Tourfecto
 */

// ============================================
// بيئة التطبيق
// ============================================
define('APP_NAME', 'Tourfecto');

// رقم واتساب صاحب المنصة نفسه (مش رقم بوت العميل) - يُستخدم في صفحة
// الباقات العامة لتوجيه العملاء المهتمين للتواصل والدفع يدويًا، بما إن
// بوابة الدفع الإلكتروني مش مفعّلة بعد. بصيغة دولية بدون + أو مسافات
// (مثال: 201001234567)
define('SUPPORT_WHATSAPP_NUMBER', env('SUPPORT_WHATSAPP_NUMBER') ?: '');
define('APP_VERSION', '1.0.0');
define('APP_ENV', env('APP_ENV') ?: 'production'); // production, staging, development
define('APP_DEBUG', filter_var(env('APP_DEBUG') ?: false, FILTER_VALIDATE_BOOLEAN));
define('APP_URL', env('APP_URL') ?: 'https://tourfecto.com');

// ============================================
// SEO Proxy (Server-Side Edge / CNAME)
// الهوست اللي العميل يشير عليه CNAME من subdomain بتاعه عشان التنفيذ
// التلقائي يشتغل server-side. في الإنتاج يبقى هوست مخصص (wildcard TLS)،
// وفي بيئات المعاينة بيفضل فارغ فنعتمد على HTTP_HOST الحالي وقت العرض.
// ============================================
define('SEO_PROXY_HOST', env('SEO_PROXY_HOST') ?: '');
define('APP_TIMEZONE', env('APP_TIMEZONE') ?: 'UTC');
define('APP_LOCALE', env('APP_LOCALE') ?: 'ar');
define('APP_FALLBACK_LOCALE', 'en');

// ============================================
// إعدادات الوقت والتاريخ
// ============================================
date_default_timezone_set(APP_TIMEZONE);
setlocale(LC_ALL, APP_LOCALE . '_' . strtoupper(APP_LOCALE) . '.utf8');

// ============================================
// إعدادات الأخطاء والتسجيل
// ============================================
if (APP_DEBUG) {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
} else {
    error_reporting(E_ALL & ~E_DEPRECATED); // E_STRICT اتلغى في PHP 8.4 واستخدامه بيطبع Deprecated في كل صفحة
    ini_set('display_errors', 0);
    ini_set('display_startup_errors', 0);
}

ini_set('log_errors', 1);
ini_set('error_log', TOURFECTO_STORAGE . '/logs/php_errors.log');

// ============================================
// إعدادات الذاكرة والتنفيذ
// ============================================
ini_set('memory_limit', '256M');
ini_set('max_execution_time', 300);
ini_set('max_input_time', 300);
ini_set('upload_max_filesize', '20M');
ini_set('post_max_size', '20M');
ini_set('max_file_uploads', 20);

// ============================================
// إعدادات الترميز
// ============================================
ini_set('default_charset', 'UTF-8');
mb_internal_encoding('UTF-8');
mb_http_output('UTF-8');
mb_language('uni');

// ============================================
// إعدادات الجلسات
// ============================================
define('SESSION_LIFETIME', env('SESSION_LIFETIME') ?: 3600);

ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_secure', 1);
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.use_only_cookies', 1);
// بيانات الجلسة (ومنها توكن الحماية CSRF) لازم تعيش قد جلسة الدخول - كانت بتتمسح بعد ساعة فالفورمز تفشل
ini_set('session.gc_maxlifetime', max((int) SESSION_LIFETIME, 3 * 86400));
ini_set('session.save_path', TOURFECTO_STORAGE . '/sessions');

// ============================================
// إعدادات الأمان
// ============================================
define('CSRF_TOKEN_LENGTH', 32);
define('CSRF_TOKEN_LIFETIME', 3600); // ثانية
// Argon2id لو متاح في PHP بتاع السيرفر، وإلا bcrypt (بدل Fatal Error)
define('PASSWORD_HASH_ALGO', defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT);
define('PASSWORD_HASH_OPTIONS', defined('PASSWORD_ARGON2ID') ? [
    'memory_cost' => 65536,
    'time_cost' => 4,
    'threads' => 1
] : ['cost' => 12]);

// ============================================
// إعدادات البريد الإلكتروني
// ============================================
define('MAIL_DRIVER', env('MAIL_DRIVER') ?: 'smtp');
define('MAIL_HOST', env('MAIL_HOST') ?: 'smtp.gmail.com');
define('MAIL_PORT', env('MAIL_PORT') ?: 587);
define('MAIL_USERNAME', env('MAIL_USERNAME') ?: '');
define('MAIL_PASSWORD', env('MAIL_PASSWORD') ?: '');
define('MAIL_ENCRYPTION', env('MAIL_ENCRYPTION') ?: 'tls');
define('MAIL_FROM_ADDRESS', env('MAIL_FROM_ADDRESS') ?: 'noreply@tourfecto.com');
define('MAIL_FROM_NAME', env('MAIL_FROM_NAME') ?: 'Tourfecto');

// ============================================
// إعدادات تحديد معدل الطلبات (Rate Limiting)
// ============================================
define('RATE_LIMIT_ENABLED', filter_var(env('RATE_LIMIT_ENABLED', true), FILTER_VALIDATE_BOOLEAN)); // env() بيرجّع false فعلي لـ "false"، فـ ?: true كان بيمنع الإيقاف
define('RATE_LIMIT_REQUESTS', (int) (env('RATE_LIMIT_REQUESTS') ?: 100));
define('RATE_LIMIT_WINDOW', (int) (env('RATE_LIMIT_WINDOW') ?: 60));

// ============================================
// إعدادات التطبيقات المتقدمة
// ============================================
define('ALLOWED_ORIGINS', [
    'https://app.tourfecto.com',
    'https://www.tourfecto.com',
    'https://tourfecto.com',
    'http://localhost:3000',
    'http://localhost:8080'
]);

define('CORS_ALLOWED_METHODS', [
    'GET', 'POST', 'PUT', 'DELETE', 'OPTIONS', 'PATCH'
]);

define('CORS_ALLOWED_HEADERS', [
    'Content-Type',
    'Authorization',
    'X-Requested-With',
    'Accept',
    'Origin',
    'X-CSRF-TOKEN'
]);

// ============================================
// إعدادات التخزين المؤقت
// ============================================
define('CACHE_ENABLED', true);
define('CACHE_DRIVER', 'file'); // file, redis, memcached
define('CACHE_LIFETIME', 3600); // ثانية
define('CACHE_PREFIX', 'tourfecto_');
define('CACHE_DURATION_DAYS', (int) (env('CACHE_DURATION_DAYS') ?: 7)); // مدة كاش الذكاء الاصطناعي بالأيام

// ============================================
// إعدادات التطبيقات الخارجية
// ============================================
define('GOOGLE_ANALYTICS_ID', env('GOOGLE_ANALYTICS_ID') ?: '');
define('GOOGLE_MAPS_API_KEY', env('GOOGLE_MAPS_API_KEY') ?: '');
define('SENTRY_DSN', env('SENTRY_DSN') ?: '');

// ============================================
// إعدادات التحميل والموارد
// ============================================
define('MAX_IMAGE_SIZE', 5 * 1024 * 1024); // 5MB
define('ALLOWED_IMAGE_TYPES', [
    'image/jpeg',
    'image/png',
    'image/gif',
    'image/webp',
    'image/svg+xml'
]);

// ============================================
// إعدادات التقارير والإشعارات
// ============================================
define('REPORT_EXPIRY_DAYS', 30);
define('REPORT_CACHE_DAYS', 7);
define('MAX_REPORTS_PER_USER', 1000);
define('MAX_COMPETITORS', 5);

// ============================================
// إعدادات العملة
// ============================================
define('DEFAULT_CURRENCY', 'USD');
define('CURRENCY_SYMBOLS', [
    'USD' => '$',
    'EUR' => '€',
    'GBP' => '£',
    'EGP' => 'E£',
    'SAR' => '﷼',
    'AED' => 'د.إ',
    'KWD' => 'د.ك',
    'BHD' => 'د.ب'
]);

// ============================================
// إعدادات المناطق الزمنية للمستخدمين
// ============================================
define('SUPPORTED_TIMEZONES', [
    'UTC',
    'Asia/Riyadh',
    'Asia/Dubai',
    'Africa/Cairo',
    'Europe/London',
    'Europe/Paris',
    'America/New_York',
    'America/Los_Angeles'
]);

// ============================================
// إعدادات اللغات المدعومة
// ============================================
define('SUPPORTED_LANGUAGES', [
    'ar' => 'العربية',
    'en' => 'English',
    'fr' => 'Français',
    'de' => 'Deutsch',
    'es' => 'Español',
    'it' => 'Italiano',
    'pt' => 'Português',
    'ru' => 'Русский',
    'zh' => '中文',
    'ja' => '日本語'
]);
