-- Tourfecto - السماح بأكتر من صفحة فيسبوك/حساب إنستجرام لنفس الموقع (آمن للتشغيل أكتر من مرة)
-- المشكلة: القيد uniq_website_platform (website_id, platform) كان بيسمح بصف واحد بس لكل منصة.

SET @has := (SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'platform_connections' AND INDEX_NAME = 'uniq_website_platform');
SET @q := IF(@has > 0, 'ALTER TABLE `platform_connections` DROP INDEX `uniq_website_platform`', 'SELECT 1');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

-- فهرس عادي (مش فريد) بدل القديم عشان سرعة البحث
SET @has2 := (SELECT COUNT(*) FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'platform_connections' AND INDEX_NAME = 'idx_website_platform');
SET @q2 := IF(@has2 = 0, 'ALTER TABLE `platform_connections` ADD INDEX `idx_website_platform` (`website_id`, `platform`)', 'SELECT 1');
PREPARE s2 FROM @q2; EXECUTE s2; DEALLOCATE PREPARE s2;
