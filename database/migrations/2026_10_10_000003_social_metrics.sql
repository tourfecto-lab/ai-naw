-- Tourfecto - إحصائيات تفاعل المنشورات (كاش لنتائج Meta Graph) - آمن للتشغيل أكتر من مرة
CREATE TABLE IF NOT EXISTS `social_post_metrics` (
    `target_id` INT(11) NOT NULL,
    `likes` INT(11) NOT NULL DEFAULT 0,
    `comments` INT(11) NOT NULL DEFAULT 0,
    `shares` INT(11) NOT NULL DEFAULT 0,
    `reach` INT(11) DEFAULT NULL,
    `state` VARCHAR(20) NOT NULL DEFAULT 'ok' COMMENT 'ok | unavailable',
    `note` VARCHAR(300) DEFAULT NULL,
    `fetched_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`target_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
