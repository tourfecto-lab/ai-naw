-- ============================================================
-- Tourfecto - Migration: مطابقة المخطط مع الكود (من تشغيل فعلي للمنصة)
-- @date 2026-10-03
--
-- الأخطاء دي ظهرت في storage/logs/app.log بعد تشغيل رحلة العميل كاملة على
-- قاعدة مبنية من الـ migrations. كل جملة additive وآمنة تتكرر (أخطاء
-- "عمود موجود" بيتجاهلها الـ runner).
-- ============================================================

-- 1) activity_logs: النسخة القديمة من schema.sql فيها event_type NOT NULL من غير
--    default، فـ ActivityLog::record (اللي بيكتب module/action) كان بيفشل دايمًا.
SET @__al_has_event := (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'activity_logs' AND COLUMN_NAME = 'event_type');
SET @__al_sql := IF(@__al_has_event > 0,
    'ALTER TABLE `activity_logs` MODIFY COLUMN `event_type` VARCHAR(100) NULL DEFAULT NULL',
    'SELECT 1');
PREPARE __al_stmt FROM @__al_sql;
EXECUTE __al_stmt;
DEALLOCATE PREPARE __al_stmt;

-- 2) websites: أعمدة Business Control Center الموجودة على الإنتاج (اتضافت من
--    phpMyAdmin) والكود بيقراها مباشرة (OnboardingController, CompetitorIntelligence,
--    AdminProController). Website model بيكتشفها تلقائيًا لو موجودة.
ALTER TABLE `websites` ADD COLUMN `domain` VARCHAR(255) DEFAULT NULL;
ALTER TABLE `websites` ADD COLUMN `brand_name` VARCHAR(255) DEFAULT NULL;
ALTER TABLE `websites` ADD COLUMN `industry_niche` VARCHAR(100) DEFAULT NULL;
ALTER TABLE `websites` ADD COLUMN `target_countries` VARCHAR(255) DEFAULT NULL;
ALTER TABLE `websites` ADD COLUMN `is_active` TINYINT(1) NOT NULL DEFAULT 1;
UPDATE `websites` SET `domain` = `main_url` WHERE `domain` IS NULL AND `main_url` IS NOT NULL;
UPDATE `websites` SET `brand_name` = `company_name` WHERE `brand_name` IS NULL AND `company_name` IS NOT NULL;
UPDATE `websites` SET `industry_niche` = `industry` WHERE `industry_niche` IS NULL AND `industry` IS NOT NULL;
UPDATE `websites` SET `target_countries` = `target_country` WHERE `target_countries` IS NULL AND `target_country` IS NOT NULL;

-- 3) Website Optimizer: 2026_07_15 عمل الجداول بأعمدة إجبارية، و 2026_08_20 (التعريف
--    الأحدث) خلاها اختيارية بـ defaults - بس CREATE IF NOT EXISTS ماكانش بيعدّل
--    جدول موجود. هنا بنطبّق التعريف الأحدث (تخفيف قيود بس، من غير مسح بيانات).
ALTER TABLE `wo_audit_findings`
    MODIFY COLUMN `check_key` VARCHAR(100) DEFAULT NULL,
    MODIFY COLUMN `status` VARCHAR(20) NOT NULL DEFAULT 'info',
    MODIFY COLUMN `severity` VARCHAR(20) NOT NULL DEFAULT 'medium',
    MODIFY COLUMN `message` TEXT DEFAULT NULL;
ALTER TABLE `wo_fixes` MODIFY COLUMN `fix_type` VARCHAR(30) DEFAULT NULL;
