CREATE TABLE IF NOT EXISTS seo_expert_sites (
 website_id BIGINT UNSIGNED NOT NULL,user_id BIGINT UNSIGNED NOT NULL,brief_json LONGTEXT NOT NULL,policy_json LONGTEXT NOT NULL,
 status VARCHAR(24) NOT NULL DEFAULT 'draft',version INT UNSIGNED NOT NULL DEFAULT 1,last_run_id BIGINT UNSIGNED NULL,
 budget_month CHAR(7) NOT NULL,cycles_used INT UNSIGNED NOT NULL DEFAULT 0,next_due_at DATETIME NULL,
 created_at DATETIME NOT NULL,updated_at DATETIME NOT NULL,PRIMARY KEY(website_id),KEY ix_se_site_owner(user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS seo_expert_runs (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,website_id BIGINT UNSIGNED NOT NULL,user_id BIGINT UNSIGNED NOT NULL,
 request_key VARCHAR(80) NOT NULL,status VARCHAR(24) NOT NULL DEFAULT 'queued',version INT UNSIGNED NOT NULL DEFAULT 1,
 snapshot_json LONGTEXT NULL,plan_json LONGTEXT NULL,summary TEXT NULL,usage_token VARCHAR(64) NULL,error_message VARCHAR(500) NULL,
 approved_at DATETIME NULL,created_at DATETIME NOT NULL,updated_at DATETIME NOT NULL,
 PRIMARY KEY(id),UNIQUE KEY uq_se_run_request(user_id,request_key),KEY ix_se_runs_site(website_id,user_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS seo_expert_tasks (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,run_id BIGINT UNSIGNED NOT NULL,website_id BIGINT UNSIGNED NOT NULL,user_id BIGINT UNSIGNED NOT NULL,
 kind VARCHAR(24) NOT NULL,category VARCHAR(12) NOT NULL,priority VARCHAR(12) NOT NULL DEFAULT 'medium',
 page_url VARCHAR(1800) NOT NULL,page_path VARCHAR(1000) NOT NULL,title VARCHAR(250) NOT NULL,instructions TEXT NOT NULL,
 field_name VARCHAR(24) NULL,expected_value LONGTEXT NULL,before_json LONGTEXT NULL,evidence_json LONGTEXT NULL,
 status VARCHAR(24) NOT NULL DEFAULT 'pending',is_active TINYINT NOT NULL DEFAULT 0,previous_task_id BIGINT UNSIGNED NULL,
 external_post_id BIGINT UNSIGNED NULL,published_url VARCHAR(1800) NULL,checked_at DATETIME NULL,
 created_at DATETIME NOT NULL,updated_at DATETIME NOT NULL,PRIMARY KEY(id),KEY ix_se_task_run(run_id),
 KEY ix_se_task_site(website_id,is_active),CONSTRAINT fk_se_task_run FOREIGN KEY(run_id) REFERENCES seo_expert_runs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
