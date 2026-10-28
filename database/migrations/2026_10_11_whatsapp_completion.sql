-- Additive WhatsApp follow-up completion. Run after whatsapp_marketing.sql.
SET @wa_schema=DATABASE();
SET @wa_sql=IF(EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=@wa_schema AND table_name='wa_marketing_contacts' AND column_name='tags'),'SELECT 1','ALTER TABLE wa_marketing_contacts ADD COLUMN tags VARCHAR(250) NOT NULL DEFAULT \'\'');
PREPARE wa_stmt FROM @wa_sql; EXECUTE wa_stmt; DEALLOCATE PREPARE wa_stmt;
SET @wa_sql=IF(EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=@wa_schema AND table_name='wa_marketing_templates' AND column_name='components_json'),'SELECT 1','ALTER TABLE wa_marketing_templates ADD COLUMN components_json LONGTEXT NULL');
PREPARE wa_stmt FROM @wa_sql; EXECUTE wa_stmt; DEALLOCATE PREPARE wa_stmt;
SET @wa_sql=IF(EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=@wa_schema AND table_name='wa_marketing_campaigns' AND column_name='components_json'),'SELECT 1','ALTER TABLE wa_marketing_campaigns ADD COLUMN components_json LONGTEXT NULL, ADD COLUMN extra_parameters_json LONGTEXT NULL');
PREPARE wa_stmt FROM @wa_sql; EXECUTE wa_stmt; DEALLOCATE PREPARE wa_stmt;
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
-- Existing CRM communication/team tables: fill genuinely missing storage without changing installed tables.
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
