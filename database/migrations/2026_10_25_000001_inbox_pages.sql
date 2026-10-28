-- Tourfecto - صندوق التعليقات: الصفحات اللي بيسحب منها ويرد عليها + تعليقات المنشورات المنشورة من فيسبوك/إنستجرام مباشرة.
-- آمن للتشغيل أكتر من مرة، وكله إضافات (جدول جديد وأعمدة NULL).

CREATE TABLE IF NOT EXISTS `social_inbox_pages` (
    `platform_connection_id` INT(11) NOT NULL,
    `user_id` INT(11) NOT NULL,
    `auto_reply` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '0 = الرد الخاص التلقائي مقفول على الصفحة دي',
    `last_sync_at` DATETIME DEFAULT NULL COMMENT 'UTC - آخر مرة اتسحبت تعليقاتها',
    `last_error` VARCHAR(500) DEFAULT NULL,
    `posts_checked` INT(11) NOT NULL DEFAULT 0,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`platform_connection_id`),
    KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'social_comments' AND COLUMN_NAME = 'post_excerpt');
SET @q := IF(@has = 0, 'ALTER TABLE `social_comments` ADD COLUMN `post_excerpt` VARCHAR(300) DEFAULT NULL, ADD COLUMN `post_permalink` VARCHAR(600) DEFAULT NULL', 'SELECT 1');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
