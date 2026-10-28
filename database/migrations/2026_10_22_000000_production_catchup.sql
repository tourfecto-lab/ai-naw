-- Tourfecto - تحديث قاعدة البيانات المجمّع (2026-10-22)
-- بيكمّل كل التحديثات اللي ماتطبقتش على السيرفر (البريد، SEO المحلي والدولي، النشر الاجتماعي، واتساب، الطيار الآلي).
-- آمن على MySQL 8 و MariaDB، وآمن للتشغيل أكتر من مرة: كل عمود/فهرس بيتضاف بس لو مش موجود.
-- مفيش أي حذف لبيانات ولا تصغير لأعمدة.
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ===== 2026_10_03_000001_align_schema_with_code =====
SET @__al_has_event := (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'activity_logs' AND COLUMN_NAME = 'event_type');
SET @__al_sql := IF(@__al_has_event > 0,
    'ALTER TABLE `activity_logs` MODIFY COLUMN `event_type` VARCHAR(100) NULL DEFAULT NULL',
    'SELECT 1');
PREPARE __al_stmt FROM @__al_sql;
EXECUTE __al_stmt;
DEALLOCATE PREPARE __al_stmt;
SET @tf_need := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'websites' AND COLUMN_NAME = 'domain');
SET @tf_sql := IF(@tf_need = 0, 'ALTER TABLE `websites` ADD COLUMN `domain` VARCHAR(255) DEFAULT NULL', 'SELECT 1');
PREPARE tf_stmt FROM @tf_sql; EXECUTE tf_stmt; DEALLOCATE PREPARE tf_stmt;
SET @tf_need := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'websites' AND COLUMN_NAME = 'brand_name');
SET @tf_sql := IF(@tf_need = 0, 'ALTER TABLE `websites` ADD COLUMN `brand_name` VARCHAR(255) DEFAULT NULL', 'SELECT 1');
PREPARE tf_stmt FROM @tf_sql; EXECUTE tf_stmt; DEALLOCATE PREPARE tf_stmt;
SET @tf_need := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'websites' AND COLUMN_NAME = 'industry_niche');
SET @tf_sql := IF(@tf_need = 0, 'ALTER TABLE `websites` ADD COLUMN `industry_niche` VARCHAR(100) DEFAULT NULL', 'SELECT 1');
PREPARE tf_stmt FROM @tf_sql; EXECUTE tf_stmt; DEALLOCATE PREPARE tf_stmt;
SET @tf_need := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'websites' AND COLUMN_NAME = 'target_countries');
SET @tf_sql := IF(@tf_need = 0, 'ALTER TABLE `websites` ADD COLUMN `target_countries` VARCHAR(255) DEFAULT NULL', 'SELECT 1');
PREPARE tf_stmt FROM @tf_sql; EXECUTE tf_stmt; DEALLOCATE PREPARE tf_stmt;
SET @tf_need := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'websites' AND COLUMN_NAME = 'is_active');
SET @tf_sql := IF(@tf_need = 0, 'ALTER TABLE `websites` ADD COLUMN `is_active` TINYINT(1) NOT NULL DEFAULT 1', 'SELECT 1');
PREPARE tf_stmt FROM @tf_sql; EXECUTE tf_stmt; DEALLOCATE PREPARE tf_stmt;
SET @tf_need := (SELECT COUNT(*) = 0 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'websites' AND COLUMN_NAME = 'main_url');
SET @tf_sql := IF(@tf_need = 0, 'UPDATE `websites` SET `domain` = `main_url` WHERE `domain` IS NULL AND `main_url` IS NOT NULL', 'SELECT 1');
PREPARE tf_stmt FROM @tf_sql; EXECUTE tf_stmt; DEALLOCATE PREPARE tf_stmt;
SET @tf_need := (SELECT COUNT(*) = 0 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'websites' AND COLUMN_NAME = 'company_name');
SET @tf_sql := IF(@tf_need = 0, 'UPDATE `websites` SET `brand_name` = `company_name` WHERE `brand_name` IS NULL AND `company_name` IS NOT NULL', 'SELECT 1');
PREPARE tf_stmt FROM @tf_sql; EXECUTE tf_stmt; DEALLOCATE PREPARE tf_stmt;
SET @tf_need := (SELECT COUNT(*) = 0 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'websites' AND COLUMN_NAME = 'industry');
SET @tf_sql := IF(@tf_need = 0, 'UPDATE `websites` SET `industry_niche` = `industry` WHERE `industry_niche` IS NULL AND `industry` IS NOT NULL', 'SELECT 1');
PREPARE tf_stmt FROM @tf_sql; EXECUTE tf_stmt; DEALLOCATE PREPARE tf_stmt;
SET @tf_need := (SELECT COUNT(*) = 0 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'websites' AND COLUMN_NAME = 'target_country');
SET @tf_sql := IF(@tf_need = 0, 'UPDATE `websites` SET `target_countries` = `target_country` WHERE `target_countries` IS NULL AND `target_country` IS NOT NULL', 'SELECT 1');
PREPARE tf_stmt FROM @tf_sql; EXECUTE tf_stmt; DEALLOCATE PREPARE tf_stmt;
ALTER TABLE `wo_audit_findings`
    MODIFY COLUMN `check_key` VARCHAR(100) DEFAULT NULL,
    MODIFY COLUMN `status` VARCHAR(20) NOT NULL DEFAULT 'info',
    MODIFY COLUMN `severity` VARCHAR(20) NOT NULL DEFAULT 'medium',
    MODIFY COLUMN `message` TEXT DEFAULT NULL;
ALTER TABLE `wo_fixes` MODIFY COLUMN `fix_type` VARCHAR(30) DEFAULT NULL;

-- ===== 2026_10_11_whatsapp_completion =====
SET @wa_schema=DATABASE();
SET @wa_sql=IF(EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=@wa_schema AND table_name='wa_marketing_contacts' AND column_name='tags'),'SELECT 1','ALTER TABLE wa_marketing_contacts ADD COLUMN tags VARCHAR(250) NOT NULL DEFAULT \'\'');
PREPARE wa_stmt FROM @wa_sql;
EXECUTE wa_stmt;
DEALLOCATE PREPARE wa_stmt;
SET @wa_sql=IF(EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=@wa_schema AND table_name='wa_marketing_templates' AND column_name='components_json'),'SELECT 1','ALTER TABLE wa_marketing_templates ADD COLUMN components_json LONGTEXT NULL');
PREPARE wa_stmt FROM @wa_sql;
EXECUTE wa_stmt;
DEALLOCATE PREPARE wa_stmt;
SET @wa_sql=IF(EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=@wa_schema AND table_name='wa_marketing_campaigns' AND column_name='components_json'),'SELECT 1','ALTER TABLE wa_marketing_campaigns ADD COLUMN components_json LONGTEXT NULL, ADD COLUMN extra_parameters_json LONGTEXT NULL');
PREPARE wa_stmt FROM @wa_sql;
EXECUTE wa_stmt;
DEALLOCATE PREPARE wa_stmt;
CREATE TABLE IF NOT EXISTS wa_marketing_quota_reservations (
 campaign_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,user_id BIGINT UNSIGNED NOT NULL,
 subscription_id BIGINT UNSIGNED NULL,period_start DATETIME NOT NULL,units INT UNSIGNED NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,KEY wa_quota_period(user_id,period_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS wa_crm_inbound_links (
 user_id BIGINT UNSIGNED NOT NULL,website_id BIGINT UNSIGNED NOT NULL,wamid VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 message_id BIGINT UNSIGNED NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY(user_id,website_id,wamid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS crm_team_members (
 id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,tenant_user_id BIGINT UNSIGNED NOT NULL,member_user_id BIGINT UNSIGNED NOT NULL,
 role ENUM('admin','manager','sales','support','viewer') NOT NULL DEFAULT 'viewer',added_by_user_id BIGINT UNSIGNED NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY uniq_member_single_tenant(member_user_id),KEY idx_crm_team_tenant(tenant_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS crm_conversations (
 id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,user_id BIGINT UNSIGNED NOT NULL,contact_id INT NULL,
 channel ENUM('whatsapp','email','sms') NOT NULL,external_thread_id VARCHAR(255) NULL,assigned_user_id BIGINT UNSIGNED NULL,
 last_message_at DATETIME NULL,unread_count INT NOT NULL DEFAULT 0,created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 KEY idx_crm_conv_user(user_id),KEY idx_crm_conv_contact(contact_id),KEY idx_crm_conv_channel_thread(channel,external_thread_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS crm_messages (
 id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,conversation_id INT NOT NULL,direction ENUM('inbound','outbound') NOT NULL,
 sender_user_id BIGINT UNSIGNED NULL,body TEXT NOT NULL,subject VARCHAR(255) NULL,status VARCHAR(30) NOT NULL DEFAULT 'sent',
 external_message_id VARCHAR(255) NULL,error VARCHAR(500) NULL,sent_at DATETIME NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,KEY idx_crm_msg_conversation(conversation_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===== 2026_10_17_000003_content_quality =====
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

-- ===== 2026_10_17_000004_local_links_reporting =====
CREATE TABLE IF NOT EXISTS seo_phase5_claims (claim_key VARCHAR(191) PRIMARY KEY, created_at DATETIME NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS seo_local_profiles (
 website_id INT PRIMARY KEY,user_id INT NOT NULL,profile_json MEDIUMTEXT NOT NULL,updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS seo_local_grid_runs (
 id INT AUTO_INCREMENT PRIMARY KEY,website_id INT NOT NULL,user_id INT NOT NULL,month_start DATE NOT NULL,
 request_hash CHAR(64) NOT NULL,config_json MEDIUMTEXT NOT NULL,status VARCHAR(30) NOT NULL DEFAULT 'queued',created_at DATETIME NOT NULL,
 UNIQUE KEY grid_request(website_id,month_start,request_hash),KEY owner_month(user_id,month_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS seo_local_grid_points (
 run_id INT NOT NULL,keyword_index INT NOT NULL,point_index INT NOT NULL,state VARCHAR(30) NOT NULL,
 rank_position INT NULL,evidence_json TEXT NULL,checked_at DATETIME NULL,PRIMARY KEY point_identity(run_id,keyword_index,point_index)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS seo_citation_tasks (
 id INT AUTO_INCREMENT PRIMARY KEY,website_id INT NOT NULL,user_id INT NOT NULL,source_url VARCHAR(1800) NOT NULL,
 evidence_hash CHAR(64) NOT NULL,evidence_json MEDIUMTEXT NOT NULL,status VARCHAR(30) NOT NULL DEFAULT 'pending',created_at DATETIME NOT NULL,
 UNIQUE KEY citation_evidence(website_id,evidence_hash),KEY owner_tasks(user_id,website_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS seo_link_domain_snapshots (
 website_id INT NOT NULL,user_id INT NOT NULL,target_domain VARCHAR(255) NOT NULL,result_json MEDIUMTEXT NOT NULL,fetched_at DATETIME NOT NULL,
 PRIMARY KEY site_target(website_id,target_domain)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS seo_backlink_evidence (
 backlink_id INT PRIMARY KEY,rel_json TEXT NOT NULL,anchor_text TEXT NULL,target_url VARCHAR(2000) NULL,state VARCHAR(30) NOT NULL,checked_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS seo_auto_impact_monitors (
 website_id INT NOT NULL,user_id INT NOT NULL,task_id INT NOT NULL,window_days SMALLINT NOT NULL,monitor_id INT NOT NULL,
 PRIMARY KEY task_window(website_id,task_id,window_days)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ===== 2026_10_17_000005_international_indexing =====
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

-- ===== 2026_10_18_000001_email_reputation_guard =====
CREATE TABLE IF NOT EXISTS `email_account_limits` (
    `user_id` INT(11) NOT NULL,
    `status` ENUM('active','paused','suspended') NOT NULL DEFAULT 'active' COMMENT 'paused = إيقاف مؤقت (يُستأنف)، suspended = إيقاف إداري',
    `pause_reason` VARCHAR(500) NULL DEFAULT NULL,
    `paused_at` DATETIME NULL DEFAULT NULL,
    `paused_by` INT(11) NULL DEFAULT NULL COMMENT 'NULL = النظام (إيقاف تلقائي)',
    `hourly_limit` INT(11) NULL DEFAULT NULL COMMENT 'NULL = الافتراضي/حسب الباقة',
    `daily_limit` INT(11) NULL DEFAULT NULL,
    `monthly_limit` INT(11) NULL DEFAULT NULL,
    `per_minute_limit` INT(11) NULL DEFAULT NULL COMMENT 'سقف المعدل (Throttle) لكل دقيقة',
    `max_bounce_rate` DECIMAL(5,2) NULL DEFAULT NULL COMMENT 'نسبة % للإيقاف التلقائي (NULL = الافتراضي)',
    `max_complaint_rate` DECIMAL(5,3) NULL DEFAULT NULL,
    `warmup_enabled` TINYINT(1) NOT NULL DEFAULT 1,
    `warmup_started_at` DATETIME NULL DEFAULT NULL COMMENT 'يُضبط عند أول إرسال',
    `review_approved_at` DATETIME NULL DEFAULT NULL COMMENT 'موافقة الأدمن على أول حملة',
    `review_approved_by` INT(11) NULL DEFAULT NULL,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`user_id`),
    KEY `idx_eal_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='حدود وحالة إرسال التسويق بالبريد لكل عميل';
CREATE TABLE IF NOT EXISTS `email_send_usage` (
    `user_id` INT(11) NOT NULL,
    `bucket_start` DATETIME NOT NULL COMMENT 'بداية الساعة UTC',
    `sent_count` INT(11) NOT NULL DEFAULT 0,
    PRIMARY KEY (`user_id`, `bucket_start`),
    KEY `idx_esu_bucket` (`bucket_start`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='استهلاك الإرسال بالساعة';
CREATE TABLE IF NOT EXISTS `email_reputation_events` (
    `id` BIGINT(20) NOT NULL AUTO_INCREMENT,
    `user_id` INT(11) NOT NULL,
    `campaign_id` INT(11) NULL DEFAULT NULL,
    `event_type` VARCHAR(40) NOT NULL COMMENT 'account_paused/account_resumed/campaign_paused/limits_changed/...',
    `actor_id` INT(11) NULL DEFAULT NULL COMMENT 'NULL = النظام',
    `details` TEXT NULL DEFAULT NULL COMMENT 'JSON',
    `created_at` DATETIME NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_ere_user_created` (`user_id`, `created_at`),
    KEY `idx_ere_type` (`event_type`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='سجل تدقيق حماية السمعة';
CREATE TABLE IF NOT EXISTS `email_global_suppressions` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `kind` ENUM('email','domain') NOT NULL DEFAULT 'email',
    `value` VARCHAR(191) NOT NULL COMMENT 'lowercase',
    `reason` VARCHAR(255) NULL DEFAULT NULL,
    `created_by` INT(11) NULL DEFAULT NULL,
    `created_at` DATETIME NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_egs_kind_value` (`kind`, `value`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='حظر عام على مستوى المنصة';
ALTER TABLE `email_campaigns` MODIFY COLUMN `status`
    ENUM('draft','scheduled','sending','sent','cancelled','failed','partial','uncertain','paused') NOT NULL DEFAULT 'draft';
ALTER TABLE `email_campaign_recipients` MODIFY COLUMN `status`
    ENUM('pending','sending','sent','opened','clicked','unsubscribed','bounced','failed','uncertain') NOT NULL DEFAULT 'pending';
SET @tf_need := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'email_campaigns' AND COLUMN_NAME = 'paused_reason');
SET @tf_sql := IF(@tf_need = 0, 'ALTER TABLE `email_campaigns` ADD COLUMN `paused_reason` VARCHAR(500) NULL DEFAULT NULL', 'SELECT 1');
PREPARE tf_stmt FROM @tf_sql; EXECUTE tf_stmt; DEALLOCATE PREPARE tf_stmt;
SET @tf_need := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'email_campaigns' AND COLUMN_NAME = 'paused_at');
SET @tf_sql := IF(@tf_need = 0, 'ALTER TABLE `email_campaigns` ADD COLUMN `paused_at` DATETIME NULL DEFAULT NULL', 'SELECT 1');
PREPARE tf_stmt FROM @tf_sql; EXECUTE tf_stmt; DEALLOCATE PREPARE tf_stmt;
SET @tf_need := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'email_campaign_recipients' AND COLUMN_NAME = 'attempts');
SET @tf_sql := IF(@tf_need = 0, 'ALTER TABLE `email_campaign_recipients` ADD COLUMN `attempts` TINYINT(3) UNSIGNED NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE tf_stmt FROM @tf_sql; EXECUTE tf_stmt; DEALLOCATE PREPARE tf_stmt;
SET @tf_need := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'email_campaign_recipients' AND COLUMN_NAME = 'next_attempt_at');
SET @tf_sql := IF(@tf_need = 0, 'ALTER TABLE `email_campaign_recipients` ADD COLUMN `next_attempt_at` DATETIME NULL DEFAULT NULL COMMENT ''UTC - إعادة محاولة مؤجلة''', 'SELECT 1');
PREPARE tf_stmt FROM @tf_sql; EXECUTE tf_stmt; DEALLOCATE PREPARE tf_stmt;
SET @tf_need := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'email_campaign_recipients' AND COLUMN_NAME = 'error_class');
SET @tf_sql := IF(@tf_need = 0, 'ALTER TABLE `email_campaign_recipients` ADD COLUMN `error_class` VARCHAR(20) NULL DEFAULT NULL COMMENT ''temporary/invalid_recipient/blocked/permanent/unknown''', 'SELECT 1');
PREPARE tf_stmt FROM @tf_sql; EXECUTE tf_stmt; DEALLOCATE PREPARE tf_stmt;
SET @tf_need := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'email_campaign_recipients' AND COLUMN_NAME = 'bounced_at');
SET @tf_sql := IF(@tf_need = 0, 'ALTER TABLE `email_campaign_recipients` ADD COLUMN `bounced_at` DATETIME NULL DEFAULT NULL', 'SELECT 1');
PREPARE tf_stmt FROM @tf_sql; EXECUTE tf_stmt; DEALLOCATE PREPARE tf_stmt;
SET @tf_need := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'email_campaign_recipients' AND COLUMN_NAME = 'complained_at');
SET @tf_sql := IF(@tf_need = 0, 'ALTER TABLE `email_campaign_recipients` ADD COLUMN `complained_at` DATETIME NULL DEFAULT NULL', 'SELECT 1');
PREPARE tf_stmt FROM @tf_sql; EXECUTE tf_stmt; DEALLOCATE PREPARE tf_stmt;
SET @tf_need := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'email_campaign_recipients' AND INDEX_NAME = 'idx_ecr_campaign_status_next');
SET @tf_sql := IF(@tf_need = 0, 'ALTER TABLE `email_campaign_recipients` ADD KEY `idx_ecr_campaign_status_next` (`campaign_id`, `status`, `next_attempt_at`)', 'SELECT 1');
PREPARE tf_stmt FROM @tf_sql; EXECUTE tf_stmt; DEALLOCATE PREPARE tf_stmt;
SET @tf_need := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'email_campaign_recipients' AND INDEX_NAME = 'idx_ecr_email');
SET @tf_sql := IF(@tf_need = 0, 'ALTER TABLE `email_campaign_recipients` ADD KEY `idx_ecr_email` (`email`)', 'SELECT 1');
PREPARE tf_stmt FROM @tf_sql; EXECUTE tf_stmt; DEALLOCATE PREPARE tf_stmt;
SET @tf_need := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'email_subscribers' AND INDEX_NAME = 'idx_es_user_status_email');
SET @tf_sql := IF(@tf_need = 0, 'ALTER TABLE `email_subscribers` ADD KEY `idx_es_user_status_email` (`user_id`, `status`, `email`)', 'SELECT 1');
PREPARE tf_stmt FROM @tf_sql; EXECUTE tf_stmt; DEALLOCATE PREPARE tf_stmt;

-- ===== 2026_10_18_000001_enterprise_email_marketing_upgrade =====
SET @tf_need := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'email_smtp_settings' AND COLUMN_NAME = 'daily_limit');
SET @tf_sql := IF(@tf_need = 0, 'ALTER TABLE `email_smtp_settings` ADD COLUMN `daily_limit` INT UNSIGNED NOT NULL DEFAULT 500', 'SELECT 1');
PREPARE tf_stmt FROM @tf_sql; EXECUTE tf_stmt; DEALLOCATE PREPARE tf_stmt;
SET @tf_need := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'email_smtp_settings' AND COLUMN_NAME = 'sent_today');
SET @tf_sql := IF(@tf_need = 0, 'ALTER TABLE `email_smtp_settings` ADD COLUMN `sent_today` INT UNSIGNED NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE tf_stmt FROM @tf_sql; EXECUTE tf_stmt; DEALLOCATE PREPARE tf_stmt;
SET @tf_need := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'email_smtp_settings' AND COLUMN_NAME = 'warmup_enabled');
SET @tf_sql := IF(@tf_need = 0, 'ALTER TABLE `email_smtp_settings` ADD COLUMN `warmup_enabled` TINYINT(1) NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE tf_stmt FROM @tf_sql; EXECUTE tf_stmt; DEALLOCATE PREPARE tf_stmt;
SET @tf_need := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'email_smtp_settings' AND COLUMN_NAME = 'warmup_step_increment');
SET @tf_sql := IF(@tf_need = 0, 'ALTER TABLE `email_smtp_settings` ADD COLUMN `warmup_step_increment` INT UNSIGNED NOT NULL DEFAULT 50', 'SELECT 1');
PREPARE tf_stmt FROM @tf_sql; EXECUTE tf_stmt; DEALLOCATE PREPARE tf_stmt;
SET @tf_need := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'email_smtp_settings' AND COLUMN_NAME = 'spf_verified');
SET @tf_sql := IF(@tf_need = 0, 'ALTER TABLE `email_smtp_settings` ADD COLUMN `spf_verified` TINYINT(1) NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE tf_stmt FROM @tf_sql; EXECUTE tf_stmt; DEALLOCATE PREPARE tf_stmt;
SET @tf_need := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'email_smtp_settings' AND COLUMN_NAME = 'dkim_verified');
SET @tf_sql := IF(@tf_need = 0, 'ALTER TABLE `email_smtp_settings` ADD COLUMN `dkim_verified` TINYINT(1) NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE tf_stmt FROM @tf_sql; EXECUTE tf_stmt; DEALLOCATE PREPARE tf_stmt;
SET @tf_need := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'email_smtp_settings' AND COLUMN_NAME = 'dmarc_verified');
SET @tf_sql := IF(@tf_need = 0, 'ALTER TABLE `email_smtp_settings` ADD COLUMN `dmarc_verified` TINYINT(1) NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE tf_stmt FROM @tf_sql; EXECUTE tf_stmt; DEALLOCATE PREPARE tf_stmt;
SET @tf_need := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'email_smtp_settings' AND COLUMN_NAME = 'dkim_selector');
SET @tf_sql := IF(@tf_need = 0, 'ALTER TABLE `email_smtp_settings` ADD COLUMN `dkim_selector` VARCHAR(64) DEFAULT ''default''', 'SELECT 1');
PREPARE tf_stmt FROM @tf_sql; EXECUTE tf_stmt; DEALLOCATE PREPARE tf_stmt;
SET @tf_need := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'email_smtp_settings' AND COLUMN_NAME = 'health_score');
SET @tf_sql := IF(@tf_need = 0, 'ALTER TABLE `email_smtp_settings` ADD COLUMN `health_score` TINYINT UNSIGNED NOT NULL DEFAULT 100', 'SELECT 1');
PREPARE tf_stmt FROM @tf_sql; EXECUTE tf_stmt; DEALLOCATE PREPARE tf_stmt;
SET @tf_need := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'email_smtp_settings' AND COLUMN_NAME = 'bounces_count');
SET @tf_sql := IF(@tf_need = 0, 'ALTER TABLE `email_smtp_settings` ADD COLUMN `bounces_count` INT UNSIGNED NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE tf_stmt FROM @tf_sql; EXECUTE tf_stmt; DEALLOCATE PREPARE tf_stmt;
SET @tf_need := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'email_smtp_settings' AND COLUMN_NAME = 'complaints_count');
SET @tf_sql := IF(@tf_need = 0, 'ALTER TABLE `email_smtp_settings` ADD COLUMN `complaints_count` INT UNSIGNED NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE tf_stmt FROM @tf_sql; EXECUTE tf_stmt; DEALLOCATE PREPARE tf_stmt;
SET @tf_need := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'email_smtp_settings' AND COLUMN_NAME = 'last_used_at');
SET @tf_sql := IF(@tf_need = 0, 'ALTER TABLE `email_smtp_settings` ADD COLUMN `last_used_at` TIMESTAMP NULL DEFAULT NULL', 'SELECT 1');
PREPARE tf_stmt FROM @tf_sql; EXECUTE tf_stmt; DEALLOCATE PREPARE tf_stmt;
SET @tf_need := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'email_automation_steps' AND COLUMN_NAME = 'step_type');
SET @tf_sql := IF(@tf_need = 0, 'ALTER TABLE `email_automation_steps` ADD COLUMN `step_type` ENUM(''send_email'', ''delay'', ''condition'', ''action_tag'', ''webhook'') NOT NULL DEFAULT ''send_email''', 'SELECT 1');
PREPARE tf_stmt FROM @tf_sql; EXECUTE tf_stmt; DEALLOCATE PREPARE tf_stmt;
SET @tf_need := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'email_automation_steps' AND COLUMN_NAME = 'condition_rules');
SET @tf_sql := IF(@tf_need = 0, 'ALTER TABLE `email_automation_steps` ADD COLUMN `condition_rules` JSON NULL', 'SELECT 1');
PREPARE tf_stmt FROM @tf_sql; EXECUTE tf_stmt; DEALLOCATE PREPARE tf_stmt;
SET @tf_need := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'email_automation_steps' AND COLUMN_NAME = 'true_next_step_id');
SET @tf_sql := IF(@tf_need = 0, 'ALTER TABLE `email_automation_steps` ADD COLUMN `true_next_step_id` INT UNSIGNED NULL', 'SELECT 1');
PREPARE tf_stmt FROM @tf_sql; EXECUTE tf_stmt; DEALLOCATE PREPARE tf_stmt;
SET @tf_need := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'email_automation_steps' AND COLUMN_NAME = 'false_next_step_id');
SET @tf_sql := IF(@tf_need = 0, 'ALTER TABLE `email_automation_steps` ADD COLUMN `false_next_step_id` INT UNSIGNED NULL', 'SELECT 1');
PREPARE tf_stmt FROM @tf_sql; EXECUTE tf_stmt; DEALLOCATE PREPARE tf_stmt;
SET @tf_need := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'email_campaign_recipients' AND COLUMN_NAME = 'is_human_open');
SET @tf_sql := IF(@tf_need = 0, 'ALTER TABLE `email_campaign_recipients` ADD COLUMN `is_human_open` TINYINT(1) NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE tf_stmt FROM @tf_sql; EXECUTE tf_stmt; DEALLOCATE PREPARE tf_stmt;
SET @tf_need := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'email_campaign_recipients' AND COLUMN_NAME = 'open_type');
SET @tf_sql := IF(@tf_need = 0, 'ALTER TABLE `email_campaign_recipients` ADD COLUMN `open_type` VARCHAR(32) NULL DEFAULT NULL', 'SELECT 1');
PREPARE tf_stmt FROM @tf_sql; EXECUTE tf_stmt; DEALLOCATE PREPARE tf_stmt;
SET @tf_need := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'email_campaign_recipients' AND COLUMN_NAME = 'open_count');
SET @tf_sql := IF(@tf_need = 0, 'ALTER TABLE `email_campaign_recipients` ADD COLUMN `open_count` INT UNSIGNED NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE tf_stmt FROM @tf_sql; EXECUTE tf_stmt; DEALLOCATE PREPARE tf_stmt;
SET @tf_need := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'email_campaign_recipients' AND COLUMN_NAME = 'click_count');
SET @tf_sql := IF(@tf_need = 0, 'ALTER TABLE `email_campaign_recipients` ADD COLUMN `click_count` INT UNSIGNED NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE tf_stmt FROM @tf_sql; EXECUTE tf_stmt; DEALLOCATE PREPARE tf_stmt;
SET @tf_need := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'email_campaign_recipients' AND COLUMN_NAME = 'user_agent');
SET @tf_sql := IF(@tf_need = 0, 'ALTER TABLE `email_campaign_recipients` ADD COLUMN `user_agent` VARCHAR(512) NULL DEFAULT NULL', 'SELECT 1');
PREPARE tf_stmt FROM @tf_sql; EXECUTE tf_stmt; DEALLOCATE PREPARE tf_stmt;
SET @tf_need := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'email_campaign_recipients' AND COLUMN_NAME = 'ip_address');
SET @tf_sql := IF(@tf_need = 0, 'ALTER TABLE `email_campaign_recipients` ADD COLUMN `ip_address` VARCHAR(64) NULL DEFAULT NULL', 'SELECT 1');
PREPARE tf_stmt FROM @tf_sql; EXECUTE tf_stmt; DEALLOCATE PREPARE tf_stmt;
SET @tf_need := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'email_subscribers' AND COLUMN_NAME = 'optimal_send_hour');
SET @tf_sql := IF(@tf_need = 0, 'ALTER TABLE `email_subscribers` ADD COLUMN `optimal_send_hour` TINYINT UNSIGNED NULL DEFAULT NULL', 'SELECT 1');
PREPARE tf_stmt FROM @tf_sql; EXECUTE tf_stmt; DEALLOCATE PREPARE tf_stmt;
SET @tf_need := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'email_subscribers' AND COLUMN_NAME = 'engagement_score');
SET @tf_sql := IF(@tf_need = 0, 'ALTER TABLE `email_subscribers` ADD COLUMN `engagement_score` INT UNSIGNED NOT NULL DEFAULT 50', 'SELECT 1');
PREPARE tf_stmt FROM @tf_sql; EXECUTE tf_stmt; DEALLOCATE PREPARE tf_stmt;
SET @tf_need := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'email_subscribers' AND COLUMN_NAME = 'last_engaged_at');
SET @tf_sql := IF(@tf_need = 0, 'ALTER TABLE `email_subscribers` ADD COLUMN `last_engaged_at` TIMESTAMP NULL DEFAULT NULL', 'SELECT 1');
PREPARE tf_stmt FROM @tf_sql; EXECUTE tf_stmt; DEALLOCATE PREPARE tf_stmt;
SET @tf_need := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'email_campaigns' AND COLUMN_NAME = 'attributed_bookings_count');
SET @tf_sql := IF(@tf_need = 0, 'ALTER TABLE `email_campaigns` ADD COLUMN `attributed_bookings_count` INT UNSIGNED NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE tf_stmt FROM @tf_sql; EXECUTE tf_stmt; DEALLOCATE PREPARE tf_stmt;
SET @tf_need := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'email_campaigns' AND COLUMN_NAME = 'attributed_revenue');
SET @tf_sql := IF(@tf_need = 0, 'ALTER TABLE `email_campaigns` ADD COLUMN `attributed_revenue` DECIMAL(12,2) NOT NULL DEFAULT 0.00', 'SELECT 1');
PREPARE tf_stmt FROM @tf_sql; EXECUTE tf_stmt; DEALLOCATE PREPARE tf_stmt;

-- ===== 2026_10_18_000001_seo_platform_booking_products =====
CREATE TABLE IF NOT EXISTS seo_platform_booking_products (
 product_id INT NOT NULL PRIMARY KEY,
 website_id INT NOT NULL,
 user_id INT NOT NULL,
 confirmed_at DATETIME NOT NULL,
 KEY owner_site(user_id,website_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ===== 2026_10_18_000001_social_publishing_reliability =====
SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'social_post_targets' AND COLUMN_NAME = 'error_code');
SET @q := IF(@has = 0, 'ALTER TABLE `social_post_targets` ADD COLUMN `error_code` INT DEFAULT NULL COMMENT ''كود خطأ Meta'' AFTER `last_error`', 'SELECT 1');
PREPARE s FROM @q;
EXECUTE s;
DEALLOCATE PREPARE s;
SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'social_post_targets' AND COLUMN_NAME = 'error_category');
SET @q := IF(@has = 0, 'ALTER TABLE `social_post_targets` ADD COLUMN `error_category` VARCHAR(20) DEFAULT NULL COMMENT ''token|permission|rate_limit|blocked|media|param|transient|unknown'' AFTER `error_code`', 'SELECT 1');
PREPARE s FROM @q;
EXECUTE s;
DEALLOCATE PREPARE s;
SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'social_post_targets' AND COLUMN_NAME = 'fbtrace_id');
SET @q := IF(@has = 0, 'ALTER TABLE `social_post_targets` ADD COLUMN `fbtrace_id` VARCHAR(64) DEFAULT NULL COMMENT ''رمز تتبع Meta للدعم الفني'' AFTER `error_category`', 'SELECT 1');
PREPARE s FROM @q;
EXECUTE s;
DEALLOCATE PREPARE s;
SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'social_post_targets' AND COLUMN_NAME = 'retry_count');
SET @q := IF(@has = 0, 'ALTER TABLE `social_post_targets` ADD COLUMN `retry_count` TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT ''محاولات الإعادة التلقائية (حد استخدام/شبكة)'' AFTER `fbtrace_id`', 'SELECT 1');
PREPARE s FROM @q;
EXECUTE s;
DEALLOCATE PREPARE s;
SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'social_post_targets' AND COLUMN_NAME = 'next_retry_at');
SET @q := IF(@has = 0, 'ALTER TABLE `social_post_targets` ADD COLUMN `next_retry_at` DATETIME DEFAULT NULL AFTER `retry_count`', 'SELECT 1');
PREPARE s FROM @q;
EXECUTE s;
DEALLOCATE PREPARE s;
SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'social_post_targets' AND COLUMN_NAME = 'publish_attempt_at');
SET @q := IF(@has = 0, 'ALTER TABLE `social_post_targets` ADD COLUMN `publish_attempt_at` DATETIME DEFAULT NULL COMMENT ''وقت آخر طلب نشر فعلي (UTC) - أساس نافذة المصالحة'' AFTER `next_retry_at`', 'SELECT 1');
PREPARE s FROM @q;
EXECUTE s;
DEALLOCATE PREPARE s;
SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'social_post_targets' AND COLUMN_NAME = 'reconciled_at');
SET @q := IF(@has = 0, 'ALTER TABLE `social_post_targets` ADD COLUMN `reconciled_at` DATETIME DEFAULT NULL COMMENT ''اتحسم بالمصالحة التلقائية أو يدويًا'' AFTER `publish_attempt_at`', 'SELECT 1');
PREPARE s FROM @q;
EXECUTE s;
DEALLOCATE PREPARE s;
SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'social_post_targets' AND COLUMN_NAME = 'publish_ms');
SET @q := IF(@has = 0, 'ALTER TABLE `social_post_targets` ADD COLUMN `publish_ms` INT DEFAULT NULL COMMENT ''زمن تنفيذ النشر بالمللي ثانية'' AFTER `reconciled_at`', 'SELECT 1');
PREPARE s FROM @q;
EXECUTE s;
DEALLOCATE PREPARE s;
SET @has := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'social_post_targets' AND INDEX_NAME = 'idx_status_attempt');
SET @q := IF(@has = 0, 'ALTER TABLE `social_post_targets` ADD INDEX `idx_status_attempt` (`status`, `publish_attempt_at`)', 'SELECT 1');
PREPARE s FROM @q;
EXECUTE s;
DEALLOCATE PREPARE s;
SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'social_posts' AND COLUMN_NAME = 'first_comment');
SET @q := IF(@has = 0, 'ALTER TABLE `social_posts` ADD COLUMN `first_comment` TEXT DEFAULT NULL AFTER `hashtags`', 'SELECT 1');
PREPARE s FROM @q;
EXECUTE s;
DEALLOCATE PREPARE s;
SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'social_post_metrics' AND COLUMN_NAME = 'views');
SET @q := IF(@has = 0, 'ALTER TABLE `social_post_metrics` ADD COLUMN `views` INT DEFAULT NULL AFTER `reach`', 'SELECT 1');
PREPARE s FROM @q;
EXECUTE s;
DEALLOCATE PREPARE s;
SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'social_post_metrics' AND COLUMN_NAME = 'insights_note');
SET @q := IF(@has = 0, 'ALTER TABLE `social_post_metrics` ADD COLUMN `insights_note` VARCHAR(300) DEFAULT NULL AFTER `note`', 'SELECT 1');
PREPARE s FROM @q;
EXECUTE s;
DEALLOCATE PREPARE s;
CREATE TABLE IF NOT EXISTS `social_publish_log` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `target_id` INT(11) NOT NULL,
    `user_id` INT(11) DEFAULT NULL,
    `platform_connection_id` INT(11) DEFAULT NULL,
    `platform` VARCHAR(20) NOT NULL,
    `outcome` VARCHAR(20) NOT NULL COMMENT 'published|failed|uncertain|retry_later|reconciled_published|reconciled_not_published|preflight_failed',
    `error_code` INT DEFAULT NULL,
    `error_category` VARCHAR(20) DEFAULT NULL,
    `fbtrace_id` VARCHAR(64) DEFAULT NULL,
    `duration_ms` INT DEFAULT NULL,
    `note` VARCHAR(300) DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_conn_created` (`platform_connection_id`, `created_at`),
    KEY `idx_user_created` (`user_id`, `created_at`),
    KEY `idx_target` (`target_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `social_connection_health` (
    `platform_connection_id` INT(11) NOT NULL,
    `state` VARCHAR(24) NOT NULL DEFAULT 'ok' COMMENT 'ok|expiring|expired|invalid|missing_permissions|error',
    `token_expires_at` DATETIME DEFAULT NULL,
    `data_access_expires_at` DATETIME DEFAULT NULL,
    `missing_scopes` VARCHAR(500) DEFAULT NULL,
    `last_error` VARCHAR(300) DEFAULT NULL,
    `checked_at` DATETIME DEFAULT NULL,
    `notified_state` VARCHAR(24) DEFAULT NULL COMMENT 'آخر حالة بعتنا عنها إشعار (علشان ما نكرّرش)',
    `notified_at` DATETIME DEFAULT NULL,
    PRIMARY KEY (`platform_connection_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===== 2026_10_18_000001_upgrade_smtp_deliverability =====
SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'email_smtp_settings' AND COLUMN_NAME = 'daily_limit');
SET @q := IF(@has = 0, 'ALTER TABLE `email_smtp_settings` ADD COLUMN `daily_limit` INT UNSIGNED DEFAULT 500', 'SELECT 1');
PREPARE s FROM @q;
EXECUTE s;
DEALLOCATE PREPARE s;
SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'email_smtp_settings' AND COLUMN_NAME = 'sent_today');
SET @q := IF(@has = 0, 'ALTER TABLE `email_smtp_settings` ADD COLUMN `sent_today` INT UNSIGNED DEFAULT 0', 'SELECT 1');
PREPARE s FROM @q;
EXECUTE s;
DEALLOCATE PREPARE s;
SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'email_smtp_settings' AND COLUMN_NAME = 'warmup_enabled');
SET @q := IF(@has = 0, 'ALTER TABLE `email_smtp_settings` ADD COLUMN `warmup_enabled` TINYINT(1) DEFAULT 0', 'SELECT 1');
PREPARE s FROM @q;
EXECUTE s;
DEALLOCATE PREPARE s;
SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'email_smtp_settings' AND COLUMN_NAME = 'warmup_step_increment');
SET @q := IF(@has = 0, 'ALTER TABLE `email_smtp_settings` ADD COLUMN `warmup_step_increment` INT UNSIGNED DEFAULT 50', 'SELECT 1');
PREPARE s FROM @q;
EXECUTE s;
DEALLOCATE PREPARE s;
SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'email_smtp_settings' AND COLUMN_NAME = 'spf_verified');
SET @q := IF(@has = 0, 'ALTER TABLE `email_smtp_settings` ADD COLUMN `spf_verified` TINYINT(1) DEFAULT 0', 'SELECT 1');
PREPARE s FROM @q;
EXECUTE s;
DEALLOCATE PREPARE s;
SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'email_smtp_settings' AND COLUMN_NAME = 'dkim_verified');
SET @q := IF(@has = 0, 'ALTER TABLE `email_smtp_settings` ADD COLUMN `dkim_verified` TINYINT(1) DEFAULT 0', 'SELECT 1');
PREPARE s FROM @q;
EXECUTE s;
DEALLOCATE PREPARE s;
SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'email_smtp_settings' AND COLUMN_NAME = 'dmarc_verified');
SET @q := IF(@has = 0, 'ALTER TABLE `email_smtp_settings` ADD COLUMN `dmarc_verified` TINYINT(1) DEFAULT 0', 'SELECT 1');
PREPARE s FROM @q;
EXECUTE s;
DEALLOCATE PREPARE s;
SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'email_smtp_settings' AND COLUMN_NAME = 'health_score');
SET @q := IF(@has = 0, 'ALTER TABLE `email_smtp_settings` ADD COLUMN `health_score` TINYINT UNSIGNED DEFAULT 100', 'SELECT 1');
PREPARE s FROM @q;
EXECUTE s;
DEALLOCATE PREPARE s;
SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'email_smtp_settings' AND COLUMN_NAME = 'last_used_at');
SET @q := IF(@has = 0, 'ALTER TABLE `email_smtp_settings` ADD COLUMN `last_used_at` TIMESTAMP NULL DEFAULT NULL', 'SELECT 1');
PREPARE s FROM @q;
EXECUTE s;
DEALLOCATE PREPARE s;

-- ===== 2026_10_18_000002_social_post_media =====
CREATE TABLE IF NOT EXISTS `social_post_media` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `social_post_id` INT(11) NOT NULL,
    `media_item_id` INT(11) NOT NULL,
    `position` TINYINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_post_media` (`social_post_id`, `media_item_id`),
    KEY `idx_post_position` (`social_post_id`, `position`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===== 2026_10_18_000003_meta_data_privacy =====
SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'social_connection_health' AND COLUMN_NAME = 'fb_user_id');
SET @q := IF(@has = 0, 'ALTER TABLE `social_connection_health` ADD COLUMN `fb_user_id` VARCHAR(40) DEFAULT NULL AFTER `platform_connection_id`, ADD INDEX `idx_fb_user` (`fb_user_id`)', 'SELECT 1');
PREPARE s FROM @q;
EXECUTE s;
DEALLOCATE PREPARE s;
CREATE TABLE IF NOT EXISTS `meta_data_requests` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `confirmation_code` CHAR(24) NOT NULL,
    `fb_user_hash` CHAR(64) NOT NULL,
    `type` VARCHAR(16) NOT NULL COMMENT 'deauthorize|deletion',
    `status` VARCHAR(32) NOT NULL DEFAULT 'received' COMMENT 'completed|no_matching_connection',
    `connections_affected` INT(11) NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_code` (`confirmation_code`),
    KEY `idx_hash` (`fb_user_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===== 2026_10_18_000070_upgrade_autopilot_enterprise =====
ALTER TABLE `ad_pending_actions`
    MODIFY COLUMN `status` ENUM('pending','processing','approved','executed','rejected','failed','uncertain','expired') NOT NULL DEFAULT 'pending';
SET @__fx_cooldown_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ad_autopilot_settings' AND COLUMN_NAME = 'cooldown_hours'
);
SET @__fx_cooldown_sql := IF(@__fx_cooldown_exists = 0,
    'ALTER TABLE `ad_autopilot_settings` ADD COLUMN `cooldown_hours` INT(11) NOT NULL DEFAULT 24 COMMENT ''فترة الانتظار بالساعات بين كل تعديل ميزانية لنفس الحملة'' AFTER `max_changes_per_day`',
    'SELECT 1');
PREPARE __fx_cooldown_stmt FROM @__fx_cooldown_sql;
EXECUTE __fx_cooldown_stmt;
DEALLOCATE PREPARE __fx_cooldown_stmt;
SET @__idx_perf_camp_date := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ad_performance_reports' AND INDEX_NAME = 'idx_campaign_date_start'
);
SET @__idx_perf_sql := IF(@__idx_perf_camp_date = 0,
    'ALTER TABLE `ad_performance_reports` ADD INDEX `idx_campaign_date_start` (`campaign_id`, `date_start`)',
    'SELECT 1');
PREPARE __idx_perf_stmt FROM @__idx_perf_sql;
EXECUTE __idx_perf_stmt;
DEALLOCATE PREPARE __idx_perf_stmt;

-- ===== 2026_10_19_000001_email_compliance =====
CREATE TABLE IF NOT EXISTS `email_consent_log` (
    `id` BIGINT(20) NOT NULL AUTO_INCREMENT,
    `user_id` INT(11) NOT NULL,
    `subscriber_id` INT(11) NOT NULL,
    `email` VARCHAR(191) NOT NULL,
    `event` ENUM('subscribed','pending_optin','confirmed','unsubscribed','imported') NOT NULL,
    `source` VARCHAR(50) NULL DEFAULT NULL,
    `list_id` INT(11) NULL DEFAULT NULL,
    `ip` VARCHAR(64) NULL DEFAULT NULL,
    `user_agent` VARCHAR(255) NULL DEFAULT NULL,
    `consent_text` VARCHAR(1000) NULL DEFAULT NULL COMMENT 'نص الموافقة اللي شافه المشترك',
    `created_at` DATETIME NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_ecl_user_sub` (`user_id`, `subscriber_id`),
    KEY `idx_ecl_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='سجل موافقات المشتركين';
CREATE TABLE IF NOT EXISTS `email_data_requests` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `user_id` INT(11) NOT NULL,
    `kind` ENUM('export','erase') NOT NULL,
    `email_hash` CHAR(64) NOT NULL COMMENT 'sha256 للإيميل — لا نخزّن الإيميل نفسه',
    `actor_id` INT(11) NULL DEFAULT NULL,
    `created_at` DATETIME NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_edr_user` (`user_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='تدقيق طلبات GDPR';

-- ===== 2026_10_20_000001_email_ab_auto_winner =====
SET @tf_need := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'email_ab_tests' AND COLUMN_NAME = 'holdout_percent');
SET @tf_sql := IF(@tf_need = 0, 'ALTER TABLE `email_ab_tests` ADD COLUMN `holdout_percent` TINYINT(3) UNSIGNED NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE tf_stmt FROM @tf_sql; EXECUTE tf_stmt; DEALLOCATE PREPARE tf_stmt;
SET @tf_need := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'email_ab_tests' AND COLUMN_NAME = 'auto_winner');
SET @tf_sql := IF(@tf_need = 0, 'ALTER TABLE `email_ab_tests` ADD COLUMN `auto_winner` TINYINT(1) NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE tf_stmt FROM @tf_sql; EXECUTE tf_stmt; DEALLOCATE PREPARE tf_stmt;
SET @tf_need := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'email_ab_tests' AND COLUMN_NAME = 'test_hours');
SET @tf_sql := IF(@tf_need = 0, 'ALTER TABLE `email_ab_tests` ADD COLUMN `test_hours` INT(11) NOT NULL DEFAULT 24 COMMENT ''أقل مدة انتظار بعد بدء الاختبار قبل اختيار الفائز''', 'SELECT 1');
PREPARE tf_stmt FROM @tf_sql; EXECUTE tf_stmt; DEALLOCATE PREPARE tf_stmt;
SET @tf_need := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'email_ab_tests' AND COLUMN_NAME = 'started_at');
SET @tf_sql := IF(@tf_need = 0, 'ALTER TABLE `email_ab_tests` ADD COLUMN `started_at` DATETIME NULL DEFAULT NULL COMMENT ''UTC''', 'SELECT 1');
PREPARE tf_stmt FROM @tf_sql; EXECUTE tf_stmt; DEALLOCATE PREPARE tf_stmt;
SET @tf_need := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'email_ab_tests' AND COLUMN_NAME = 'holdout_campaign_id');
SET @tf_sql := IF(@tf_need = 0, 'ALTER TABLE `email_ab_tests` ADD COLUMN `holdout_campaign_id` INT(11) NULL DEFAULT NULL', 'SELECT 1');
PREPARE tf_stmt FROM @tf_sql; EXECUTE tf_stmt; DEALLOCATE PREPARE tf_stmt;
SET @tf_need := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'email_ab_tests' AND COLUMN_NAME = 'holdout_sent_at');
SET @tf_sql := IF(@tf_need = 0, 'ALTER TABLE `email_ab_tests` ADD COLUMN `holdout_sent_at` DATETIME NULL DEFAULT NULL', 'SELECT 1');
PREPARE tf_stmt FROM @tf_sql; EXECUTE tf_stmt; DEALLOCATE PREPARE tf_stmt;

-- ===== 2026_10_22_000001_social_autopilot =====
CREATE TABLE IF NOT EXISTS `social_autopilot_settings` (
    `website_id` INT(11) NOT NULL,
    `user_id` INT(11) NOT NULL,
    `enabled` TINYINT(1) NOT NULL DEFAULT 0,
    `connection_ids` VARCHAR(100) DEFAULT NULL COMMENT 'JSON: صفحات النشر؛ فاضي = كل الصفحات المربوطة',
    `post_count` TINYINT UNSIGNED NOT NULL DEFAULT 12,
    `publish_time` CHAR(5) NOT NULL DEFAULT '18:00',
    `timezone` VARCHAR(64) NOT NULL DEFAULT 'UTC',
    `language` CHAR(2) NOT NULL DEFAULT 'en',
    `mix` VARCHAR(10) NOT NULL DEFAULT 'mix',
    `run_day` TINYINT UNSIGNED NOT NULL DEFAULT 20 COMMENT 'يوم في الشهر بتتجهز فيه خطة الشهر الجاي',
    `auto_approve` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'يعتمد الخطة وينشر من غير مراجعة (باقات الطيار الآلي بس)',
    `last_month_key` CHAR(7) DEFAULT NULL,
    `last_plan_id` INT(11) DEFAULT NULL,
    `last_status` VARCHAR(30) DEFAULT NULL,
    `last_error` VARCHAR(500) DEFAULT NULL,
    `last_run_at` DATETIME DEFAULT NULL,
    `created_at` DATETIME NOT NULL,
    `updated_at` DATETIME NOT NULL,
    PRIMARY KEY (`website_id`),
    KEY `idx_sas_user` (`user_id`),
    KEY `idx_sas_due` (`enabled`, `last_month_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
