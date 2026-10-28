-- Apply on staging first; legacy inventory remains in booking units until explicitly switched.
-- Runner-safe version: no DELIMITER, no stored procedures. One plain statement per line/terminator.
CREATE TABLE IF NOT EXISTS booking_product_settings (
 product_id INT NOT NULL PRIMARY KEY, user_id INT NOT NULL,
 capacity_mode VARCHAR(12) NOT NULL DEFAULT 'booking',
 child_price DECIMAL(12,2) NOT NULL DEFAULT 0, children_use_seat TINYINT NOT NULL DEFAULT 1,
 cancellation_hours INT NOT NULL DEFAULT 24, refund_percent DECIMAL(5,2) NOT NULL DEFAULT 0,
 updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 INDEX idx_owner (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS workspace_operations_settings (
 user_id INT NOT NULL PRIMARY KEY, timezone VARCHAR(64) NOT NULL DEFAULT 'Africa/Cairo',
 updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS booking_product_extras (
 id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, product_id INT NOT NULL, user_id INT NOT NULL,
 name VARCHAR(150) NOT NULL, price DECIMAL(12,2) NOT NULL, price_mode VARCHAR(12) NOT NULL DEFAULT 'booking',
 is_active TINYINT NOT NULL DEFAULT 1, INDEX idx_product (product_id,user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS operations_migrations (name VARCHAR(100) PRIMARY KEY, applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB;
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
CREATE TABLE IF NOT EXISTS `booking_payment_links` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `token` VARCHAR(40) NOT NULL,
  `booking_id` INT NOT NULL,
  `user_id` INT NOT NULL,
  `kind` VARCHAR(10) NOT NULL DEFAULT 'deposit',
  `amount` DECIMAL(12,2) NOT NULL,
  `currency` VARCHAR(10) NOT NULL DEFAULT 'USD',
  `status` VARCHAR(12) NOT NULL DEFAULT 'active',
  `expires_at` DATETIME NOT NULL,
  `paid_at` DATETIME NULL DEFAULT NULL,
  `views` INT NOT NULL DEFAULT 0,
  `created_by` INT NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_payment_link_token` (`token`),
  KEY `idx_payment_link_booking` (`booking_id`),
  KEY `idx_payment_link_user` (`user_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===== Additive columns (add only if missing; safe to re-run) =====
-- booking_reminder_settings.language
SET @s = (SELECT IF(COUNT(*)=0, 'ALTER TABLE `booking_reminder_settings` ADD COLUMN `language` VARCHAR(5) NOT NULL DEFAULT ''auto''', 'DO 0') FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='booking_reminder_settings' AND column_name='language');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;
-- booking_reminder_settings.message_template_en
SET @s = (SELECT IF(COUNT(*)=0, 'ALTER TABLE `booking_reminder_settings` ADD COLUMN `message_template_en` TEXT NULL', 'DO 0') FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='booking_reminder_settings' AND column_name='message_template_en');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;
-- bookings.amount_paid
SET @s = (SELECT IF(COUNT(*)=0, 'ALTER TABLE `bookings` ADD COLUMN `amount_paid` DECIMAL(12,2) NOT NULL DEFAULT 0', 'DO 0') FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='bookings' AND column_name='amount_paid');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;
-- bookings.inventory_units
SET @s = (SELECT IF(COUNT(*)=0, 'ALTER TABLE `bookings` ADD COLUMN `inventory_units` INT NOT NULL DEFAULT 1', 'DO 0') FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='bookings' AND column_name='inventory_units');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;
-- bookings.pricing_json
SET @s = (SELECT IF(COUNT(*)=0, 'ALTER TABLE `bookings` ADD COLUMN `pricing_json` JSON NULL', 'DO 0') FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='bookings' AND column_name='pricing_json');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;
-- bookings.public_request_token
SET @s = (SELECT IF(COUNT(*)=0, 'ALTER TABLE `bookings` ADD COLUMN `public_request_token` VARCHAR(32) NULL', 'DO 0') FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='bookings' AND column_name='public_request_token');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;
-- bookings.public_request_fingerprint
SET @s = (SELECT IF(COUNT(*)=0, 'ALTER TABLE `bookings` ADD COLUMN `public_request_fingerprint` VARCHAR(64) NULL', 'DO 0') FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='bookings' AND column_name='public_request_fingerprint');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;
-- bookings.ticket_token
SET @s = (SELECT IF(COUNT(*)=0, 'ALTER TABLE `bookings` ADD COLUMN `ticket_token` VARCHAR(32) NULL', 'DO 0') FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='bookings' AND column_name='ticket_token');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;
-- bookings.checked_in_at
SET @s = (SELECT IF(COUNT(*)=0, 'ALTER TABLE `bookings` ADD COLUMN `checked_in_at` DATETIME NULL', 'DO 0') FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='bookings' AND column_name='checked_in_at');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;
-- bookings.reminder_attempts
SET @s = (SELECT IF(COUNT(*)=0, 'ALTER TABLE `bookings` ADD COLUMN `reminder_attempts` INT NOT NULL DEFAULT 0', 'DO 0') FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='bookings' AND column_name='reminder_attempts');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;
-- bookings.reminder_claimed_at
SET @s = (SELECT IF(COUNT(*)=0, 'ALTER TABLE `bookings` ADD COLUMN `reminder_claimed_at` DATETIME NULL', 'DO 0') FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='bookings' AND column_name='reminder_claimed_at');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;
-- bookings.reminder_next_attempt_at
SET @s = (SELECT IF(COUNT(*)=0, 'ALTER TABLE `bookings` ADD COLUMN `reminder_next_attempt_at` DATETIME NULL', 'DO 0') FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='bookings' AND column_name='reminder_next_attempt_at');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;
-- bookings.reminder_sent_at
SET @s = (SELECT IF(COUNT(*)=0, 'ALTER TABLE `bookings` ADD COLUMN `reminder_sent_at` DATETIME NULL', 'DO 0') FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='bookings' AND column_name='reminder_sent_at');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;
-- bookings.reminder_status
SET @s = (SELECT IF(COUNT(*)=0, 'ALTER TABLE `bookings` ADD COLUMN `reminder_status` VARCHAR(20) NULL', 'DO 0') FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='bookings' AND column_name='reminder_status');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;
-- bookings.reminder_error
SET @s = (SELECT IF(COUNT(*)=0, 'ALTER TABLE `bookings` ADD COLUMN `reminder_error` VARCHAR(255) NULL', 'DO 0') FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='bookings' AND column_name='reminder_error');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;
-- workspace_operations_settings.reminder_last_scan_at
SET @s = (SELECT IF(COUNT(*)=0, 'ALTER TABLE `workspace_operations_settings` ADD COLUMN `reminder_last_scan_at` DATETIME NULL', 'DO 0') FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='workspace_operations_settings' AND column_name='reminder_last_scan_at');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;
-- refunds.gateway_request_started_at
SET @s = (SELECT IF(COUNT(*)=0, 'ALTER TABLE `refunds` ADD COLUMN `gateway_request_started_at` DATETIME NULL', 'DO 0') FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='refunds' AND column_name='gateway_request_started_at');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;

-- ===== Indexes (add only if missing; safe to re-run) =====
SET @s = (SELECT IF(COUNT(*)=0, 'ALTER TABLE `bookings` ADD UNIQUE KEY `uq_booking_ticket_token` (`ticket_token`)', 'DO 0') FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='bookings' AND index_name='uq_booking_ticket_token');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;
SET @s = (SELECT IF(COUNT(*)=0, 'ALTER TABLE `bookings` ADD UNIQUE KEY `uq_booking_public_request` (`user_id`,`public_request_token`)', 'DO 0') FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='bookings' AND index_name='uq_booking_public_request');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;
SET @s = (SELECT IF(COUNT(*)=0, 'ALTER TABLE `bookings` ADD KEY `idx_booking_reminders_due` (`user_id`,`start_date`,`status`,`reminder_sent_at`)', 'DO 0') FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='bookings' AND index_name='idx_booking_reminders_due');
PREPARE st FROM @s;
EXECUTE st;
DEALLOCATE PREPARE st;

INSERT IGNORE INTO operations_migrations(name) VALUES('2026_10_10_operations_upgrade');
