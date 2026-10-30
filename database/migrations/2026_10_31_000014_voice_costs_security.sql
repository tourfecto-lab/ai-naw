-- TOURFECTO AI VOICE & SALES AGENT (Phase 9): usage ledger, budget alerts, invoices, recordings. Additive only.
CREATE TABLE IF NOT EXISTS voice_usage_ledger (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 tenant_user_id INT(11) NOT NULL,
 ref VARCHAR(80) NOT NULL COMMENT 'call:<id> or number:<id>:<yyyymmdd>',
 component VARCHAR(30) NOT NULL COMMENT 'inbound_minutes, outbound_minutes, speech, ai, number_rental, storage',
 quantity DECIMAL(14,4) NOT NULL DEFAULT 0,
 unit VARCHAR(12) NOT NULL DEFAULT 'minute',
 cost_usd DECIMAL(12,6) NOT NULL DEFAULT 0 COMMENT 'what Tourfecto pays',
 price_usd DECIMAL(12,6) NOT NULL DEFAULT 0 COMMENT 'what the company pays (cost plus markup)',
 cost_source ENUM('provider','estimated','none') NOT NULL DEFAULT 'none',
 occurred_at DATETIME NOT NULL,
 updated_at DATETIME NOT NULL,
 UNIQUE KEY uq_voice_ledger (tenant_user_id, ref, component),
 KEY idx_voice_ledger_month (tenant_user_id, occurred_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS voice_budget_alerts (
 tenant_user_id INT(11) NOT NULL,
 month CHAR(7) NOT NULL,
 threshold TINYINT UNSIGNED NOT NULL,
 created_at DATETIME NOT NULL,
 PRIMARY KEY (tenant_user_id, month, threshold)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS voice_invoices (
 id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
 tenant_user_id INT(11) NOT NULL,
 month CHAR(7) NOT NULL,
 cost_usd DECIMAL(12,4) NOT NULL DEFAULT 0,
 price_usd DECIMAL(12,4) NOT NULL DEFAULT 0,
 status ENUM('open','charged','failed','waived') NOT NULL DEFAULT 'open',
 wallet_tx_id INT(11) NULL,
 error VARCHAR(255) NULL,
 created_at DATETIME NOT NULL,
 updated_at DATETIME NOT NULL,
 UNIQUE KEY uq_voice_invoice (tenant_user_id, month)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS voice_recordings (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 tenant_user_id INT(11) NOT NULL,
 call_id BIGINT UNSIGNED NOT NULL,
 provider VARCHAR(30) NOT NULL,
 recording_sid VARCHAR(64) NULL,
 status ENUM('requested','available','deleted','failed') NOT NULL DEFAULT 'requested',
 duration_s INT NULL,
 retention_until DATETIME NULL,
 created_at DATETIME NOT NULL,
 updated_at DATETIME NOT NULL,
 UNIQUE KEY uq_voice_recording (provider, recording_sid),
 KEY idx_voice_recordings_call (tenant_user_id, call_id),
 KEY idx_voice_recordings_retention (status, retention_until)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
