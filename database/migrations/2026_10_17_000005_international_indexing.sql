CREATE TABLE IF NOT EXISTS builder_language_versions (
 generated_website_id BIGINT UNSIGNED NOT NULL,
 language VARCHAR(20) NOT NULL,
 source_hash CHAR(64) NOT NULL,
 confirmed_by BIGINT UNSIGNED NOT NULL,
 confirmed_at DATETIME NOT NULL,
 fields_json MEDIUMTEXT NOT NULL,
 status VARCHAR(20) NOT NULL DEFAULT 'draft',
 reviewed_by BIGINT UNSIGNED NULL,
 reviewed_at DATETIME NULL,
 updated_at DATETIME NOT NULL,
 PRIMARY KEY(generated_website_id,language)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS seo_sitemap_permissions (
 website_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
 user_id BIGINT UNSIGNED NOT NULL,
 connection_id BIGINT UNSIGNED NOT NULL,
 granted_scope TEXT NOT NULL,
 enabled TINYINT NOT NULL DEFAULT 0,
 sitemap_url VARCHAR(1800) NULL,
 result_json MEDIUMTEXT NULL,
 checked_at DATETIME NULL,
 updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
