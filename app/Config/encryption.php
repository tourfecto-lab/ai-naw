<?php

/**
 * Tourfecto - تكوين التشفير والأمان
 * @version 1.0.0
 * @author Tourfecto Team
 * @copyright 2026 Tourfecto
 */

// ============================================
// إعدادات التشفير الأساسية
// ============================================
define('ENCRYPTION_ENABLED', true);
define('ENCRYPTION_CIPHER', 'AES-256-CBC');
// تصحيح مؤكد من سجل الأخطاء الفعلي: ".env.example" (والمفروض .env الحقيقي
// بنفس الصيغة) بيكتب المفتاح بصيغة "base64:xxxxx" (زي Laravel APP_KEY
// بالظبط)، لكن أكتر من كلاس في المشروع (Encryption.php، DataEncryption.php)
// كان بيعمل base64_decode() على القيمة *شاملة* كلمة "base64:" نفسها، مش
// بس الجزء المُرمّز. النتيجة: مفتاح فك تشفير بطول غلط دايمًا، وفشل تسجيل
// الدخول بالكامل ("Encryption key must be 32 bytes long"). بنشيل الـ
// prefix هنا مرة واحدة، عشان أي كود تاني يستخدم ENCRYPTION_KEY ياخده صح
// تلقائيًا من غير ما يحتاج يتذكر يشيل الـ prefix بنفسه.
$rawEncryptionKey = env('ENCRYPTION_KEY') ?: '';
if (strpos($rawEncryptionKey, 'base64:') === 0) {
    $rawEncryptionKey = substr($rawEncryptionKey, 7);
}
define('ENCRYPTION_KEY', $rawEncryptionKey);
define('ENCRYPTION_METHOD', 'openssl'); // openssl, sodium, mcrypt
define('ENCRYPTION_HASH_ALGO', 'sha256');

// ============================================
// مفتاح توقيع JWT (مصادقة Token للموبايل/الـ SPA)
// المرحلة 2 من خطة API Gateway - 2026-08-06
// ============================================
// لازم يكون مفتاح منفصل عن ENCRYPTION_KEY (مبدأ أساسي في الأمان: مفتاح
// توقيع التوكنات ومفتاح تشفير البيانات مايبقوش نفس المفتاح، عشان لو
// واحد فيهم اتسرب، التاني يفضل آمن). لو JWT_SECRET مش موجود في .env
// (ترقية من نسخة قديمة من غيره)، بنشتقّه من ENCRYPTION_KEY بدل ما نوقف
// الموقع بالكامل - لكن الأفضل تضيف JWT_SECRET مستقل في .env بأسرع وقت.
$rawJwtSecret = env('JWT_SECRET') ?: '';
if ($rawJwtSecret === '' && $rawEncryptionKey !== '') {
    $rawJwtSecret = hash_hmac('sha256', 'tourfecto_jwt_derived', $rawEncryptionKey);
}
define('JWT_SECRET', $rawJwtSecret);
define('JWT_ACCESS_TOKEN_TTL', (int) (env('JWT_ACCESS_TOKEN_TTL') ?: 900));       // 15 دقيقة
define('JWT_REFRESH_TOKEN_TTL', (int) (env('JWT_REFRESH_TOKEN_TTL') ?: 2592000)); // 30 يوم

// ============================================
// إعدادات التجزئة (Hashing)
// ============================================
define('HASH_ALGO', 'sha256');
define('HASH_ITERATIONS', 100000);
define('HASH_SALT_LENGTH', 32);
define('HASH_USE_PBKDF2', true);

// ============================================
// إعدادات تشفير البيانات الحساسة
// ============================================
define('ENCRYPT_SENSITIVE_FIELDS', [
    'password',
    'credit_card',
    'cvv',
    'ssn',
    'passport_number',
    'national_id',
    'phone',
    'email',
    'address',
    'api_key',
    'access_token'
]);

// ============================================
// إعدادات GDPR Compliance
// ============================================
define('GDPR_ENABLED', true);
define('GDPR_DATA_RETENTION_DAYS', 365);
define('GDPR_ANONYMIZE_DATA', true);
define('GDPR_DATA_ENCRYPTION', true);
define('GDPR_LOGGING_ENABLED', true);

// ============================================
// إعدادات التوقيع الرقمي
// ============================================
define('SIGNATURE_ENABLED', true);
define('SIGNATURE_ALGO', 'SHA256withRSA');
define('SIGNATURE_PRIVATE_KEY', env('SIGNATURE_PRIVATE_KEY') ?: '');
define('SIGNATURE_PUBLIC_KEY', env('SIGNATURE_PUBLIC_KEY') ?: '');

// ============================================
// إعدادات SSL/TLS
// ============================================
define('SSL_VERIFY_PEER', true);
define('SSL_VERIFY_HOST', true);
define('SSL_CIPHER_LIST', 'ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384');

// ============================================
// إعدادات المفاتيح
// ============================================
define('KEY_ROTATION_ENABLED', true);
define('KEY_ROTATION_INTERVAL', 90); // أيام
define('KEY_BACKUP_ENABLED', true);
define('KEY_BACKUP_PATH', TOURFECTO_STORAGE . '/backups/keys');

// ============================================
// إعدادات التشفير المخصص
// ============================================
define('CUSTOM_ENCRYPTION', [
    'user_phone' => [
        'algorithm' => 'AES-256-CBC',
        'salt' => 'user_phone_salt'
    ],
    'user_email' => [
        'algorithm' => 'AES-256-CBC',
        'salt' => 'user_email_salt'
    ],
    'chat_messages' => [
        'algorithm' => 'AES-256-CBC',
        'salt' => 'chat_message_salt'
    ],
    'api_keys' => [
        'algorithm' => 'AES-256-CBC',
        'salt' => 'api_key_salt'
    ]
]);

// ============================================
// إعدادات التحقق من سلامة البيانات
// ============================================
define('DATA_INTEGRITY_CHECK', true);
define('HMAC_ALGO', 'sha256');
define('HMAC_KEY', env('HMAC_KEY') ?: '');
define('CHECKSUM_ENABLED', true);
