-- Tourfecto - السوشيال ميديا الاحترافية: الستوري، تتبّع UTM، صندوق التعليقات. آمن للتشغيل أكتر من مرة.
-- كل الإضافات أعمدة NULL/افتراضية وجداول جديدة: الكود القديم شغال قبلها وبعدها، والكود الجديد بيتأكد من وجودها قبل ما يستخدمها.

-- 1) شكل المنشور: feed (عادي) أو story (ستوري فيسبوك/إنستجرام)
SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'social_posts' AND COLUMN_NAME = 'post_format');
SET @q := IF(@has = 0, 'ALTER TABLE `social_posts` ADD COLUMN `post_format` VARCHAR(16) NOT NULL DEFAULT ''feed'' COMMENT ''feed|story''', 'SELECT 1');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

-- 2) حملة UTM: لو متحددة، كل رابط لموقع العميل في النص بياخد utm_source=المنصة&utm_medium=social&utm_campaign=...
SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'social_posts' AND COLUMN_NAME = 'utm_campaign');
SET @q := IF(@has = 0, 'ALTER TABLE `social_posts` ADD COLUMN `utm_campaign` VARCHAR(80) DEFAULT NULL', 'SELECT 1');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

-- 3) صندوق التعليقات: تعليقات الجمهور على منشوراتنا (بتتسحب من Meta)، والرد عليها من المنصة
CREATE TABLE IF NOT EXISTS `social_comments` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `user_id` INT(11) NOT NULL,
    `platform_connection_id` INT(11) NOT NULL,
    `target_id` INT(11) DEFAULT NULL COMMENT 'social_post_targets.id',
    `platform` VARCHAR(16) NOT NULL COMMENT 'facebook|instagram',
    `external_comment_id` VARCHAR(80) NOT NULL,
    `external_object_id` VARCHAR(80) DEFAULT NULL COMMENT 'المنشور اللي عليه التعليق',
    `author_name` VARCHAR(190) DEFAULT NULL,
    `author_id` VARCHAR(80) DEFAULT NULL,
    `message` TEXT NOT NULL,
    `commented_at` DATETIME DEFAULT NULL COMMENT 'UTC',
    `like_count` INT(11) NOT NULL DEFAULT 0,
    `intent` VARCHAR(24) DEFAULT NULL COMMENT 'price|booking|question|praise|complaint|spam|other',
    `status` VARCHAR(16) NOT NULL DEFAULT 'new' COMMENT 'new|replied|hidden|done',
    `ai_suggestion` TEXT DEFAULT NULL,
    `reply_text` TEXT DEFAULT NULL,
    `reply_external_id` VARCHAR(80) DEFAULT NULL,
    `replied_at` DATETIME DEFAULT NULL,
    `fetched_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_conn_comment` (`platform_connection_id`, `external_comment_id`),
    KEY `idx_user_status` (`user_id`, `status`, `commented_at`),
    KEY `idx_target` (`target_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4) أعمدة مكتبة الوسائط اللي كود النشر بيعتمد عليها (deleted_at في كل استعلامات النشر، publish_version في روابط الوسائط الخاصة).
--    مفيش ملف migration في المشروع بيعملها؛ لو موجودة على السيرفر الخطوة دي بتتخطى من غير ما تغيّر حاجة.
SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'media_items' AND COLUMN_NAME = 'deleted_at');
SET @q := IF(@has = 0, 'ALTER TABLE `media_items` ADD COLUMN `deleted_at` TIMESTAMP NULL DEFAULT NULL', 'SELECT 1');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'media_items' AND COLUMN_NAME = 'publish_version');
SET @q := IF(@has = 0, 'ALTER TABLE `media_items` ADD COLUMN `publish_version` INT NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
