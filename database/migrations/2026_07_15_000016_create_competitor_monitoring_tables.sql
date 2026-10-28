-- ============================================================
-- BATCH6 - Competitor Monitoring
-- بادئة cm_. اتجاهلنا جداول الموديول الأصلية: competitors/
-- competitor_websites (عندك competitors فعلاً بأعمدة حقيقية مؤكدة من
-- BATCH3)، google_ads/meta_ads (عندك ad_campaigns)، users/tenants
-- (عندك users بالفعل، مفيش multi-tenant منفصل). ربطنا كل جدول جديد
-- بـ competitors.id الموجود.
-- ============================================================

CREATE TABLE IF NOT EXISTS `cm_google_rankings` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `competitor_id` INT NOT NULL,
    `keyword` VARCHAR(255) NOT NULL,
    `position` SMALLINT UNSIGNED NULL,
    `checked_at` DATE NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_cm_rank_competitor` (`competitor_id`, `checked_at`),
    FOREIGN KEY (`competitor_id`) REFERENCES `competitors`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `cm_pricing` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `competitor_id` INT NOT NULL,
    `item_name` VARCHAR(255) NOT NULL,
    `price` DECIMAL(10,2) NULL,
    `currency` VARCHAR(10) DEFAULT 'USD',
    `observed_at` DATE NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_cm_price_competitor` (`competitor_id`, `observed_at`),
    FOREIGN KEY (`competitor_id`) REFERENCES `competitors`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `cm_offers` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `competitor_id` INT NOT NULL,
    `title` VARCHAR(255) NOT NULL,
    `description` TEXT NULL,
    `detected_at` DATE NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_cm_offer_competitor` (`competitor_id`),
    FOREIGN KEY (`competitor_id`) REFERENCES `competitors`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `cm_content_updates` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `competitor_id` INT NOT NULL,
    `title` VARCHAR(255) NOT NULL,
    `url` VARCHAR(500) NULL,
    `detected_at` DATE NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_cm_content_competitor` (`competitor_id`),
    FOREIGN KEY (`competitor_id`) REFERENCES `competitors`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `cm_alerts` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `competitor_id` INT NOT NULL,
    `user_id` INT(11) NOT NULL,
    `alert_type` VARCHAR(50) NOT NULL COMMENT 'price_change/new_offer/ranking_drop/new_content',
    `message` VARCHAR(500) NOT NULL,
    `is_read` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_cm_alert_user` (`user_id`, `is_read`),
    FOREIGN KEY (`competitor_id`) REFERENCES `competitors`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
