-- Create once. Does not alter existing posts or credentials.
CREATE TABLE IF NOT EXISTS social_meta_history (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 user_id BIGINT UNSIGNED NOT NULL,
 connection_id INT NOT NULL,
 platform VARCHAR(16) NOT NULL,
 external_post_id VARCHAR(190) NOT NULL,
 content MEDIUMTEXT NULL,
 media_type VARCHAR(30) NULL,
 media_url TEXT NULL,
 permalink TEXT NULL,
 published_at DATETIME NULL,
 likes BIGINT UNSIGNED NULL,
 comments BIGINT UNSIGNED NULL,
 shares BIGINT UNSIGNED NULL,
 fetched_at DATETIME NOT NULL,
 UNIQUE KEY uq_connection_post (connection_id, external_post_id),
 INDEX idx_owner_date (user_id, published_at),
 INDEX idx_connection_date (connection_id, published_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
