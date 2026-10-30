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
 max_concurrent_calls TINYINT UNSIGNED NOT NULL DEFAULT 3 COMMENT 'simultaneous AI calls this company may have',
 booking_mode ENUM('request_only','create_pending','auto_confirm') NOT NULL DEFAULT 'request_only' COMMENT 'what the AI may do with a booking request taken on a call',
 booking_max_amount DECIMAL(12,2) NULL COMMENT 'auto_confirm only bookings up to this total',
 post_call_whatsapp TINYINT(1) NOT NULL DEFAULT 0,
 post_call_email TINYINT(1) NOT NULL DEFAULT 0,
 payment_link_pct TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '0 = never send payment links, else deposit percent',
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
 greeting TEXT NULL,
 status ENUM('draft','active','paused') NOT NULL DEFAULT 'draft',
 created_by_user_id INT(11) NULL,
 created_at DATETIME NOT NULL,
 updated_at DATETIME NOT NULL,
 KEY idx_voice_agents_tenant (tenant_user_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- Auditing reuses the platform's activity_logs (module 'voice'); no table of our own.
