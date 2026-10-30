-- TOURFECTO AI VOICE & SALES AGENT (Phase 5): outbound jobs, consent, do-not-call. Additive only.
CREATE TABLE IF NOT EXISTS voice_outbound_jobs (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 tenant_user_id INT(11) NOT NULL,
 number_id INT(11) NOT NULL COMMENT 'caller ID / from number',
 agent_id INT(11) NOT NULL,
 contact_id INT(11) NULL,
 to_e164 VARCHAR(20) NOT NULL,
 phone_key CHAR(9) NOT NULL,
 country CHAR(2) NULL,
 tz VARCHAR(60) NULL,
 purpose VARCHAR(30) NOT NULL,
 category ENUM('transactional','service','marketing') NOT NULL,
 context_json TEXT NULL,
 status ENUM('scheduled','dialing','completed','blocked','exhausted','cancelled') NOT NULL DEFAULT 'scheduled',
 blocked_reason VARCHAR(60) NULL,
 attempts TINYINT NOT NULL DEFAULT 0,
 max_attempts TINYINT NOT NULL DEFAULT 3,
 min_gap_hours TINYINT UNSIGNED NULL COMMENT 'hours between attempts to the same person (1-48); NULL = policy default',
 campaign_id BIGINT UNSIGNED NULL,
 next_attempt_at DATETIME NOT NULL,
 last_attempt_at DATETIME NULL,
 last_result VARCHAR(20) NULL COMMENT 'answered, busy, no-answer, voicemail, failed, simulated',
 last_call_sid VARCHAR(80) NULL,
 approved_live TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'a person explicitly approved real calls for this job',
 created_by_user_id INT(11) NOT NULL,
 created_at DATETIME NOT NULL,
 updated_at DATETIME NOT NULL,
 KEY idx_voice_jobs_due (status, next_attempt_at),
 KEY idx_voice_jobs_tenant (tenant_user_id, status),
 KEY idx_voice_jobs_phone (tenant_user_id, phone_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS voice_campaigns (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 tenant_user_id INT(11) NOT NULL,
 name VARCHAR(120) NOT NULL,
 purpose VARCHAR(30) NOT NULL,
 number_id INT(11) NOT NULL,
 agent_id INT(11) NOT NULL,
 status ENUM('active','paused','cancelled') NOT NULL DEFAULT 'active',
 max_attempts TINYINT NOT NULL DEFAULT 3,
 min_gap_hours TINYINT UNSIGNED NULL,
 tz VARCHAR(60) NULL COMMENT 'default time zone for recipients without their own',
 approve_live TINYINT(1) NOT NULL DEFAULT 0,
 created_by_user_id INT(11) NOT NULL,
 created_at DATETIME NOT NULL,
 updated_at DATETIME NOT NULL,
 KEY idx_voice_campaigns_tenant (tenant_user_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS voice_consents (
 id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
 tenant_user_id INT(11) NOT NULL,
 phone_key CHAR(9) NOT NULL,
 e164 VARCHAR(20) NOT NULL,
 contact_id INT(11) NULL,
 purpose ENUM('marketing') NOT NULL DEFAULT 'marketing',
 status ENUM('granted','withdrawn') NOT NULL,
 source VARCHAR(30) NOT NULL,
 evidence VARCHAR(500) NULL COMMENT 'how and when consent was obtained (form, recording id, document)',
 granted_by_user_id INT(11) NULL,
 granted_at DATETIME NULL,
 withdrawn_at DATETIME NULL,
 updated_at DATETIME NOT NULL,
 UNIQUE KEY uq_voice_consent (tenant_user_id, phone_key, purpose)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS voice_dnc (
 id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
 tenant_user_id INT(11) NOT NULL COMMENT '0 = platform-wide list',
 phone_key CHAR(9) NOT NULL,
 e164 VARCHAR(20) NOT NULL,
 reason VARCHAR(120) NULL,
 source ENUM('opt_out_call','manual','import','complaint','platform') NOT NULL DEFAULT 'manual',
 created_by_user_id INT(11) NULL,
 created_at DATETIME NOT NULL,
 UNIQUE KEY uq_voice_dnc (tenant_user_id, phone_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS voice_outbound_attempts (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 tenant_user_id INT(11) NOT NULL,
 job_id BIGINT UNSIGNED NOT NULL,
 phone_key CHAR(9) NOT NULL,
 simulated TINYINT(1) NOT NULL DEFAULT 0,
 result VARCHAR(20) NULL,
 created_at DATETIME NOT NULL,
 KEY idx_voice_attempts_phone (tenant_user_id, phone_key, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
