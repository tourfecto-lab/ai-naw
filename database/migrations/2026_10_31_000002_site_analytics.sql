-- ============================================================
-- Tourfecto - Generated-site analytics (privacy-safe daily aggregates)
-- No IP, user agent or cookie is stored. Unique visitors are counted from a daily-rotating hash that is deleted after 2 days.
-- Hand-applied on the server; safe to run twice.
-- ============================================================

CREATE TABLE IF NOT EXISTS `site_daily_stats` (
  `website_id` INT(11) NOT NULL,
  `day` DATE NOT NULL,
  `views` INT(11) NOT NULL DEFAULT 0,
  `visitors` INT(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`website_id`, `day`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `site_daily_pages` (
  `website_id` INT(11) NOT NULL,
  `day` DATE NOT NULL,
  `page` VARCHAR(100) NOT NULL COMMENT 'home / tours / about ... / tour:<slug> / room:<slug>',
  `views` INT(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`website_id`, `day`, `page`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `site_daily_sources` (
  `website_id` INT(11) NOT NULL,
  `day` DATE NOT NULL,
  `source` VARCHAR(20) NOT NULL COMMENT 'direct / search / social / ads / other',
  `views` INT(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`website_id`, `day`, `source`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `site_visitor_seen` (
  `website_id` INT(11) NOT NULL,
  `day` DATE NOT NULL,
  `vhash` CHAR(32) NOT NULL,
  PRIMARY KEY (`website_id`, `day`, `vhash`),
  KEY `idx_site_visitor_day` (`day`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='daily-rotating hash, kept 2 days only';
