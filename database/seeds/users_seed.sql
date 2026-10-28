-- ============================================
-- Tourfecto - Seed: Users Table
-- إدخال بيانات المستخدمين الافتراضية
-- @version 1.0.0
-- @author Tourfecto Team
-- @copyright 2026 Tourfecto
-- ============================================

-- ============================================
-- 1. إدخال المستخدمين الأساسيين
-- ============================================

-- المدير العام (Super Admin)
INSERT INTO `users` (
    `company_name`,
    `email`,
    `password_hash`,
    `phone`,
    `country`,
    `language`,
    `timezone`,
    `role`,
    `is_active`,
    `email_verified`,
    `api_token`,
    `token_expiry`,
    `last_activity`,
    `created_at`
) VALUES (
    'Tourfecto Admin',
    'admin@tourfecto.com',
    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', -- password: password
    '+966500000001',
    'SA',
    'ar',
    'Asia/Riyadh',
    'super_admin',
    1,
    1,
    'admin_token_' . MD5(RAND()),
    DATE_ADD(NOW(), INTERVAL 365 DAY),
    NOW(),
    NOW()
);

-- مدير النظام
INSERT INTO `users` (
    `company_name`,
    `email`,
    `password_hash`,
    `phone`,
    `country`,
    `language`,
    `timezone`,
    `role`,
    `is_active`,
    `email_verified`,
    `api_token`,
    `token_expiry`,
    `created_at`
) VALUES (
    'Tourfecto System',
    'system@tourfecto.com',
    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
    '+966500000002',
    'SA',
    'ar',
    'Asia/Riyadh',
    'admin',
    1,
    1,
    'system_token_' . MD5(RAND()),
    DATE_ADD(NOW(), INTERVAL 365 DAY),
    NOW()
);

-- مستخدم تجريبي - شركة سياحة (الباقة الاحترافية)
INSERT INTO `users` (
    `company_name`,
    `email`,
    `password_hash`,
    `phone`,
    `country`,
    `language`,
    `timezone`,
    `role`,
    `is_active`,
    `email_verified`,
    `api_token`,
    `token_expiry`,
    `created_at`
) VALUES (
    'العربي للسياحة',
    'info@arabic-travel.com',
    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
    '+966500000003',
    'SA',
    'ar',
    'Asia/Riyadh',
    'manager',
    1,
    1,
    'arabic_token_' . MD5(RAND()),
    DATE_ADD(NOW(), INTERVAL 365 DAY),
    NOW()
);

-- مستخدم تجريبي - شركة سياحة (الباقة الأساسية)
INSERT INTO `users` (
    `company_name`,
    `email`,
    `password_hash`,
    `phone`,
    `country`,
    `language`,
    `timezone`,
    `role`,
    `is_active`,
    `email_verified`,
    `api_token`,
    `token_expiry`,
    `created_at`
) VALUES (
    'مصر للسياحة',
    'info@egypt-travel.com',
    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
    '+201000000001',
    'EG',
    'ar',
    'Africa/Cairo',
    'agent',
    1,
    1,
    'egypt_token_' . MD5(RAND()),
    DATE_ADD(NOW(), INTERVAL 365 DAY),
    NOW()
);

-- مستخدم تجريبي - شركة سياحة أوروبية (الباقة المؤسسية)
INSERT INTO `users` (
    `company_name`,
    `email`,
    `password_hash`,
    `phone`,
    `country`,
    `language`,
    `timezone`,
    `role`,
    `is_active`,
    `email_verified`,
    `api_token`,
    `token_expiry`,
    `created_at`
) VALUES (
    'European Travel Group',
    'info@europe-travel.com',
    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
    '+442000000001',
    'GB',
    'en',
    'Europe/London',
    'manager',
    1,
    1,
    'europe_token_' . MD5(RAND()),
    DATE_ADD(NOW(), INTERVAL 365 DAY),
    NOW()
);

-- ============================================
-- 2. إدخال مستخدمين إضافيين للاختبار
-- ============================================

-- مستخدم غير مفعل
INSERT INTO `users` (
    `company_name`,
    `email`,
    `password_hash`,
    `phone`,
    `country`,
    `language`,
    `timezone`,
    `role`,
    `is_active`,
    `email_verified`,
    `created_at`
) VALUES (
    'شركة غير مفعلة',
    'inactive@test.com',
    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
    '+966500000004',
    'SA',
    'ar',
    'Asia/Riyadh',
    'user',
    0,
    0,
    NOW()
);

-- مستخدم مع بريد غير موثق
INSERT INTO `users` (
    `company_name`,
    `email`,
    `password_hash`,
    `phone`,
    `country`,
    `language`,
    `timezone`,
    `role`,
    `is_active`,
    `email_verified`,
    `created_at`
) VALUES (
    'شركة غير موثقة',
    'unverified@test.com',
    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
    '+966500000005',
    'SA',
    'ar',
    'Asia/Riyadh',
    'user',
    1,
    0,
    NOW()
);

-- ============================================
-- 3. تحديث المعرفات المتسلسلة
-- ============================================
ALTER TABLE `users` AUTO_INCREMENT = 1000;

-- ============================================
-- 4. إضافة بيانات إضافية للمستخدمين
-- ============================================

-- تحديث الشركات العربية
UPDATE `users` 
SET 
    `country` = 'SA',
    `timezone` = 'Asia/Riyadh',
    `last_activity` = NOW()
WHERE `email` LIKE '%@arabic-travel.com' OR `email` LIKE '%@egypt-travel.com';

-- تحديث الشركات الأوروبية
UPDATE `users` 
SET 
    `country` = 'GB',
    `timezone` = 'Europe/London',
    `language` = 'en',
    `last_activity` = NOW()
WHERE `email` LIKE '%@europe-travel.com';

-- ============================================
-- 5. إضافة بيانات الحقول الجديدة
-- ============================================

-- إضافة عدد محاولات تسجيل الدخول
UPDATE `users` SET `login_attempts` = 0 WHERE `login_attempts` IS NULL;
UPDATE `users` SET `blocked_until` = NULL WHERE `blocked_until` IS NOT NULL;

-- إضافة آخر تسجيل دخول
UPDATE `users` SET `last_login` = NOW() WHERE `id` <= 5;

-- ============================================
-- 6. ملاحظات
-- ============================================
-- كلمات المرور الافتراضية:
-- password (لجميع المستخدمين)
-- 
-- المستخدمون المتاحون للتسجيل:
-- admin@tourfecto.com / password
-- system@tourfecto.com / password
-- info@arabic-travel.com / password
-- info@egypt-travel.com / password
-- info@europe-travel.com / password
-- ============================================