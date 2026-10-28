-- ============================================================
-- Tourfecto - Migration: صفحة الإعدادات الاحترافية
-- (صورة شخصية + تفضيلات إشعارات حقيقية بدل قيم وهمية)
-- @version 1.0.0  @date 2026-07-19
-- ============================================================

ALTER TABLE `users`
    ADD COLUMN `avatar_url` VARCHAR(500) DEFAULT NULL COMMENT 'رابط الصورة الشخصية (نسبي من جذر الموقع)' AFTER `company_name`,
    ADD COLUMN `notify_email` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'تفعيل إشعارات البريد الإلكتروني',
    ADD COLUMN `notify_chat` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'تفعيل إشعارات المحادثات',
    ADD COLUMN `notify_reviews` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'تفعيل إشعارات المراجعات الجديدة';
