-- Tourfecto - الطيار الآلي للسوشيال: تجهيز خطة الشهر الجاي أوتوماتيك من الحجوزات والرحلات النشطة
-- آمن للتشغيل أكتر من مرة.
CREATE TABLE IF NOT EXISTS `social_autopilot_settings` (
    `website_id` INT(11) NOT NULL,
    `user_id` INT(11) NOT NULL,
    `enabled` TINYINT(1) NOT NULL DEFAULT 0,
    `connection_ids` VARCHAR(100) DEFAULT NULL COMMENT 'JSON: صفحات النشر؛ فاضي = كل الصفحات المربوطة',
    `post_count` TINYINT UNSIGNED NOT NULL DEFAULT 12,
    `publish_time` CHAR(5) NOT NULL DEFAULT '18:00',
    `timezone` VARCHAR(64) NOT NULL DEFAULT 'UTC',
    `language` CHAR(2) NOT NULL DEFAULT 'en',
    `mix` VARCHAR(10) NOT NULL DEFAULT 'mix',
    `run_day` TINYINT UNSIGNED NOT NULL DEFAULT 20 COMMENT 'يوم في الشهر بتتجهز فيه خطة الشهر الجاي',
    `auto_approve` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'يعتمد الخطة وينشر من غير مراجعة (باقات الطيار الآلي بس)',
    `last_month_key` CHAR(7) DEFAULT NULL,
    `last_plan_id` INT(11) DEFAULT NULL,
    `last_status` VARCHAR(30) DEFAULT NULL,
    `last_error` VARCHAR(500) DEFAULT NULL,
    `last_run_at` DATETIME DEFAULT NULL,
    `created_at` DATETIME NOT NULL,
    `updated_at` DATETIME NOT NULL,
    PRIMARY KEY (`website_id`),
    KEY `idx_sas_user` (`user_id`),
    KEY `idx_sas_due` (`enabled`, `last_month_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
