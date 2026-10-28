-- Tourfecto - callbacks الخصوصية المطلوبة من Meta للتطبيقات العامة (Deauthorize + Data Deletion). آمن للتكرار.
-- 1) معرّف مستخدم فيسبوك (App-scoped) على صحة الاتصال: بيتملي من debug_token في الفحص اليومي، وهو أساس مطابقة الـ callback.
SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'social_connection_health' AND COLUMN_NAME = 'fb_user_id');
SET @q := IF(@has = 0, 'ALTER TABLE `social_connection_health` ADD COLUMN `fb_user_id` VARCHAR(40) DEFAULT NULL AFTER `platform_connection_id`, ADD INDEX `idx_fb_user` (`fb_user_id`)', 'SELECT 1');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

-- 2) سجل طلبات الخصوصية (Audit). بنخزّن hash للمعرّف مش المعرّف نفسه، وكود التأكيد اللي Meta بتعرضه للمستخدم.
CREATE TABLE IF NOT EXISTS `meta_data_requests` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `confirmation_code` CHAR(24) NOT NULL,
    `fb_user_hash` CHAR(64) NOT NULL,
    `type` VARCHAR(16) NOT NULL COMMENT 'deauthorize|deletion',
    `status` VARCHAR(32) NOT NULL DEFAULT 'received' COMMENT 'completed|no_matching_connection',
    `connections_affected` INT(11) NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_code` (`confirmation_code`),
    KEY `idx_hash` (`fb_user_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
