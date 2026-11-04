-- العقل الاستراتيجي: الأهداف، سياسة التشغيل الذاتي لكل مجال، تفضيلات الشركة، ذاكرة القرارات المرفوضة، وسجل التدقيق.
-- BrainSchema::ensureExtras بيعمل نفس الجداول وقت التشغيل لو الملف ده ما اتطبقش على السيرفر.
-- (من غير ADD COLUMN IF NOT EXISTS: السيرفر MySQL. كل جدول في أمر CREATE واحد.)

-- هدف قابل للقياس لكل موقع (مقياس من صورة الشركة + رقم مستهدف + تاريخ)
CREATE TABLE IF NOT EXISTS brain_goals (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 user_id BIGINT UNSIGNED NOT NULL,
 website_id BIGINT UNSIGNED NOT NULL,
 metric_key VARCHAR(60) NOT NULL,
 direction VARCHAR(8) NOT NULL DEFAULT 'up',
 target_value DECIMAL(14,4) NOT NULL,
 baseline_value DECIMAL(14,4) NOT NULL,
 deadline DATE NOT NULL,
 status VARCHAR(12) NOT NULL DEFAULT 'active',
 created_at DATETIME NOT NULL,
 closed_at DATETIME NULL,
 KEY ix_brain_goal_owner (user_id, website_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- التشغيل الذاتي لكل مجال: auto (الافتراضي لما التشغيل الذاتي مفتوح) أو suggest (اقتراح بس)
CREATE TABLE IF NOT EXISTS brain_policy (
 user_id BIGINT UNSIGNED NOT NULL,
 area VARCHAR(30) NOT NULL,
 mode VARCHAR(10) NOT NULL DEFAULT 'auto',
 updated_at DATETIME NOT NULL,
 PRIMARY KEY (user_id, area)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- تفضيلات الشركة: طاقة الأسبوع (نقاط جهد) والملخص الأسبوعي (مقفول افتراضيًا)
CREATE TABLE IF NOT EXISTS brain_prefs (
 user_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
 weekly_capacity TINYINT UNSIGNED NOT NULL DEFAULT 6,
 digest_enabled TINYINT(1) NOT NULL DEFAULT 0,
 digest_sent_at DATETIME NULL,
 updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ذاكرة القرارات: ليه الشركة رفضت قرار (مش مناسب / مش دلوقتي / اتعمل برّه) عشان العقل ما يكررهوش
CREATE TABLE IF NOT EXISTS brain_memory (
 user_id BIGINT UNSIGNED NOT NULL,
 website_id BIGINT UNSIGNED NOT NULL,
 decision_key VARCHAR(120) NOT NULL,
 reason VARCHAR(20) NOT NULL,
 snooze_until DATETIME NULL,
 created_at DATETIME NOT NULL,
 PRIMARY KEY (user_id, website_id, decision_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- سجل تدقيق: كل حاجة العقل عملها أو اتوافق عليها (مين وإمتى)
CREATE TABLE IF NOT EXISTS brain_audit (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 user_id BIGINT UNSIGNED NOT NULL,
 website_id BIGINT UNSIGNED NOT NULL,
 decision_key VARCHAR(120) NULL,
 event VARCHAR(30) NOT NULL,
 detail VARCHAR(255) NULL,
 created_at DATETIME NOT NULL,
 KEY ix_brain_audit_owner (user_id, website_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
