-- Latest service completion delta only. Apply after all previously delivered migrations.
CREATE TABLE IF NOT EXISTS chat_approved_deliveries (
 message_id BIGINT UNSIGNED PRIMARY KEY,user_id BIGINT UNSIGNED NOT NULL,
 state ENUM('ready','sending','sent','failed','uncertain') NOT NULL DEFAULT 'ready',
 attempts INT UNSIGNED NOT NULL DEFAULT 0,external_id VARCHAR(255) NULL,
 last_error VARCHAR(500) NULL,evidence TEXT NULL,reply_hash CHAR(64) NULL,
 created_at DATETIME NOT NULL,updated_at DATETIME NOT NULL,
 INDEX(user_id,state,updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS booking_refund_requests (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,user_id BIGINT UNSIGNED NOT NULL,
 booking_id BIGINT UNSIGNED NOT NULL,transaction_id BIGINT UNSIGNED NOT NULL,
 request_key CHAR(64) CHARACTER SET ascii NOT NULL UNIQUE,
 amount DECIMAL(14,2) NOT NULL,currency CHAR(3) NOT NULL,
 state ENUM('review','processing','succeeded','failed','uncertain','rejected') NOT NULL DEFAULT 'review',
 policy_json TEXT NOT NULL,reason VARCHAR(500) NOT NULL,refund_id BIGINT UNSIGNED NULL,
 created_by BIGINT UNSIGNED NOT NULL,reviewed_by BIGINT UNSIGNED NULL,
 created_at DATETIME NOT NULL,updated_at DATETIME NOT NULL,
 INDEX(user_id,state,created_at),INDEX(booking_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS agency_domain_setups (
 agency_id BIGINT UNSIGNED PRIMARY KEY,user_id BIGINT UNSIGNED NOT NULL,
 domain VARCHAR(253) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
 verification_token CHAR(48) CHARACTER SET ascii NOT NULL,
 dns_state VARCHAR(30) NOT NULL DEFAULT 'pending_dns',https_state VARCHAR(30) NOT NULL DEFAULT 'not_checked',
 last_checked_at DATETIME NULL,verified_at DATETIME NULL,
 created_at DATETIME NOT NULL,updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS service_center_checks (
 user_id BIGINT UNSIGNED NOT NULL,service_key VARCHAR(64) CHARACTER SET ascii NOT NULL,
 check_key VARCHAR(64) CHARACTER SET ascii NOT NULL,evidence VARCHAR(500) NOT NULL,
 checked_by BIGINT UNSIGNED NOT NULL,checked_at DATETIME NOT NULL,
 PRIMARY KEY(user_id,service_key,check_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS hotel_rate_rules (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,user_id BIGINT UNSIGNED NOT NULL,product_id BIGINT UNSIGNED NOT NULL,
 name VARCHAR(100) NOT NULL,date_from DATE NOT NULL,date_to DATE NOT NULL,
 nightly_price DECIMAL(14,2) NOT NULL,min_nights INT UNSIGNED NOT NULL DEFAULT 1,
 created_at DATETIME NOT NULL,INDEX(user_id,product_id,date_from,date_to)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
