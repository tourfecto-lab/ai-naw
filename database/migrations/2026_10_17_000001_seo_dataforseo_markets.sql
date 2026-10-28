-- Phase 2: run outside a transaction; repeatable MySQL 5.7+ migration.

CREATE TABLE IF NOT EXISTS `dataforseo_calls` (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, endpoint VARCHAR(160) NOT NULL, cost DECIMAL(14,6) NOT NULL, reserved_cost DECIMAL(14,6) NOT NULL DEFAULT 0, website_id INT NOT NULL, status VARCHAR(24) NOT NULL DEFAULT 'pending', created_at DATETIME NOT NULL, KEY month_cost(created_at), KEY site_calls(website_id,created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `seo_markets` (id INT AUTO_INCREMENT PRIMARY KEY, website_id INT NOT NULL, location_code INT NOT NULL, language_code VARCHAR(12) NOT NULL, label VARCHAR(100) NOT NULL, is_primary TINYINT NOT NULL DEFAULT 0, UNIQUE KEY market(website_id,location_code,language_code)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `seo_keyword_research` (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, website_id INT NOT NULL, market_id INT NOT NULL, keyword VARCHAR(255) NOT NULL, volume INT NULL, difficulty DECIMAL(5,2) NULL, cpc DECIMAL(12,4) NULL, intent VARCHAR(40) NULL, source VARCHAR(32) NOT NULL DEFAULT 'dataforseo', fetched_at DATETIME NOT NULL, UNIQUE KEY research(website_id,market_id,keyword)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `seo_research_cache` (website_id INT NOT NULL, market_id INT NOT NULL, cache_key CHAR(64) NOT NULL, result_json MEDIUMTEXT NOT NULL, fetched_at DATETIME NOT NULL, PRIMARY KEY(website_id,market_id,cache_key)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `seo_provider_alerts` (alert_key VARCHAR(100) PRIMARY KEY, created_at DATETIME NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tracked_keywords' AND COLUMN_NAME='market_id')=0, 'ALTER TABLE `tracked_keywords` ADD COLUMN `market_id` INT NULL', 'SELECT 1');
PREPARE s FROM @s; EXECUTE s; DEALLOCATE PREPARE s;

SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tracked_keywords' AND COLUMN_NAME='impressions')=0, 'ALTER TABLE `tracked_keywords` ADD COLUMN `impressions` INT NULL', 'SELECT 1');
PREPARE s FROM @s; EXECUTE s; DEALLOCATE PREPARE s;

SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tracked_keywords' AND COLUMN_NAME='gsc_position')=0, 'ALTER TABLE `tracked_keywords` ADD COLUMN `gsc_position` DECIMAL(8,2) NULL', 'SELECT 1');
PREPARE s FROM @s; EXECUTE s; DEALLOCATE PREPARE s;

SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tracked_keywords' AND COLUMN_NAME='gsc_checked_at')=0, 'ALTER TABLE `tracked_keywords` ADD COLUMN `gsc_checked_at` DATETIME NULL', 'SELECT 1');
PREPARE s FROM @s; EXECUTE s; DEALLOCATE PREPARE s;

SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='seo_rank_tracking_history' AND COLUMN_NAME='market_id')=0, 'ALTER TABLE `seo_rank_tracking_history` ADD COLUMN `market_id` INT NULL', 'SELECT 1');
PREPARE s FROM @s; EXECUTE s; DEALLOCATE PREPARE s;

SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='competitor_keywords' AND COLUMN_NAME='market_id')=0, 'ALTER TABLE `competitor_keywords` ADD COLUMN `market_id` INT NULL', 'SELECT 1');
PREPARE s FROM @s; EXECUTE s; DEALLOCATE PREPARE s;

SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='competitor_keywords' AND COLUMN_NAME='position')=0, 'ALTER TABLE `competitor_keywords` ADD COLUMN `position` SMALLINT NULL', 'SELECT 1');
PREPARE s FROM @s; EXECUTE s; DEALLOCATE PREPARE s;

SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='competitor_keywords' AND COLUMN_NAME='url')=0, 'ALTER TABLE `competitor_keywords` ADD COLUMN `url` VARCHAR(1000) NULL', 'SELECT 1');
PREPARE s FROM @s; EXECUTE s; DEALLOCATE PREPARE s;

SET @s = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='competitor_keywords' AND COLUMN_NAME='fetched_at')=0, 'ALTER TABLE `competitor_keywords` ADD COLUMN `fetched_at` DATETIME NULL', 'SELECT 1');
PREPARE s FROM @s; EXECUTE s; DEALLOCATE PREPARE s;

SET @s = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tracked_keywords' AND INDEX_NAME='uniq_market_keyword')=0, 'ALTER TABLE tracked_keywords ADD UNIQUE KEY uniq_market_keyword (website_id,market_id,keyword)', 'SELECT 1');
PREPARE s FROM @s; EXECUTE s; DEALLOCATE PREPARE s;

SET @s = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tracked_keywords' AND INDEX_NAME='uniq_website_keyword')>0, 'ALTER TABLE tracked_keywords DROP INDEX uniq_website_keyword', 'SELECT 1');
PREPARE s FROM @s; EXECUTE s; DEALLOCATE PREPARE s;

INSERT INTO pay_per_use_pricing (feature_key,label,price,currency,currency_symbol,is_active) VALUES ('seo_data','قياس كلمة SEO',0.05,'USD','$',1) ON DUPLICATE KEY UPDATE feature_key=feature_key;
