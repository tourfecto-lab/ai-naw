-- Tourfecto - الشحن التلقائي لحزم المنشورات (الاستخدام الزائد): تفضيل العميل + سجل الشحن الشهري بالسقف.
-- جداول جديدة بس، آمن للتشغيل أكتر من مرة، ومتوافق مع MySQL. الكود بينشئها تلقائيًا لو اتنسى الملف.

CREATE TABLE IF NOT EXISTS `social_topup_prefs` (
    `user_id` INT(11) NOT NULL,
    `enabled` TINYINT(1) NOT NULL DEFAULT 0,
    `monthly_cap_usd` DECIMAL(8,2) NOT NULL DEFAULT 20,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `social_topup_log` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `user_id` INT(11) NOT NULL,
    `month_key` CHAR(7) NOT NULL,
    `addon_key` VARCHAR(30) NOT NULL,
    `price` DECIMAL(8,2) NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_user_month` (`user_id`, `month_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
