-- TOURFECTO AI VOICE & SALES AGENT: per-account access set by the platform admin. A row wins over the plan (enabled = 1 grants, 0 revokes); no row = the plan decides.
CREATE TABLE IF NOT EXISTS voice_feature_grants (
 tenant_user_id INT(11) NOT NULL PRIMARY KEY,
 enabled TINYINT(1) NOT NULL,
 max_numbers SMALLINT UNSIGNED NULL,
 granted_by_user_id INT(11) NULL,
 updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
