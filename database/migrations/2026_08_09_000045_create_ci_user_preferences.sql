-- ============================================================
-- Tourfecto - Migration: Competitor Intelligence - User Preferences
-- @version 1.0.3  @date 2026-08-09
--
-- جدول صغير جديد لدعم صفحة "Settings" المطلوبة صراحة في الأمر الأصلي
-- (بند 45) واللي كانت ناقصة بالكامل من التسليم الأول. مفيش جدول
-- إعدادات مستخدم عام بالمشروع حاليًا نقدر نعيد استخدامه، فده جدول مُقيَّد
-- بحدود هذا الموديول فقط (زي باقي جداول ci_*) - إعدادات افتراضية بيتم
-- تطبيقها تلقائيًا وقت إضافة منافس جديد، مش إعداد عام على مستوى المنصة.
-- ============================================================

CREATE TABLE IF NOT EXISTS `ci_user_preferences` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT(11) NOT NULL,
    `default_monitoring_frequency` ENUM('daily','weekly','custom') NOT NULL DEFAULT 'weekly',
    `default_alert_min_severity` ENUM('info','low','medium','high','critical') NOT NULL DEFAULT 'medium',
    `default_alert_channels` JSON DEFAULT NULL COMMENT 'مثال: ["dashboard","email"]',
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_ci_user_preferences_user` (`user_id`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='تفضيلات افتراضية لكل مستخدم في موديول Competitor Intelligence - تُستخدم كقيم ابتدائية فقط، لا تفرض على المنافسين الموجودين';
