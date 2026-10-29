-- ============================================================
-- Tourfecto - Activity log of per-site manager logins (who did what, shown to the site owner)
-- Hand-applied on the server; safe to run twice.
-- ============================================================

CREATE TABLE IF NOT EXISTS `site_manager_activity` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `website_id` INT(11) NOT NULL,
  `account_id` INT(11) NOT NULL,
  `action` VARCHAR(40) NOT NULL COMMENT 'login / booking_confirm / booking_cancel / lead_update / review_update / content_save / password_change',
  `detail` VARCHAR(190) NULL DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_sma_site_time` (`website_id`, `created_at`),
  KEY `idx_sma_account` (`account_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Actions taken through /site-admin';
