-- Tourfecto - صور متعددة للمنشور الواحد (ألبوم فيسبوك / كاروسيل إنستجرام) - آمن للتشغيل أكتر من مرة
-- social_posts.media_item_id بيفضل أول صورة (للتوافق والمعاينة)، وباقي الترتيب هنا.
CREATE TABLE IF NOT EXISTS `social_post_media` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `social_post_id` INT(11) NOT NULL,
    `media_item_id` INT(11) NOT NULL,
    `position` TINYINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_post_media` (`social_post_id`, `media_item_id`),
    KEY `idx_post_position` (`social_post_id`, `position`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
