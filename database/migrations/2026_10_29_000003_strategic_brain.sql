-- العقل الاستراتيجي لكل شركة: القرارات المقترحة، وحالتها، وقياس النتيجة قبل وبعد.
-- BrainSchema::ensure بيعمل نفس الجدول وقت التشغيل لو الملف ده ما اتطبقش على السيرفر.
CREATE TABLE IF NOT EXISTS brain_decisions (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 user_id BIGINT UNSIGNED NOT NULL,
 website_id BIGINT UNSIGNED NOT NULL,
 decision_key VARCHAR(120) NOT NULL,
 area VARCHAR(30) NOT NULL,
 status VARCHAR(20) NOT NULL DEFAULT 'proposed',
 priority DECIMAL(6,2) NOT NULL DEFAULT 0,
 requires_spend TINYINT(1) NOT NULL DEFAULT 0,
 payload_json MEDIUMTEXT NOT NULL,
 metric_key VARCHAR(60) NULL,
 metric_direction VARCHAR(8) NULL,
 baseline_value DECIMAL(14,4) NULL,
 result_value DECIMAL(14,4) NULL,
 outcome VARCHAR(20) NULL,
 proposed_at DATETIME NOT NULL,
 decided_at DATETIME NULL,
 done_at DATETIME NULL,
 measured_at DATETIME NULL,
 last_seen_at DATETIME NOT NULL,
 UNIQUE KEY uq_brain_decision (user_id, website_id, decision_key),
 KEY ix_brain_owner (user_id, website_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
