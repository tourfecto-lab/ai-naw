CREATE TABLE IF NOT EXISTS website_audience_preferences (
 website_id BIGINT UNSIGNED PRIMARY KEY, user_id BIGINT UNSIGNED NOT NULL,
 target_countries_json TEXT NOT NULL, target_languages_json TEXT NOT NULL,
 selling_currency CHAR(3) NOT NULL DEFAULT 'USD', updated_at DATETIME NOT NULL,
 KEY owner_idx (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
ALTER TABLE website_content_policies MODIFY output_language VARCHAR(12) NOT NULL DEFAULT 'auto';
ALTER TABLE competitor_site_executions MODIFY output_language VARCHAR(12) NOT NULL;
