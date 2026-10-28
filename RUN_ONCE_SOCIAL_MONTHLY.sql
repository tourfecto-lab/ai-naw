-- Additive; preserves all existing posts and connections. MySQL/MariaDB, InnoDB.
CREATE TABLE IF NOT EXISTS social_monthly_plans (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 user_id BIGINT UNSIGNED NOT NULL,
 website_id BIGINT UNSIGNED NOT NULL,
 request_key VARCHAR(80) NOT NULL,
 month_key CHAR(7) NOT NULL,
 timezone VARCHAR(64) NOT NULL,
 language VARCHAR(8) NOT NULL,
 brief_json LONGTEXT NOT NULL,
 strategy_json LONGTEXT NULL,
 status VARCHAR(24) NOT NULL DEFAULT 'generating',
 version INT UNSIGNED NOT NULL DEFAULT 1,
 usage_token VARCHAR(64) NULL,
 error_message VARCHAR(500) NULL,
 approved_at DATETIME NULL,
 created_at DATETIME NOT NULL,
 updated_at DATETIME NOT NULL,
 PRIMARY KEY (id),
 UNIQUE KEY uq_social_monthly_request (user_id,request_key),
 KEY ix_social_monthly_owner (user_id,website_id,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS social_monthly_plan_items (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 plan_id BIGINT UNSIGNED NOT NULL,
 slot_number INT UNSIGNED NOT NULL,
 connection_id BIGINT UNSIGNED NOT NULL,
 platform VARCHAR(24) NOT NULL,
 theme VARCHAR(200) NOT NULL,
 objective VARCHAR(200) NOT NULL,
 content TEXT NOT NULL,
 visual_brief TEXT NOT NULL,
 media_item_id BIGINT UNSIGNED NOT NULL,
 scheduled_at DATETIME NOT NULL,
 social_post_id BIGINT UNSIGNED NULL,
 target_id BIGINT UNSIGNED NULL,
 job_id BIGINT UNSIGNED NULL,
 state VARCHAR(24) NOT NULL DEFAULT 'draft',
 created_at DATETIME NOT NULL,
 updated_at DATETIME NOT NULL,
 PRIMARY KEY (id),
 UNIQUE KEY uq_social_monthly_slot (plan_id,slot_number,connection_id),
 UNIQUE KEY uq_social_monthly_target (target_id),
 KEY ix_social_monthly_post (social_post_id),
 CONSTRAINT fk_social_monthly_item_plan FOREIGN KEY (plan_id) REFERENCES social_monthly_plans(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Metrics are shared with the existing Social screen; keep that screen's permalink insert compatible.
CREATE TABLE IF NOT EXISTS social_post_metrics (
 target_id INT(11) NOT NULL,
 likes INT(11) NOT NULL DEFAULT 0,
 comments INT(11) NOT NULL DEFAULT 0,
 shares INT(11) NOT NULL DEFAULT 0,
 reach INT(11) NULL,
 permalink VARCHAR(1000) NULL,
 state VARCHAR(20) NOT NULL DEFAULT 'ok',
 note VARCHAR(300) NULL,
 fetched_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY(target_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SET @tf_smp_sql=(SELECT IF(COUNT(*)=0,'ALTER TABLE social_post_metrics ADD COLUMN permalink VARCHAR(1000) NULL','SELECT 1') FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='social_post_metrics' AND column_name='permalink');
PREPARE tf_smp_stmt FROM @tf_smp_sql;
EXECUTE tf_smp_stmt;
DEALLOCATE PREPARE tf_smp_stmt;
