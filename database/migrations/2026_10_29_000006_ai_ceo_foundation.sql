-- TOURFECTO AI CEO (المرحلة 1): العقل التنفيذي لإدارة شركة Tourfecto نفسها.
-- منفصل عن عقل العملاء (brain_decisions): مفيش user_id لعميل هنا، والقرارات على مستوى المنصة.
-- BrainSchema::ensure بيعمل نفس الجداول وقت التشغيل لو الملف ده ما اتطبقش على السيرفر. إضافة بس، مفيش ALTER.

-- القرارات والمهام والموافقات (Strategic Radar للمنصة)
CREATE TABLE IF NOT EXISTS aiceo_decisions (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 decision_key VARCHAR(120) NOT NULL,
 area VARCHAR(30) NOT NULL,
 status VARCHAR(20) NOT NULL DEFAULT 'proposed',
 risk VARCHAR(10) NOT NULL DEFAULT 'medium',
 priority DECIMAL(6,2) NOT NULL DEFAULT 0,
 payload_json MEDIUMTEXT NOT NULL,
 proposed_at DATETIME NOT NULL,
 decided_at DATETIME NULL,
 decided_by BIGINT UNSIGNED NULL,
 done_at DATETIME NULL,
 last_seen_at DATETIME NOT NULL,
 UNIQUE KEY uq_aiceo_decision (decision_key),
 KEY ix_aiceo_status (status, priority)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- الذاكرة المؤسسية: قرارات وخطط ونتايج وملاحظات ومحادثات
CREATE TABLE IF NOT EXISTS aiceo_memory (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 kind VARCHAR(20) NOT NULL,
 title VARCHAR(255) NOT NULL,
 body MEDIUMTEXT NULL,
 decision_id BIGINT UNSIGNED NULL,
 created_by BIGINT UNSIGNED NULL,
 created_at DATETIME NOT NULL,
 KEY ix_aiceo_memory_kind (kind, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- سجل كل إجراء (مين/إيه/إمتى)، من صاحب المنصة أو من العقل نفسه (actor_id NULL)
CREATE TABLE IF NOT EXISTS aiceo_activity (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 actor_id BIGINT UNSIGNED NULL,
 action VARCHAR(60) NOT NULL,
 detail_json TEXT NULL,
 created_at DATETIME NOT NULL,
 KEY ix_aiceo_activity_time (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
