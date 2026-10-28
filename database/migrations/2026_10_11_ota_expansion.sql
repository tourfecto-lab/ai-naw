-- OTA supplier connections are separate from legacy affiliate integrations.
CREATE TABLE IF NOT EXISTS ota_supplier_connections (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 user_id BIGINT UNSIGNED NOT NULL, website_id BIGINT UNSIGNED NOT NULL,
 platform VARCHAR(32) NOT NULL, environment VARCHAR(16) NOT NULL DEFAULT 'production',
 account_id VARCHAR(190) NOT NULL DEFAULT '', credentials_encrypted MEDIUMTEXT NULL,
 state VARCHAR(32) NOT NULL DEFAULT 'pending_approval', auto_sync TINYINT NOT NULL DEFAULT 0,
 verified_at DATETIME NULL, last_synced_at DATETIME NULL, next_sync_at DATETIME NULL,
 last_error VARCHAR(255) NULL, approval_reference VARCHAR(190) NOT NULL DEFAULT '',
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY ota_site_platform (website_id,platform), KEY ota_due (auto_sync,next_sync_at), KEY ota_owner (user_id,website_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS ota_supplier_records (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, connection_id BIGINT UNSIGNED NOT NULL,
 kind VARCHAR(24) NOT NULL, external_id VARCHAR(190) COLLATE utf8mb4_bin NOT NULL, summary_json MEDIUMTEXT NOT NULL,
 observed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY ota_record (connection_id,kind,external_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS ota_supplier_product_mappings (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, connection_id BIGINT UNSIGNED NOT NULL,
 external_id VARCHAR(190) COLLATE utf8mb4_bin NOT NULL, product_id INT NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY ota_mapping (connection_id,external_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
