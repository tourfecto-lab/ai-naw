-- ============================================================
-- Tourfecto - Migration: توسعة التحليلات من ai-analytics-insights-hub
-- @version 1.0.0  @date 2026-07-14
--
-- نطاق محدود عمدًا: أضفنا فقط جداول تخزين بيانات الزيارات/التحويلات/
-- الأجهزة/الدول - دي جداول "استقبال بيانات" (تحتاج مصدر حقيقي يغذّيها،
-- زي Google Analytics API عبر platform_connections). باقي جداول
-- الموديول الأصلي (landing_pages, social_insights, local_performance,
-- ai_search_traffic, keyword_rankings, user_behavior, generated_reports)
-- اتأجّلوا لمرحلة تالية - إضافتهم دلوقتي هتبني واجهة بلا مصدر بيانات
-- حقيقي وراها.
-- ============================================================

CREATE TABLE IF NOT EXISTS `analytics_traffic` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `website_id` INT(11) NOT NULL,
    `date` DATE NOT NULL,
    `sessions` INT(11) NOT NULL DEFAULT 0,
    `users` INT(11) NOT NULL DEFAULT 0,
    `pageviews` INT(11) NOT NULL DEFAULT 0,
    `bounce_rate` DECIMAL(5,2) DEFAULT NULL,
    `avg_session_duration_seconds` INT(11) DEFAULT NULL,
    `source` VARCHAR(50) DEFAULT 'manual' COMMENT 'google_analytics, manual...',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    FOREIGN KEY (`website_id`) REFERENCES `websites`(`id`) ON DELETE CASCADE,
    UNIQUE KEY `uniq_website_date` (`website_id`, `date`),
    INDEX `idx_date` (`date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='زيارات الموقع اليومية';

CREATE TABLE IF NOT EXISTS `analytics_conversions` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `website_id` INT(11) NOT NULL,
    `date` DATE NOT NULL,
    `goal_name` VARCHAR(100) NOT NULL COMMENT 'booking, contact_form, whatsapp_click...',
    `conversions` INT(11) NOT NULL DEFAULT 0,
    `revenue` DECIMAL(12,2) DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    FOREIGN KEY (`website_id`) REFERENCES `websites`(`id`) ON DELETE CASCADE,
    INDEX `idx_website_date` (`website_id`, `date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='أهداف وتحويلات الموقع';

CREATE TABLE IF NOT EXISTS `analytics_device_breakdown` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `website_id` INT(11) NOT NULL,
    `date` DATE NOT NULL,
    `device_type` ENUM('desktop','mobile','tablet') NOT NULL,
    `sessions` INT(11) NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    FOREIGN KEY (`website_id`) REFERENCES `websites`(`id`) ON DELETE CASCADE,
    UNIQUE KEY `uniq_website_date_device` (`website_id`, `date`, `device_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='توزيع الزيارات حسب نوع الجهاز';

CREATE TABLE IF NOT EXISTS `analytics_country_breakdown` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `website_id` INT(11) NOT NULL,
    `date` DATE NOT NULL,
    `country_code` CHAR(2) NOT NULL,
    `sessions` INT(11) NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    FOREIGN KEY (`website_id`) REFERENCES `websites`(`id`) ON DELETE CASCADE,
    UNIQUE KEY `uniq_website_date_country` (`website_id`, `date`, `country_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='توزيع الزيارات حسب الدولة';
