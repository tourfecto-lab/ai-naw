-- Tourfecto service reliability migration. Apply before replacing PHP files.
-- Run on the supplied/current schema; do not import the old full database dump.
CREATE TABLE IF NOT EXISTS external_execution_attempts (
 operation_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 user_id BIGINT UNSIGNED NOT NULL, operation_label VARCHAR(190) NOT NULL,
 state ENUM('started','completed','failed','uncertain') NOT NULL,
 result_json LONGTEXT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL,
 INDEX(user_id,state,updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS service_usage_reservations (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, token CHAR(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
 user_id BIGINT UNSIGNED NOT NULL, owner_user_id BIGINT UNSIGNED NOT NULL, feature_key VARCHAR(100) NOT NULL,
 source ENUM('plan','wallet') NOT NULL, amount DECIMAL(12,2) NOT NULL DEFAULT 0, usage_event_id BIGINT UNSIGNED NULL,
 state ENUM('reserved','completed','released','uncertain') NOT NULL,
 created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, INDEX(owner_user_id,state)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS browser_sessions (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id BIGINT UNSIGNED NOT NULL,
 token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
 refresh_token_id INT NULL, expires_at DATETIME NOT NULL, revoked_at DATETIME NULL,
 created_at DATETIME NOT NULL, last_used_at DATETIME NOT NULL, INDEX(user_id,revoked_at,expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
ALTER TABLE email_campaigns MODIFY status ENUM('draft','scheduled','sending','sent','cancelled','failed','partial','uncertain') NOT NULL DEFAULT 'draft';
ALTER TABLE email_campaign_recipients MODIFY status ENUM('pending','sent','opened','clicked','unsubscribed','bounced','failed','sending','uncertain') NOT NULL DEFAULT 'pending';
ALTER TABLE social_posts MODIFY status ENUM('draft','scheduled','publishing','published','failed','partial','uncertain') NOT NULL DEFAULT 'draft';
ALTER TABLE social_post_targets MODIFY status ENUM('pending','scheduled','publishing','published','failed','uncertain') NOT NULL DEFAULT 'pending';
ALTER TABLE ad_pending_actions MODIFY status ENUM('pending','approved','rejected','processing','executed','failed','uncertain') NOT NULL DEFAULT 'pending';

SET @tf_sql=(SELECT IF(COUNT(*)=0,'ALTER TABLE bookings ADD COLUMN end_date DATE NULL','SELECT 1') FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='bookings' AND column_name='end_date');
PREPARE tf_stmt FROM @tf_sql; EXECUTE tf_stmt; DEALLOCATE PREPARE tf_stmt;

CREATE TABLE IF NOT EXISTS site_custom_domains (
 generated_website_id BIGINT UNSIGNED PRIMARY KEY,user_id BIGINT UNSIGNED NOT NULL,
 domain VARCHAR(253) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
 verification_token CHAR(48) CHARACTER SET ascii NOT NULL,state ENUM('pending_dns','verified') NOT NULL DEFAULT 'pending_dns',verified_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ota_revenue_bindings(booking_key CHAR(64) CHARACTER SET ascii PRIMARY KEY,user_id BIGINT UNSIGNED NOT NULL,platform VARCHAR(30) NOT NULL,account_id VARCHAR(190) NOT NULL,external_reference VARCHAR(255) NOT NULL,amount DECIMAL(12,2) NOT NULL,currency CHAR(3) NOT NULL,refunded_amount DECIMAL(12,2) NOT NULL DEFAULT 0,INDEX(user_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS ota_refund_events(event_key CHAR(64) CHARACTER SET ascii PRIMARY KEY,booking_key CHAR(64) CHARACTER SET ascii NOT NULL,amount DECIMAL(12,2) NOT NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,INDEX(booking_key)) ENGINE=InnoDB;

SET @tf_sql=(SELECT IF(COUNT(*)=0,'ALTER TABLE rev_revenue_records ADD COLUMN product_id BIGINT UNSIGNED NULL','SELECT 1') FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='rev_revenue_records' AND column_name='product_id');
PREPARE tf_stmt FROM @tf_sql; EXECUTE tf_stmt; DEALLOCATE PREPARE tf_stmt;

SET @tf_sql=(SELECT IF(COUNT(*)=0,'ALTER TABLE rev_revenue_records ADD COLUMN customer_id BIGINT UNSIGNED NULL','SELECT 1') FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='rev_revenue_records' AND column_name='customer_id');
PREPARE tf_stmt FROM @tf_sql; EXECUTE tf_stmt; DEALLOCATE PREPARE tf_stmt;

SET @tf_sql=(SELECT IF(COUNT(*)=0,'ALTER TABLE rev_revenue_records ADD COLUMN booking_id BIGINT UNSIGNED NULL','SELECT 1') FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='rev_revenue_records' AND column_name='booking_id');
PREPARE tf_stmt FROM @tf_sql; EXECUTE tf_stmt; DEALLOCATE PREPARE tf_stmt;

CREATE TABLE IF NOT EXISTS service_usage_reviews(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,reservation_token CHAR(48) CHARACTER SET ascii NOT NULL UNIQUE,admin_id BIGINT UNSIGNED NOT NULL,previous_state VARCHAR(16) NOT NULL,outcome VARCHAR(16) NOT NULL,evidence TEXT NOT NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
