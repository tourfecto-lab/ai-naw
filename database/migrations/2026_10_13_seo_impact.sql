-- Additive Phase 4 schema. Select the existing Tourfecto database first. No DROP/ALTER of existing tables.
CREATE TABLE IF NOT EXISTS seo_impact_monitors (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, website_id INT NOT NULL,
 page_url VARCHAR(1800) NOT NULL, page_path VARCHAR(1000) NOT NULL, page_hash CHAR(64) NOT NULL,
 change_date DATE NOT NULL, anchor_source VARCHAR(40) NOT NULL, window_days SMALLINT NOT NULL,
 goal_event VARCHAR(64) NOT NULL DEFAULT '', channel VARCHAR(20) NOT NULL DEFAULT 'organic',
 status VARCHAR(32) NOT NULL DEFAULT 'queued', result_json MEDIUMTEXT NULL, next_due_at DATETIME NULL,
 last_collected_at DATETIME NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL,
 UNIQUE KEY monitor_scope (website_id,user_id,page_hash,change_date,window_days,goal_event,channel), KEY owner_site (user_id,website_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS seo_impact_changes (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,user_id INT NOT NULL,website_id INT NOT NULL,
 external_key VARCHAR(100) NOT NULL,page_url VARCHAR(1800) NOT NULL,change_date DATE NOT NULL,
 source VARCHAR(40) NOT NULL,created_at DATETIME NOT NULL,
 UNIQUE KEY event_scope (website_id,user_id,external_key),KEY owner_site(user_id,website_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS seo_booking_sources (
 website_id INT NOT NULL PRIMARY KEY,user_id INT NOT NULL,secret_enc TEXT NOT NULL,
 source_url VARCHAR(1800) NOT NULL,source_status VARCHAR(20) NOT NULL DEFAULT 'pending',enabled TINYINT NOT NULL DEFAULT 1,
 tracking_started_at DATETIME NOT NULL,last_received_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS seo_paid_booking_events (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,website_id INT NOT NULL,user_id INT NOT NULL,
 booking_key CHAR(64) NOT NULL,source_version BIGINT UNSIGNED NOT NULL,page_path VARCHAR(1000) NULL,
 paid_date DATE NOT NULL,amount DECIMAL(14,2) NOT NULL,currency CHAR(3) NOT NULL,status VARCHAR(20) NOT NULL,
 received_at DATETIME NOT NULL,UNIQUE KEY booking_scope(website_id,booking_key),KEY page_date(website_id,paid_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS seo_ai_observations (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,website_id INT NOT NULL,user_id INT NOT NULL,
 provider VARCHAR(32) NOT NULL,page_path VARCHAR(1000) NOT NULL,page_hash CHAR(64) NOT NULL,
 date_start DATE NOT NULL,date_end DATE NOT NULL,citations INT UNSIGNED NOT NULL,
 source_kind VARCHAR(40) NOT NULL,evidence_url VARCHAR(1800) NOT NULL DEFAULT '',created_at DATETIME NOT NULL,
 UNIQUE KEY observation_scope(website_id,user_id,provider,page_hash,date_start,date_end,source_kind),KEY owner_site(user_id,website_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
