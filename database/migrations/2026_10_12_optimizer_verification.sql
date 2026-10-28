CREATE TABLE IF NOT EXISTS edge_fix_verifications (
 applied_fix_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
 website_id BIGINT UNSIGNED NOT NULL,
 user_id BIGINT UNSIGNED NOT NULL,
 before_json MEDIUMTEXT NULL,
 expected_value MEDIUMTEXT NOT NULL,
 state VARCHAR(32) NOT NULL DEFAULT 'saved',
 observed_json MEDIUMTEXT NULL,
 reason VARCHAR(500) NULL,
 checked_at DATETIME NULL,
 KEY edge_verification_owner(user_id,website_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
