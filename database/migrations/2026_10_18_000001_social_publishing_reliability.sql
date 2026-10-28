-- Tourfecto - موثوقية النشر الاجتماعي (v2): تتبع الأخطاء بالكود، إعادة المحاولة، المصالحة،
-- سجل النشر للمراقبة، وصحة الاتصالات. آمن للتشغيل أكتر من مرة.
-- ⚠️ خد نسخة احتياطية قبل التشغيل (ALTER على social_post_targets).

-- 1) social_post_targets
SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'social_post_targets' AND COLUMN_NAME = 'error_code');
SET @q := IF(@has = 0, 'ALTER TABLE `social_post_targets` ADD COLUMN `error_code` INT DEFAULT NULL COMMENT ''كود خطأ Meta'' AFTER `last_error`', 'SELECT 1');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'social_post_targets' AND COLUMN_NAME = 'error_category');
SET @q := IF(@has = 0, 'ALTER TABLE `social_post_targets` ADD COLUMN `error_category` VARCHAR(20) DEFAULT NULL COMMENT ''token|permission|rate_limit|blocked|media|param|transient|unknown'' AFTER `error_code`', 'SELECT 1');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'social_post_targets' AND COLUMN_NAME = 'fbtrace_id');
SET @q := IF(@has = 0, 'ALTER TABLE `social_post_targets` ADD COLUMN `fbtrace_id` VARCHAR(64) DEFAULT NULL COMMENT ''رمز تتبع Meta للدعم الفني'' AFTER `error_category`', 'SELECT 1');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'social_post_targets' AND COLUMN_NAME = 'retry_count');
SET @q := IF(@has = 0, 'ALTER TABLE `social_post_targets` ADD COLUMN `retry_count` TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT ''محاولات الإعادة التلقائية (حد استخدام/شبكة)'' AFTER `fbtrace_id`', 'SELECT 1');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'social_post_targets' AND COLUMN_NAME = 'next_retry_at');
SET @q := IF(@has = 0, 'ALTER TABLE `social_post_targets` ADD COLUMN `next_retry_at` DATETIME DEFAULT NULL AFTER `retry_count`', 'SELECT 1');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'social_post_targets' AND COLUMN_NAME = 'publish_attempt_at');
SET @q := IF(@has = 0, 'ALTER TABLE `social_post_targets` ADD COLUMN `publish_attempt_at` DATETIME DEFAULT NULL COMMENT ''وقت آخر طلب نشر فعلي (UTC) - أساس نافذة المصالحة'' AFTER `next_retry_at`', 'SELECT 1');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'social_post_targets' AND COLUMN_NAME = 'reconciled_at');
SET @q := IF(@has = 0, 'ALTER TABLE `social_post_targets` ADD COLUMN `reconciled_at` DATETIME DEFAULT NULL COMMENT ''اتحسم بالمصالحة التلقائية أو يدويًا'' AFTER `publish_attempt_at`', 'SELECT 1');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'social_post_targets' AND COLUMN_NAME = 'publish_ms');
SET @q := IF(@has = 0, 'ALTER TABLE `social_post_targets` ADD COLUMN `publish_ms` INT DEFAULT NULL COMMENT ''زمن تنفيذ النشر بالمللي ثانية'' AFTER `reconciled_at`', 'SELECT 1');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
SET @has := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'social_post_targets' AND INDEX_NAME = 'idx_status_attempt');
SET @q := IF(@has = 0, 'ALTER TABLE `social_post_targets` ADD INDEX `idx_status_attempt` (`status`, `publish_attempt_at`)', 'SELECT 1');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

-- 2) social_posts: أول تعليق تلقائي (هاشتاجات/رابط)
SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'social_posts' AND COLUMN_NAME = 'first_comment');
SET @q := IF(@has = 0, 'ALTER TABLE `social_posts` ADD COLUMN `first_comment` TEXT DEFAULT NULL AFTER `hashtags`', 'SELECT 1');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

-- 3) social_post_metrics: مشاهدات + سبب غياب الـ insights (بدل null صامت)
SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'social_post_metrics' AND COLUMN_NAME = 'views');
SET @q := IF(@has = 0, 'ALTER TABLE `social_post_metrics` ADD COLUMN `views` INT DEFAULT NULL AFTER `reach`', 'SELECT 1');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'social_post_metrics' AND COLUMN_NAME = 'insights_note');
SET @q := IF(@has = 0, 'ALTER TABLE `social_post_metrics` ADD COLUMN `insights_note` VARCHAR(300) DEFAULT NULL AFTER `note`', 'SELECT 1');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

-- 4) سجل كل محاولة نشر (مراقبة: معدل النجاح، زمن التنفيذ، أكتر الأخطاء، Audit)
CREATE TABLE IF NOT EXISTS `social_publish_log` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `target_id` INT(11) NOT NULL,
    `user_id` INT(11) DEFAULT NULL,
    `platform_connection_id` INT(11) DEFAULT NULL,
    `platform` VARCHAR(20) NOT NULL,
    `outcome` VARCHAR(20) NOT NULL COMMENT 'published|failed|uncertain|retry_later|reconciled_published|reconciled_not_published|preflight_failed',
    `error_code` INT DEFAULT NULL,
    `error_category` VARCHAR(20) DEFAULT NULL,
    `fbtrace_id` VARCHAR(64) DEFAULT NULL,
    `duration_ms` INT DEFAULT NULL,
    `note` VARCHAR(300) DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_conn_created` (`platform_connection_id`, `created_at`),
    KEY `idx_user_created` (`user_id`, `created_at`),
    KEY `idx_target` (`target_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5) صحة الاتصالات (debug_token يومي + الصلاحيات الناقصة) - جدول منفصل عشان ما نعدّلش platform_connections المشتركة
CREATE TABLE IF NOT EXISTS `social_connection_health` (
    `platform_connection_id` INT(11) NOT NULL,
    `state` VARCHAR(24) NOT NULL DEFAULT 'ok' COMMENT 'ok|expiring|expired|invalid|missing_permissions|error',
    `token_expires_at` DATETIME DEFAULT NULL,
    `data_access_expires_at` DATETIME DEFAULT NULL,
    `missing_scopes` VARCHAR(500) DEFAULT NULL,
    `last_error` VARCHAR(300) DEFAULT NULL,
    `checked_at` DATETIME DEFAULT NULL,
    `notified_state` VARCHAR(24) DEFAULT NULL COMMENT 'آخر حالة بعتنا عنها إشعار (علشان ما نكرّرش)',
    `notified_at` DATETIME DEFAULT NULL,
    PRIMARY KEY (`platform_connection_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
