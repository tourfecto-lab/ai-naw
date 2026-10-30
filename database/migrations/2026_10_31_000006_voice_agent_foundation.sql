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

CREATE TABLE IF NOT EXISTS voice_audit_log (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 tenant_user_id INT(11) NOT NULL,
 actor_user_id INT(11) NULL,
 action VARCHAR(60) NOT NULL,
 subject_type VARCHAR(40) NULL,
 subject_id VARCHAR(40) NULL,
 meta TEXT NULL,
 created_at DATETIME NOT NULL,
 KEY idx_voice_audit_tenant (tenant_user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
