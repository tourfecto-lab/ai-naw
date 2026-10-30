-- TOURFECTO AI VOICE & SALES AGENT (Phase 8): AI call supervisor reviews. Additive only.
CREATE TABLE IF NOT EXISTS voice_call_reviews (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 tenant_user_id INT(11) NOT NULL,
 call_id BIGINT UNSIGNED NOT NULL,
 source ENUM('rules','model') NOT NULL,
 quality_score TINYINT NULL COMMENT '1-5, model reviews only',
 issues TEXT NULL COMMENT 'JSON list of issue codes',
 loss_reason VARCHAR(30) NULL,
 objections TEXT NULL COMMENT 'JSON list of objection codes',
 suggestions TEXT NULL COMMENT 'JSON list of short improvement suggestions',
 transcript_hash CHAR(64) NOT NULL,
 cost_usd DECIMAL(10,4) NOT NULL DEFAULT 0,
 created_at DATETIME NOT NULL,
 UNIQUE KEY uq_voice_review_call (call_id),
 KEY idx_voice_review_tenant (tenant_user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
