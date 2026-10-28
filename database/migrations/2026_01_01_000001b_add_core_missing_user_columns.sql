-- ============================================================
-- Tourfecto - Migration: أعمدة أساسية ناقصة من users
-- @version 1.0.0  @date 2026-01-01 (تصحيح بأثر رجعي)
--
-- 🐛 باگ حقيقي اتلقى أثناء اختبار تسجيل حساب عميل جديد على تنصيب فاضي:
-- app/Models/User.php فيه تعليق صريح بيقول إن بنية users الحقيقية على
-- السيرفر (المتحقق منها من phpMyAdmin) فيها first_name/last_name/status/
-- email_verified_at/country_code - لكن أي واحدة فيهم مش موجودة في
-- 2026_01_01_000001_create_users_table.sql. النتيجة: AuthController::register()
-- (اللي بيكتب first_name/last_name/status/country_code) وAuthController::login()
-- (اللي بيقرا status) كانوا هيفشلوا فورًا بـ "Unknown column" على أي
-- تنصيب جديد للمشروع.
--
-- بنضيفهم هنا كأعمدة إضافية (من غير ما نمسح is_active/email_verified/
-- country القديمة - تسيبهم موجودين لأي كود قديم لسه بيعتمد عليهم) عشان
-- نفس الحقول اللي User.php الفعلي بيستخدمها تبقى موجودة، من غير ما نخاطر
-- بكسر أي migration تانية بترجع لأعمدة الجدول الأصلية بالاسم القديم.
-- ============================================================

SET @__fx_col_1_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'first_name'
);
SET @__fx_col_1_sql := IF(@__fx_col_1_exists = 0,
    'ALTER TABLE `users` ADD COLUMN `first_name` VARCHAR(100) DEFAULT NULL COMMENT ''الاسم الأول'' AFTER `company_name`',
    'SELECT 1');
PREPARE __fx_col_1_stmt FROM @__fx_col_1_sql;
EXECUTE __fx_col_1_stmt;
DEALLOCATE PREPARE __fx_col_1_stmt;

SET @__fx_col_2_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'last_name'
);
SET @__fx_col_2_sql := IF(@__fx_col_2_exists = 0,
    'ALTER TABLE `users` ADD COLUMN `last_name` VARCHAR(100) DEFAULT NULL COMMENT ''الاسم الأخير'' AFTER `first_name`',
    'SELECT 1');
PREPARE __fx_col_2_stmt FROM @__fx_col_2_sql;
EXECUTE __fx_col_2_stmt;
DEALLOCATE PREPARE __fx_col_2_stmt;

SET @__fx_col_3_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'country_code'
);
SET @__fx_col_3_sql := IF(@__fx_col_3_exists = 0,
    'ALTER TABLE `users` ADD COLUMN `country_code` VARCHAR(10) DEFAULT NULL COMMENT ''كود الدولة (ISO) - يُستخدم بدل country في الكود الحالي'' AFTER `country`',
    'SELECT 1');
PREPARE __fx_col_3_stmt FROM @__fx_col_3_sql;
EXECUTE __fx_col_3_stmt;
DEALLOCATE PREPARE __fx_col_3_stmt;

SET @__fx_col_4_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'status'
);
SET @__fx_col_4_sql := IF(@__fx_col_4_exists = 0,
    'ALTER TABLE `users` ADD COLUMN `status` ENUM(''active'',''suspended'',''cancelled'',''pending'') NOT NULL DEFAULT ''active'' COMMENT ''حالة الحساب - يُستخدم بدل is_active في الكود الحالي'' AFTER `is_active`',
    'SELECT 1');
PREPARE __fx_col_4_stmt FROM @__fx_col_4_sql;
EXECUTE __fx_col_4_stmt;
DEALLOCATE PREPARE __fx_col_4_stmt;

SET @__fx_col_5_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'email_verified_at'
);
SET @__fx_col_5_sql := IF(@__fx_col_5_exists = 0,
    'ALTER TABLE `users` ADD COLUMN `email_verified_at` TIMESTAMP NULL DEFAULT NULL COMMENT ''وقت التحقق من البريد - يُستخدم بدل email_verified في الكود الحالي'' AFTER `email_verified`',
    'SELECT 1');
PREPARE __fx_col_5_stmt FROM @__fx_col_5_sql;
EXECUTE __fx_col_5_stmt;
DEALLOCATE PREPARE __fx_col_5_stmt;

-- مزامنة القيم القديمة الافتراضية مع الأعمدة الجديدة - لكن بس لو العمود
-- ده اتضاف دلوقتي فعلاً (يعني كان مش موجود قبل كده). لو العمود أصلاً
-- موجود على السيرفر بقيم حقيقية صحيحة (زي ما هو متوقع على production)،
-- من الخطر إننا نلمسها بجملة UPDATE عامة كده - ممكن تغيّر حالة حساب
-- مستخدم حقيقي غلط.
SET @__fx_sync_sql := IF(@__fx_col_4_exists = 0,
    'UPDATE `users` SET `status` = IF(`is_active` = 1, ''active'', ''suspended'') WHERE `status` = ''active''',
    'SELECT 1');
PREPARE __fx_sync_stmt FROM @__fx_sync_sql;
EXECUTE __fx_sync_stmt;
DEALLOCATE PREPARE __fx_sync_stmt;

SET @__fx_sync_sql2 := IF(@__fx_col_5_exists = 0,
    'UPDATE `users` SET `email_verified_at` = `created_at` WHERE `email_verified` = 1 AND `email_verified_at` IS NULL',
    'SELECT 1');
PREPARE __fx_sync_stmt2 FROM @__fx_sync_sql2;
EXECUTE __fx_sync_stmt2;
DEALLOCATE PREPARE __fx_sync_stmt2;
