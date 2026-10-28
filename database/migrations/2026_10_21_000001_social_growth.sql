-- Tourfecto - السوشيال: الرد الخاص التلقائي، نسبة الحجوزات للمنشورات، التحليلات المتقدمة، المنافسين،
-- رابط موافقة العميل، صلاحيات الفريق، ومنصات جديدة. آمن للتشغيل أكتر من مرة، وكله إضافات (أعمدة NULL وجداول جديدة).

-- ============ 1) الرد الخاص (Private Reply) على التعليقات ============
SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'social_comments' AND COLUMN_NAME = 'private_replied_at');
SET @q := IF(@has = 0, 'ALTER TABLE `social_comments` ADD COLUMN `private_replied_at` DATETIME DEFAULT NULL, ADD COLUMN `private_reply_text` TEXT DEFAULT NULL, ADD COLUMN `auto_handled` TINYINT(1) NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

CREATE TABLE IF NOT EXISTS `social_inbox_settings` (
    `user_id` INT(11) NOT NULL,
    `auto_private` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'رد خاص تلقائي على تعليقات السعر/الحجز',
    `auto_intents` VARCHAR(100) NOT NULL DEFAULT 'price,booking',
    `private_template` TEXT DEFAULT NULL COMMENT 'قالب الرسالة الخاصة: {name} {company} {link}',
    `public_ack` VARCHAR(500) DEFAULT NULL COMMENT 'رد علني قصير بعد الرسالة الخاصة',
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============ 2) نسبة الحجوزات للمنشورات (روابط قصيرة متتبعة) ============
CREATE TABLE IF NOT EXISTS `social_links` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `user_id` INT(11) NOT NULL,
    `social_post_id` INT(11) NOT NULL,
    `target_id` INT(11) DEFAULT NULL,
    `platform` VARCHAR(20) NOT NULL,
    `code` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `destination_url` VARCHAR(1000) NOT NULL,
    `original_url` VARCHAR(1000) NOT NULL,
    `clicks` INT(11) NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_code` (`code`),
    UNIQUE KEY `uniq_target_url` (`target_id`, `original_url`(255)),
    KEY `idx_post` (`social_post_id`),
    KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bookings' AND COLUMN_NAME = 'attributed_social_link_id');
SET @q := IF(@has = 0, 'ALTER TABLE `bookings` ADD COLUMN `attributed_social_link_id` INT(11) DEFAULT NULL, ADD INDEX `idx_social_link` (`attributed_social_link_id`)', 'SELECT 1');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'social_posts' AND COLUMN_NAME = 'track_links');
SET @q := IF(@has = 0, 'ALTER TABLE `social_posts` ADD COLUMN `track_links` TINYINT(1) NOT NULL DEFAULT 0 COMMENT ''روابط قصيرة لتتبع الحجوزات''', 'SELECT 1');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

-- ============ 3) تحليلات متقدمة ============
CREATE TABLE IF NOT EXISTS `social_account_snapshots` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `platform_connection_id` INT(11) NOT NULL,
    `snap_date` DATE NOT NULL,
    `followers` INT(11) DEFAULT NULL,
    `posts_count` INT(11) DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_conn_day` (`platform_connection_id`, `snap_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'social_post_metrics' AND COLUMN_NAME = 'saves');
SET @q := IF(@has = 0, 'ALTER TABLE `social_post_metrics` ADD COLUMN `saves` INT(11) DEFAULT NULL', 'SELECT 1');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'social_post_metrics' AND COLUMN_NAME = 'views');
SET @q := IF(@has = 0, 'ALTER TABLE `social_post_metrics` ADD COLUMN `views` INT(11) DEFAULT NULL', 'SELECT 1');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

-- ============ 4) المنافسين على إنستجرام ============
CREATE TABLE IF NOT EXISTS `social_competitors` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `user_id` INT(11) NOT NULL,
    `platform` VARCHAR(20) NOT NULL DEFAULT 'instagram',
    `username` VARCHAR(100) NOT NULL,
    `display_name` VARCHAR(190) DEFAULT NULL,
    `followers` INT(11) DEFAULT NULL,
    `media_count` INT(11) DEFAULT NULL,
    `avg_engagement` DECIMAL(12,1) DEFAULT NULL COMMENT 'متوسط (لايك+تعليق) لآخر 12 منشور',
    `posts_per_week` DECIMAL(6,1) DEFAULT NULL,
    `top_posts_json` MEDIUMTEXT DEFAULT NULL,
    `last_checked_at` DATETIME DEFAULT NULL,
    `last_error` VARCHAR(300) DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_user_handle` (`user_id`, `platform`, `username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `social_competitor_snapshots` (
    `competitor_id` INT(11) NOT NULL,
    `snap_date` DATE NOT NULL,
    `followers` INT(11) DEFAULT NULL,
    `avg_engagement` DECIMAL(12,1) DEFAULT NULL,
    PRIMARY KEY (`competitor_id`, `snap_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============ 5) رابط موافقة العميل + موافقة الفريق ============
CREATE TABLE IF NOT EXISTS `social_share_links` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `user_id` INT(11) NOT NULL,
    `plan_id` INT(11) NOT NULL,
    `token_hash` CHAR(64) NOT NULL,
    `can_approve` TINYINT(1) NOT NULL DEFAULT 1,
    `client_name` VARCHAR(120) DEFAULT NULL,
    `expires_at` DATETIME NOT NULL,
    `revoked_at` DATETIME DEFAULT NULL,
    `last_viewed_at` DATETIME DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_token` (`token_hash`),
    KEY `idx_plan` (`plan_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `social_plan_feedback` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `plan_id` INT(11) NOT NULL,
    `item_id` INT(11) DEFAULT NULL,
    `share_link_id` INT(11) DEFAULT NULL,
    `author_name` VARCHAR(120) DEFAULT NULL,
    `decision` VARCHAR(20) NOT NULL DEFAULT 'comment' COMMENT 'comment|changes|approved',
    `message` TEXT DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_plan` (`plan_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'social_posts' AND COLUMN_NAME = 'approval_state');
SET @q := IF(@has = 0, 'ALTER TABLE `social_posts` ADD COLUMN `approval_state` VARCHAR(16) DEFAULT NULL COMMENT ''pending|approved|rejected'', ADD COLUMN `requested_targets_json` TEXT DEFAULT NULL, ADD COLUMN `created_by` INT(11) DEFAULT NULL, ADD COLUMN `reviewed_by` INT(11) DEFAULT NULL, ADD COLUMN `review_note` VARCHAR(500) DEFAULT NULL', 'SELECT 1');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

-- ============ 6) إعدادات تيك توك لكل منشور (كانت بتتعمل وقت التشغيل - دلوقتي هنا كمان) ============
CREATE TABLE IF NOT EXISTS `tiktok_post_options` (
    `target_id` INT NOT NULL PRIMARY KEY,
    `options` TEXT NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) DEFAULT CHARSET=utf8mb4;
