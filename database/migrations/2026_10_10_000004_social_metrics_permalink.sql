-- Tourfecto - رابط المنشور على المنصة (إنستجرام مثلًا) داخل كاش الإحصائيات - آمن للتشغيل أكتر من مرة
SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'social_post_metrics' AND COLUMN_NAME = 'permalink');
SET @q := IF(@has = 0, 'ALTER TABLE `social_post_metrics` ADD COLUMN `permalink` VARCHAR(500) DEFAULT NULL AFTER `reach`', 'SELECT 1');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
