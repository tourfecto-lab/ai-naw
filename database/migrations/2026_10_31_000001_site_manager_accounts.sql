-- ============================================================
-- Tourfecto - Per-site manager accounts
-- A site owner can give a client / agency / employee a username + password that opens ONLY that site's dashboard
-- (bookings, contact requests, reviews) at /site-admin, without a Tourfecto account.
-- Hand-applied on the server (no migration runner); safe to run twice.
-- ============================================================

CREATE TABLE IF NOT EXISTS `site_manager_accounts` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `website_id` INT(11) NOT NULL COMMENT 'generated_websites.id - the only site this account can open',
  `owner_user_id` INT(11) NOT NULL COMMENT 'the Tourfecto account that owns the site and created this login',
  `username` VARCHAR(60) NOT NULL,
  `display_name` VARCHAR(120) NOT NULL DEFAULT '',
  `password_hash` VARCHAR(255) NOT NULL,
  `role` ENUM('manager','editor','viewer') NOT NULL DEFAULT 'viewer' COMMENT 'manager: bookings+leads+reviews actions; editor: leads+reviews actions; viewer: read only',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `failed_attempts` INT(11) NOT NULL DEFAULT 0,
  `locked_until` DATETIME NULL DEFAULT NULL,
  `last_login_at` DATETIME NULL DEFAULT NULL,
  `last_login_ip` VARCHAR(45) NULL DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_site_manager_username` (`username`),
  KEY `idx_site_manager_site` (`website_id`),
  KEY `idx_site_manager_owner` (`owner_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Logins that open one generated website dashboard only';
