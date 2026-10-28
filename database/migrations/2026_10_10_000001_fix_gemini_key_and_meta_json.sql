-- Tourfecto - إصلاح 2026-10-10 (آمن للتشغيل أكتر من مرة)

-- 1) مفتاح Gemini المحفوظ من لوحة الأدمن قيمة غلط (10 حروف) وكان بيتقدّم على مفتاح .env الصح.
--    بنفضّيه فالنظام يرجع يقرا GEMINI_API_KEY من .env.
UPDATE `system_settings` SET `setting_value` = '' WHERE `setting_key` = 'gemini_api_key';

-- 2) عمود meta_json ناقص من platform_connections (الكود بيكتب فيه صلاحيات Meta وبيانات واتساب)
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'platform_connections' AND COLUMN_NAME = 'meta_json');
SET @q := IF(@c = 0,
  'ALTER TABLE `platform_connections` ADD COLUMN `meta_json` LONGTEXT NULL AFTER `last_error`',
  'SELECT 1');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
