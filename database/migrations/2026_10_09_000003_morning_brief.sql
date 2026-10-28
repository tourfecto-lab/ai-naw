-- =====================================================================
-- Tourfecto - 2026_10_09_000003: ملخص الصبح لصاحب الشركة (واتساب / إيميل)
-- =====================================================================
CREATE TABLE IF NOT EXISTS `morning_brief_settings` (
  `user_id` INT NOT NULL,
  `enabled` TINYINT(1) NOT NULL DEFAULT 0,
  `send_hour` TINYINT NOT NULL DEFAULT 8,
  `channel` VARCHAR(20) NOT NULL DEFAULT 'auto',
  `phone` VARCHAR(40) NULL DEFAULT NULL,
  `skip_quiet_days` TINYINT(1) NOT NULL DEFAULT 1,
  `last_sent_on` DATE NULL DEFAULT NULL,
  `last_status` VARCHAR(60) NULL DEFAULT NULL,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
