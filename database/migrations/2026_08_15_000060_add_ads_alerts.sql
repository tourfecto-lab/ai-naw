-- ============================================================
-- Tourfecto - Migration: Ad Alerts (Proactive Rules + Alerts)
-- @version 1.0.0  @date 2026-08-15
--
-- تنبيهات استباقية (قواعد إنذار) لحملات الإعلانات - إضافي و non-destructive
-- فوق migrations الموديول السابق. كل التقييمات مبنية على بيانات أداء حقيقية
-- مُزامنة من المنصات (ad_performance_reports) فقط - مفيش أي رقم مُختلق.
-- ============================================================

-- ------------------------------------------------------------
-- 1) قواعد الإنذار لكل مستخدم (الافتراضيات بتيجي من AdAlertService)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ad_alert_rules` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `user_id` INT(11) NOT NULL,
    `rule_type` ENUM('budget_exhausted','cpc_spike','ctr_drop','landing_page_down','budget_pacing') NOT NULL,
    `is_enabled` TINYINT(1) NOT NULL DEFAULT 1,
    `threshold_value` DECIMAL(12,2) DEFAULT NULL COMMENT 'نسبة/حد القاعدة (نسبة مئوية) - التفسير خاص بكل قاعدة',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_user_rule` (`user_id`, `rule_type`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='قواعد التنبيهات الاستباقية لكل مستخدم';

-- ------------------------------------------------------------
-- 2) التنبيهات المُولّدة (مش duplicate لنفس القاعدة/الحملة/اليوم)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ad_alerts` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `user_id` INT(11) NOT NULL,
    `campaign_id` INT(11) NOT NULL,
    `rule_type` ENUM('budget_exhausted','cpc_spike','ctr_drop','landing_page_down','budget_pacing') NOT NULL,
    `severity` ENUM('info','warning','critical') NOT NULL DEFAULT 'warning',
    `title` VARCHAR(255) NOT NULL,
    `body` TEXT DEFAULT NULL,
    `is_read` TINYINT(1) NOT NULL DEFAULT 0,
    `is_dismissed` TINYINT(1) NOT NULL DEFAULT 0,
    `alert_date` DATE NOT NULL COMMENT 'يوم توليد التنبيه - لمنع التكرار لنفس القاعدة/الحملة',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_user_campaign_rule_date` (`user_id`, `campaign_id`, `rule_type`, `alert_date`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`campaign_id`) REFERENCES `ad_campaigns`(`id`) ON DELETE CASCADE,
    INDEX `idx_user_unread` (`user_id`, `is_read`, `is_dismissed`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='تنبيهات استباقية مولّدة من قواعد AdAlertService';
