-- TOURFECTO AI VOICE & SALES AGENT (Phase 6): what happened after each call. One row per (call, action) so nothing runs twice. Additive only.
CREATE TABLE IF NOT EXISTS voice_post_call_actions (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 tenant_user_id INT(11) NOT NULL,
 call_id BIGINT UNSIGNED NOT NULL,
 action VARCHAR(30) NOT NULL COMMENT 'summary, contact, lead, note, booking, payment_link, message, task, notify, automation',
 status ENUM('done','failed','skipped') NOT NULL,
 ref VARCHAR(60) NULL,
 detail VARCHAR(255) NULL,
 created_at DATETIME NOT NULL,
 UNIQUE KEY uq_voice_post_action (call_id, action),
 KEY idx_voice_post_tenant (tenant_user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
