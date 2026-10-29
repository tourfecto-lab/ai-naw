-- ============================================================
-- Tourfecto - Discount coupons for generated websites' online bookings
-- The discount is applied server-side inside BookingEngine::createBooking (and the public quote), so bookings.total_amount
-- already holds the discounted total and every payment / refund / commission path stays consistent.
-- Hand-applied on the server; safe to run twice.
-- ============================================================

CREATE TABLE IF NOT EXISTS `site_coupons` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `website_id` INT(11) NOT NULL,
  `owner_user_id` INT(11) NOT NULL,
  `code` VARCHAR(30) NOT NULL COMMENT 'upper-case A-Z 0-9 _ -',
  `type` ENUM('percent','fixed') NOT NULL DEFAULT 'percent',
  `value` DECIMAL(12,2) NOT NULL,
  `min_total` DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'smallest booking total the coupon applies to',
  `max_uses` INT(11) NULL DEFAULT NULL COMMENT 'NULL = unlimited',
  `used_count` INT(11) NOT NULL DEFAULT 0,
  `valid_from` DATE NULL DEFAULT NULL,
  `valid_until` DATE NULL DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_site_coupon_code` (`website_id`, `code`),
  KEY `idx_site_coupon_owner` (`owner_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Booking discount codes per generated website';
