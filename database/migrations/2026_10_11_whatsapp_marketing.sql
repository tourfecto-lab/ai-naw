-- Additive marketing tables. Existing chat and CRM records are preserved.
CREATE TABLE IF NOT EXISTS wa_marketing_contacts (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, user_id BIGINT UNSIGNED NOT NULL, website_id BIGINT UNSIGNED NOT NULL,
 phone VARCHAR(15) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, name VARCHAR(190) NOT NULL,
 language VARCHAR(12) NOT NULL DEFAULT 'en', consent_state VARCHAR(20) NOT NULL DEFAULT 'pending',
 consent_note VARCHAR(500) NOT NULL DEFAULT '', consented_at DATETIME NULL, opted_out_at DATETIME NULL,
 last_accepted_at DATETIME NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY wa_marketing_phone (user_id,website_id,phone)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS wa_marketing_consent_events (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, contact_id BIGINT UNSIGNED NOT NULL,
 user_id BIGINT UNSIGNED NOT NULL, website_id BIGINT UNSIGNED NOT NULL, state VARCHAR(20) NOT NULL,
 evidence VARCHAR(500) NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 KEY wa_consent_contact (contact_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS wa_marketing_templates (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, user_id BIGINT UNSIGNED NOT NULL, website_id BIGINT UNSIGNED NOT NULL,
 name VARCHAR(128) COLLATE utf8mb4_bin NOT NULL, language VARCHAR(12) COLLATE utf8mb4_bin NOT NULL,
 status VARCHAR(32) NOT NULL, supported TINYINT NOT NULL DEFAULT 0, body TEXT NOT NULL,
 parameter_count INT NOT NULL DEFAULT 0, reason VARCHAR(255) NULL, content_hash CHAR(64) NOT NULL,
 connection_hash CHAR(64) NOT NULL, checked_at DATETIME NOT NULL,
 UNIQUE KEY wa_template_variant (user_id,website_id,name,language)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS wa_marketing_campaigns (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, user_id BIGINT UNSIGNED NOT NULL, website_id BIGINT UNSIGNED NOT NULL,
 name VARCHAR(190) NOT NULL, state VARCHAR(24) NOT NULL DEFAULT 'draft', template_name VARCHAR(128) COLLATE utf8mb4_bin NOT NULL,
 language VARCHAR(12) COLLATE utf8mb4_bin NOT NULL, template_body TEXT NOT NULL, template_hash CHAR(64) NOT NULL,
 connection_hash CHAR(64) NOT NULL, parameters_json TEXT NOT NULL, scheduled_at DATETIME NULL,
 job_pending TINYINT NOT NULL DEFAULT 0, last_error VARCHAR(255) NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 KEY wa_campaign_due (state,scheduled_at), KEY wa_campaign_owner (user_id,website_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS wa_marketing_recipients (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, campaign_id BIGINT UNSIGNED NOT NULL, contact_id BIGINT UNSIGNED NOT NULL,
 phone VARCHAR(15) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, name VARCHAR(190) NOT NULL, language VARCHAR(12) NOT NULL,
 state VARCHAR(24) NOT NULL DEFAULT 'pending', message_id VARCHAR(255) COLLATE utf8mb4_bin NULL, provider_status VARCHAR(60) NULL,
 last_error VARCHAR(255) NULL, attempt_count INT NOT NULL DEFAULT 0, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY wa_campaign_contact (campaign_id,contact_id), UNIQUE KEY wa_campaign_recipient_phone (campaign_id,phone),
 KEY wa_recipient_pending (campaign_id,state), KEY wa_message_lookup (message_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
