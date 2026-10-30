-- TOURFECTO AI VOICE & SALES AGENT (Phase 1): foundation. Additive only (no existing table is altered).
-- tenant_user_id = the account owner (same tenant key CrmPermissionService::resolveTenantId returns).
-- Safe defaults: module off, sandbox on, outbound off, recording off. No provider/number/call tables yet (Phase 2+).
CREATE TABLE IF NOT EXISTS voice_agent_settings (
 tenant_user_id INT(11) NOT NULL PRIMARY KEY,
 enabled TINYINT(1) NOT NULL DEFAULT 0,
 sandbox_mode TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1 = no real calls or number purchases',
 default_language VARCHAR(10) NOT NULL DEFAULT 'en',
 outbound_enabled TINYINT(1) NOT NULL DEFAULT 0,
 recording_enabled TINYINT(1) NOT NULL DEFAULT 0,
 monthly_budget_usd DECIMAL(10,2) NULL COMMENT 'NULL = no cap configured',
 human_handoff_phone VARCHAR(32) NULL,
 daily_outbound_cap SMALLINT UNSIGNED NOT NULL DEFAULT 100 COMMENT 'real outbound call attempts per rolling 24h',
 timezone VARCHAR(60) NULL COMMENT 'company time zone for business hours',
 after_hours_mode ENUM('ai','message','forward') NOT NULL DEFAULT 'ai' COMMENT 'what happens to calls outside business hours',
 knowledge_website_id INT(11) NULL COMMENT 'website whose knowledge base and business hours the assistant uses',
 supervisor_enabled TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'AI call supervisor reviews flagged and sampled calls',
 supervisor_sample_pct TINYINT UNSIGNED NOT NULL DEFAULT 20 COMMENT 'share of ordinary calls reviewed by the model (flagged calls are always reviewed)',
 supervisor_daily_cap SMALLINT UNSIGNED NOT NULL DEFAULT 20 COMMENT 'model reviews per company per day',
 max_concurrent_calls TINYINT UNSIGNED NOT NULL DEFAULT 3 COMMENT 'simultaneous AI calls this company may have',
 booking_mode ENUM('request_only','create_pending','auto_confirm') NOT NULL DEFAULT 'request_only' COMMENT 'what the AI may do with a booking request taken on a call',
 booking_max_amount DECIMAL(12,2) NULL COMMENT 'auto_confirm only bookings up to this total',
 post_call_whatsapp TINYINT(1) NOT NULL DEFAULT 0,
 post_call_email TINYINT(1) NOT NULL DEFAULT 0,
 payment_link_pct TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '0 = never send payment links, else deposit percent',
 post_call_wa_template VARCHAR(80) NULL COMMENT 'approved WhatsApp utility template (one body parameter = the message) used when the 24 h window is closed',
 post_call_wa_template_lang VARCHAR(10) NULL,
 auto_abandoned_recovery TINYINT(1) NOT NULL DEFAULT 0,
 auto_trip_reminders TINYINT(1) NOT NULL DEFAULT 0,
 auto_booking_confirmation TINYINT(1) NOT NULL DEFAULT 0,
 auto_followups_live_approved TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'standing approval for automatic calls to be real (still needs platform + company live mode)',
 whatsapp_website_id INT(11) NULL COMMENT 'website whose WhatsApp connection sends post-call messages',
 created_at DATETIME NOT NULL,
 updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS voice_agents (
 id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
 tenant_user_id INT(11) NOT NULL,
 name VARCHAR(120) NOT NULL,
 language VARCHAR(10) NOT NULL DEFAULT 'en',
 voice VARCHAR(60) NULL,
 role ENUM('reception','sales','booking','support') NOT NULL DEFAULT 'reception' COMMENT 'one shared AI engine, different focus per role',
 languages VARCHAR(80) NULL COMMENT 'additional languages the assistant may speak, comma separated',
 tone VARCHAR(20) NOT NULL DEFAULT 'friendly',
 opening_text TEXT NULL COMMENT 'the exact words spoken first (the AI disclosure is always added)',
 instructions TEXT NULL COMMENT 'how the company wants customers handled',
 status ENUM('draft','active','paused') NOT NULL DEFAULT 'draft',
 created_by_user_id INT(11) NULL,
 created_at DATETIME NOT NULL,
 updated_at DATETIME NOT NULL,
 KEY idx_voice_agents_tenant (tenant_user_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- Auditing reuses the platform's activity_logs (module 'voice'); no table of our own.
