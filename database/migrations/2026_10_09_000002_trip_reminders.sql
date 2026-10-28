-- =====================================================================
-- Tourfecto - 2026_10_09_000002: تذكير العميل قبل الرحلة بيوم
--   إعدادات لكل صاحب نشاط + تسجيل التذكير على كل حجز (عشان مايتبعتش مرتين)
-- =====================================================================
CREATE TABLE IF NOT EXISTS `booking_reminder_settings` (
  `user_id` INT NOT NULL,
  `enabled` TINYINT(1) NOT NULL DEFAULT 0,
  `send_hour` TINYINT NOT NULL DEFAULT 18,
  `channel` VARCHAR(20) NOT NULL DEFAULT 'auto',
  `meeting_point` VARCHAR(500) NULL DEFAULT NULL,
  `contact_phone` VARCHAR(40) NULL DEFAULT NULL,
  `message_template` TEXT NULL DEFAULT NULL,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `bookings` ADD COLUMN IF NOT EXISTS `reminder_sent_at` DATETIME NULL DEFAULT NULL;
ALTER TABLE `bookings` ADD COLUMN IF NOT EXISTS `reminder_status` VARCHAR(20) NULL DEFAULT NULL;
ALTER TABLE `bookings` ADD COLUMN IF NOT EXISTS `reminder_error` VARCHAR(255) NULL DEFAULT NULL;
ALTER TABLE `bookings` ADD INDEX IF NOT EXISTS `idx_bookings_status_date` (`status`, `start_date`);
