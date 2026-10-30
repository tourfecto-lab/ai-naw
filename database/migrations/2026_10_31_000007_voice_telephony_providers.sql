-- TOURFECTO AI VOICE & SALES AGENT (Phase 2): telephony providers, provider errors, call costs, webhook idempotency. Additive only.
CREATE TABLE IF NOT EXISTS voice_providers (
 id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
 tenant_user_id INT(11) NOT NULL,
 provider VARCHAR(30) NOT NULL,
 credentials_enc MEDIUMTEXT NOT NULL COMMENT 'AES encrypted JSON; never returned by the API',
 status ENUM('untested','ok','failed') NOT NULL DEFAULT 'untested',
 last_test_at DATETIME NULL,
 last_test_detail VARCHAR(255) NULL,
 created_at DATETIME NOT NULL,
 updated_at DATETIME NOT NULL,
 UNIQUE KEY uq_voice_provider_tenant (tenant_user_id, provider)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS voice_provider_errors (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 tenant_user_id INT(11) NOT NULL,
 provider VARCHAR(30) NOT NULL,
 operation VARCHAR(40) NOT NULL,
 http_code SMALLINT NOT NULL DEFAULT 0,
 provider_code VARCHAR(40) NULL,
 message VARCHAR(500) NULL,
 created_at DATETIME NOT NULL,
 KEY idx_voice_err_tenant (tenant_user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS voice_call_costs (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 tenant_user_id INT(11) NOT NULL,
 provider VARCHAR(30) NOT NULL,
 call_id VARCHAR(80) NOT NULL,
 direction ENUM('inbound','outbound') NOT NULL DEFAULT 'inbound',
 duration_s INT NULL,
 cost_usd DECIMAL(12,4) NULL,
 cost_source ENUM('provider','estimated','none') NOT NULL DEFAULT 'none',
 created_at DATETIME NOT NULL,
 updated_at DATETIME NOT NULL,
 UNIQUE KEY uq_voice_cost_call (provider, call_id),
 KEY idx_voice_cost_tenant (tenant_user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS voice_webhook_events (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 tenant_user_id INT(11) NOT NULL,
 provider VARCHAR(30) NOT NULL,
 event_id VARCHAR(160) NOT NULL,
 call_id VARCHAR(80) NULL,
 status VARCHAR(40) NULL,
 created_at DATETIME NOT NULL,
 UNIQUE KEY uq_voice_event (provider, event_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
