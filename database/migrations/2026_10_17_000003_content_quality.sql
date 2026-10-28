-- Additive Phase 4 state; no customer content or existing tables are replaced.
CREATE TABLE IF NOT EXISTS seo_content_quality (
 id INT AUTO_INCREMENT PRIMARY KEY, website_id INT NOT NULL, user_id INT NOT NULL,
 source_key VARCHAR(100) NOT NULL, article_id INT NULL, task_id INT NULL,
 brief_json MEDIUMTEXT NOT NULL, faqs_json TEXT NOT NULL,
 title VARCHAR(255) NOT NULL DEFAULT '', description TEXT NULL,
 page_url VARCHAR(2000) NULL, pillar_url VARCHAR(2000) NULL, pillar_title VARCHAR(255) NULL,
 published_at DATETIME NULL, seo_state VARCHAR(40) NOT NULL DEFAULT 'pending', seo_error VARCHAR(500) NULL,
 created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL,
 UNIQUE KEY site_source(website_id,source_key), KEY site_published(website_id,published_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS seo_content_refresh_tasks (
 id INT AUTO_INCREMENT PRIMARY KEY, website_id INT NOT NULL, user_id INT NOT NULL,
 page_hash CHAR(64) NOT NULL, page_url VARCHAR(2000) NOT NULL, week_start DATE NOT NULL,
 brief_json MEDIUMTEXT NOT NULL, status VARCHAR(30) NOT NULL DEFAULT 'pending',
 created_at DATETIME NOT NULL, UNIQUE KEY page_week(website_id,page_hash,week_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
