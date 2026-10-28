-- Apply after the two earlier repair migrations. No legacy content is translated in place.
CREATE TABLE IF NOT EXISTS website_content_policies (
 website_id BIGINT UNSIGNED PRIMARY KEY,user_id BIGINT UNSIGNED NOT NULL,
 output_language ENUM('auto','en','ar') NOT NULL DEFAULT 'auto',
 competitor_auto_apply TINYINT(1) NOT NULL DEFAULT 0,updated_at DATETIME NOT NULL,
 INDEX(user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS competitor_site_executions (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,user_id BIGINT UNSIGNED NOT NULL,
 website_id BIGINT UNSIGNED NOT NULL,competitor_id BIGINT UNSIGNED NOT NULL,
 execution_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
 status ENUM('queued','running','prepared','applied','failed','uncertain','rolled_back') NOT NULL,
 output_language VARCHAR(10) NOT NULL,target_kind ENUM('builder','connected') NOT NULL,
 generated_website_id BIGINT UNSIGNED NULL,baseline_json LONGTEXT NOT NULL,
 patch_json LONGTEXT NULL,usage_token CHAR(48) CHARACTER SET ascii NULL,
 job_id BIGINT UNSIGNED NULL,is_active TINYINT(1) NOT NULL DEFAULT 0,
 error_message VARCHAR(255) NULL,created_at DATETIME NOT NULL,updated_at DATETIME NOT NULL,
 INDEX(website_id,is_active),INDEX(user_id,status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
