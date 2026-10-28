-- ============================================================
-- Tourfecto - Migration: حالة تنبيهات الاستخدام (usage_alert_state)
--
-- جدول جديد بالكامل (لا يمس أي جدول موجود) - بيسجّل آخر نسبة استخدام
-- (50/75/90/100%) اتبعت عنها إشعار لكل عميل/ميزة/فترة اشتراك، عشان
-- منبعتش نفس الإشعار في كل مرة يفتح فيها المستخدم /subscription.
--
-- period_key = "{subscription_id}:{current_period_end}" بيتغيّر تلقائيًا
-- لما الاشتراك يتجدد أو الباقة تتغيّر (صف جديد أو current_period_end
-- جديد) - يعني التنبيهات بترجع تتصفّر لوحدها كل فترة فوترة جديدة من
-- غير أي منطق تنظيف إضافي مطلوب.
-- @version 1.0.0  @date 2026-08-09
-- ============================================================

CREATE TABLE IF NOT EXISTS `usage_alert_state` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `user_id` INT(11) NOT NULL,
    `metric_key` VARCHAR(30) NOT NULL COMMENT 'ai / chat / review / competitor',
    `period_key` VARCHAR(120) NOT NULL COMMENT 'subscription_id:current_period_end - يتغيّر كل فترة فوترة',
    `highest_threshold_notified` TINYINT(3) NOT NULL DEFAULT 0 COMMENT 'آخر نسبة اتبعت عنها إشعار: 0/50/75/90/100',
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_usage_alert_state` (`user_id`, `metric_key`, `period_key`),
    KEY `idx_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='حالة تنبيهات نسب استخدام الباقة - لمنع تكرار نفس الإشعار';
