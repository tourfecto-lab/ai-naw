-- Run once in phpMyAdmin after backup. Stores only sync status, not access tokens.
CREATE TABLE IF NOT EXISTS social_meta_history_sync (
 connection_id INT NOT NULL PRIMARY KEY,
 user_id BIGINT UNSIGNED NOT NULL,
 platform VARCHAR(16) NOT NULL,
 status ENUM('pending','running','success','error') NOT NULL DEFAULT 'pending',
 last_attempt_at DATETIME NULL,
 last_success_at DATETIME NULL,
 last_count INT UNSIGNED NOT NULL DEFAULT 0,
 last_error VARCHAR(500) NULL,
 INDEX idx_attempt (last_attempt_at),
 INDEX idx_owner (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
