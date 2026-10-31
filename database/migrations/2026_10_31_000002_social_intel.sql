-- Tourfecto - ذكاء السوشيال: سجل الفحص العام، دليل المنافسين حسب السوق، وسجل تشغيلات البحث (التكلفة الفعلية).
-- جداول جديدة بس (CREATE TABLE IF NOT EXISTS)، آمن للتشغيل أكتر من مرة، ومتوافق مع MySQL و MariaDB.
-- الكود بينشئ نفس الجداول تلقائيًا لو اتنسى الملف ده.

CREATE TABLE IF NOT EXISTS `social_check_log` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `lang` VARCHAR(2) NOT NULL DEFAULT 'en',
    `fb_handle` VARCHAR(100) DEFAULT NULL COMMENT 'اسم صفحة فيسبوك العامة (مش بيانات شخصية)',
    `ig_handle` VARCHAR(40) DEFAULT NULL,
    `competitors` TEXT DEFAULT NULL,
    `n_comp` TINYINT(4) NOT NULL DEFAULT 0,
    `errors` VARCHAR(200) DEFAULT NULL,
    `converted` TINYINT(1) NOT NULL DEFAULT 0,
    `converted_at` DATETIME DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_created` (`created_at`),
    KEY `idx_fb` (`fb_handle`),
    KEY `idx_ig` (`ig_handle`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `social_market_directory` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `market_key` VARCHAR(16) NOT NULL COMMENT 'EG-ar',
    `domain` VARCHAR(190) NOT NULL,
    `name` VARCHAR(255) DEFAULT NULL,
    `facebook_handle` VARCHAR(100) DEFAULT NULL,
    `instagram_handle` VARCHAR(40) DEFAULT NULL,
    `seen_count` INT(11) NOT NULL DEFAULT 1,
    `first_seen` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `last_seen` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_market_domain` (`market_key`, `domain`),
    KEY `idx_market_seen` (`market_key`, `seen_count`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `social_finder_runs` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `user_id` INT(11) NOT NULL,
    `source` VARCHAR(24) NOT NULL,
    `found` INT(11) NOT NULL DEFAULT 0,
    `cost_usd` DECIMAL(8,4) NOT NULL DEFAULT 0 COMMENT 'التكلفة الفعلية من رد المزوّد',
    PRIMARY KEY (`id`),
    KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
