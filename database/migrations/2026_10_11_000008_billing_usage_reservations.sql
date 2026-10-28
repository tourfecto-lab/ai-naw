-- Run BEFORE uploading this patch. Idempotent, no existing records changed.
CREATE TABLE IF NOT EXISTS billing_usage_reservations (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 token VARCHAR(64) NOT NULL UNIQUE,
 user_id BIGINT UNSIGNED NOT NULL,
 subscription_id BIGINT UNSIGNED NULL,
 feature VARCHAR(40) NOT NULL,
 quantity INT UNSIGNED NOT NULL,
 source VARCHAR(16) NOT NULL,
 amount DECIMAL(14,2) NOT NULL DEFAULT 0,
 quota_anchor DATETIME NULL,
 state VARCHAR(16) NOT NULL DEFAULT 'reserved',
 created_at DATETIME NOT NULL,
 updated_at DATETIME NOT NULL,
 INDEX billing_reservation_owner (user_id,state),
 INDEX billing_reservation_subscription (subscription_id,state)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
