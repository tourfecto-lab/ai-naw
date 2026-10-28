-- =====================================================================
-- Tourfecto - 2026_10_09_000005: لغة رسايل تذكير الرحلة وملخص الصبح
--   التذكير: auto (حسب اسم العميل) أو عربي أو إنجليزي + نص إنجليزي منفصل
--   ملخص الصبح: عربي أو إنجليزي
-- =====================================================================
ALTER TABLE `booking_reminder_settings` ADD COLUMN IF NOT EXISTS `language` VARCHAR(5) NOT NULL DEFAULT 'auto';
ALTER TABLE `booking_reminder_settings` ADD COLUMN IF NOT EXISTS `message_template_en` TEXT NULL DEFAULT NULL;
ALTER TABLE `morning_brief_settings` ADD COLUMN IF NOT EXISTS `language` VARCHAR(5) NOT NULL DEFAULT 'ar';
