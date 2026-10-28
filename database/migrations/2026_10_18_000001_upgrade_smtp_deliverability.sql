-- 2026_10_18_000001_upgrade_smtp_deliverability.sql
-- آمن للتشغيل أكتر من مرة: كل عمود بيتضاف لوحده لو مش موجود (enterprise_email_marketing_upgrade
-- بيضيف daily_limit قبله، فالـ ALTER المجمّع القديم كان بيفشل كله ومايضيفش ولا عمود).
SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'email_smtp_settings' AND COLUMN_NAME = 'daily_limit');
SET @q := IF(@has = 0, 'ALTER TABLE `email_smtp_settings` ADD COLUMN `daily_limit` INT UNSIGNED DEFAULT 500', 'SELECT 1');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'email_smtp_settings' AND COLUMN_NAME = 'sent_today');
SET @q := IF(@has = 0, 'ALTER TABLE `email_smtp_settings` ADD COLUMN `sent_today` INT UNSIGNED DEFAULT 0', 'SELECT 1');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'email_smtp_settings' AND COLUMN_NAME = 'warmup_enabled');
SET @q := IF(@has = 0, 'ALTER TABLE `email_smtp_settings` ADD COLUMN `warmup_enabled` TINYINT(1) DEFAULT 0', 'SELECT 1');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'email_smtp_settings' AND COLUMN_NAME = 'warmup_step_increment');
SET @q := IF(@has = 0, 'ALTER TABLE `email_smtp_settings` ADD COLUMN `warmup_step_increment` INT UNSIGNED DEFAULT 50', 'SELECT 1');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'email_smtp_settings' AND COLUMN_NAME = 'spf_verified');
SET @q := IF(@has = 0, 'ALTER TABLE `email_smtp_settings` ADD COLUMN `spf_verified` TINYINT(1) DEFAULT 0', 'SELECT 1');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'email_smtp_settings' AND COLUMN_NAME = 'dkim_verified');
SET @q := IF(@has = 0, 'ALTER TABLE `email_smtp_settings` ADD COLUMN `dkim_verified` TINYINT(1) DEFAULT 0', 'SELECT 1');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'email_smtp_settings' AND COLUMN_NAME = 'dmarc_verified');
SET @q := IF(@has = 0, 'ALTER TABLE `email_smtp_settings` ADD COLUMN `dmarc_verified` TINYINT(1) DEFAULT 0', 'SELECT 1');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'email_smtp_settings' AND COLUMN_NAME = 'health_score');
SET @q := IF(@has = 0, 'ALTER TABLE `email_smtp_settings` ADD COLUMN `health_score` TINYINT UNSIGNED DEFAULT 100', 'SELECT 1');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'email_smtp_settings' AND COLUMN_NAME = 'last_used_at');
SET @q := IF(@has = 0, 'ALTER TABLE `email_smtp_settings` ADD COLUMN `last_used_at` TIMESTAMP NULL DEFAULT NULL', 'SELECT 1');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
