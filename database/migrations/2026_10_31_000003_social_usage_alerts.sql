-- Tourfecto - تنبيهات استهلاك باقة السوشيال (80% و100%) مرة واحدة لكل مستوى في الشهر.
-- جدول جديد بس، آمن للتشغيل أكتر من مرة. الكود بينشئه تلقائيًا لو اتنسى الملف.

CREATE TABLE IF NOT EXISTS `social_usage_alerts` (
    `user_id` INT(11) NOT NULL,
    `month_key` CHAR(7) NOT NULL,
    `kind` VARCHAR(20) NOT NULL COMMENT 'posts|ai_captions',
    `level` TINYINT(4) NOT NULL COMMENT '80|100',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`user_id`, `month_key`, `kind`, `level`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
