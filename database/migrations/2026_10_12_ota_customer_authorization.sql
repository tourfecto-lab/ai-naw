-- New table only. No changes to existing customer or supplier records.
CREATE TABLE IF NOT EXISTS ota_customer_authorizations (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 user_id BIGINT UNSIGNED NOT NULL,
 website_id BIGINT UNSIGNED NOT NULL,
 platform VARCHAR(32) NOT NULL,
 provider_domain VARCHAR(63) NOT NULL,
 account_id VARCHAR(190) NOT NULL,
 token_encrypted TEXT NOT NULL,
 scopes VARCHAR(1000) NOT NULL,
 connected_at DATETIME NOT NULL,
 UNIQUE KEY ota_customer_website_platform(user_id,website_id,platform),
 KEY ota_customer_account(platform,account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
