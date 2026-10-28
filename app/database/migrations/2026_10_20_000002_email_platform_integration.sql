-- ============================================================
-- Tourfecto - Migration: ربط التسويق بالبريد بباقي خدمات المنصة
--   - email_platform_settings     إعدادات الربط لكل حساب (مزامنة عملاء الحجوزات/CRM)
--   - email_booking_attribution   إسناد الحجز لحملة/أتمتة بريد (كليك → حجز → إيراد)
--   - email_automation_triggers_log منع تكرار تسجيل نفس الحجز/الحدث في نفس الأتمتة
--     (مشغّلات الرحلة: قبل الرحلة / بعد الرحلة، وdate_after)
-- إضافة فقط (مفيش DROP/DELETE)، وينفع يتشغّل أكتر من مرة.
-- @version 1.0.0  @date 2026-10-20
-- ============================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `email_platform_settings` (
    `user_id` INT(11) NOT NULL,
    `sync_booking_customers` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'إضافة عملاء الحجوزات كمشتركين تلقائيًا',
    `booking_list_id` INT(11) NULL DEFAULT NULL COMMENT 'القائمة اللي بيتضافوا ليها',
    `sync_crm_contacts` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'إضافة جهات اتصال CRM (Lead/Deal) كمشتركين',
    `crm_list_id` INT(11) NULL DEFAULT NULL,
    `revenue_attribution` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'ربط الحجوزات بالحملات اللي جابتها',
    `attribution_window_days` SMALLINT UNSIGNED NOT NULL DEFAULT 30,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='إعدادات ربط البريد بالمنصة';

CREATE TABLE IF NOT EXISTS `email_booking_attribution` (
    `booking_id` INT(11) NOT NULL,
    `user_id` INT(11) NOT NULL,
    `campaign_id` INT(11) NULL DEFAULT NULL,
    `automation_id` INT(11) NULL DEFAULT NULL,
    `subscriber_id` INT(11) NULL DEFAULT NULL,
    `click_token` VARCHAR(64) NOT NULL,
    `amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `currency` VARCHAR(10) NULL DEFAULT NULL,
    `credited_at` DATETIME NULL DEFAULT NULL COMMENT 'اتحسب في attributed_revenue (بعد التأكيد)',
    `reversed_at` DATETIME NULL DEFAULT NULL COMMENT 'اتشال بعد إلغاء الحجز',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`booking_id`),
    KEY `idx_eba_user` (`user_id`),
    KEY `idx_eba_campaign` (`campaign_id`),
    KEY `idx_eba_automation` (`automation_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='إسناد الحجوزات لحملات البريد';

CREATE TABLE IF NOT EXISTS `email_automation_triggers_log` (
    `automation_id` INT(11) NOT NULL,
    `subscriber_id` INT(11) NOT NULL,
    `ref_key` VARCHAR(64) NOT NULL COMMENT 'booking:<id> أو once',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`automation_id`, `subscriber_id`, `ref_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='منع تكرار دخول نفس الحدث للأتمتة';

-- أعمدة الإيراد المنسوب (كانت متضافة في enterprise upgrade لكن من غير أي كود بيملاها)
ALTER TABLE `email_campaigns`
    ADD COLUMN IF NOT EXISTS `attributed_bookings_count` INT UNSIGNED NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS `attributed_revenue` DECIMAL(12,2) NOT NULL DEFAULT 0.00;

ALTER TABLE `email_automations`
    ADD COLUMN IF NOT EXISTS `attributed_bookings_count` INT UNSIGNED NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS `attributed_revenue` DECIMAL(12,2) NOT NULL DEFAULT 0.00;

-- Send-Time Optimization + الحملات المتكررة
ALTER TABLE `email_campaigns`
    ADD COLUMN IF NOT EXISTS `send_time_optimization` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'كل مستلم يتبعتله في أنسب ساعة ليه',
    ADD COLUMN IF NOT EXISTS `recurrence` VARCHAR(16) NULL DEFAULT NULL COMMENT 'daily/weekly/monthly — الحملة قالب بيتنسخ كل مرة',
    ADD COLUMN IF NOT EXISTS `next_run_at` DATETIME NULL DEFAULT NULL COMMENT 'UTC',
    ADD COLUMN IF NOT EXISTS `parent_campaign_id` INT(11) NULL DEFAULT NULL COMMENT 'الحملة المتكررة الأصلية';

-- تصنيف الفتح (Apple MPP / فلاتر الأمان) — نفس أعمدة enterprise upgrade، مكررة هنا
-- عشان الميزة تشتغل حتى لو ملف 2026_10_18 ما اتشغّلش
ALTER TABLE `email_campaign_recipients`
    ADD COLUMN IF NOT EXISTS `is_human_open` TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS `open_type` VARCHAR(32) NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `user_agent` VARCHAR(512) NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `ip_address` VARCHAR(64) NULL DEFAULT NULL;

ALTER TABLE `email_subscribers`
    ADD COLUMN IF NOT EXISTS `optimal_send_hour` TINYINT UNSIGNED NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `last_engaged_at` TIMESTAMP NULL DEFAULT NULL;

CREATE INDEX IF NOT EXISTS `idx_email_campaigns_recurring` ON `email_campaigns` (`recurrence`, `next_run_at`);
