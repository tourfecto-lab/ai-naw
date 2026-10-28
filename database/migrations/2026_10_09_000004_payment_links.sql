-- =====================================================================
-- Tourfecto - 2026_10_09_000004: لينك دفع (عربون أو المبلغ كله) لحجز معيّن
--   صاحب الشركة يبعته للعميل على الواتساب/الشات، والعميل يدفع والحجز يتأكد لوحده
-- =====================================================================
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

ALTER TABLE `bookings` ADD COLUMN IF NOT EXISTS `amount_paid` DECIMAL(12,2) NOT NULL DEFAULT 0;
