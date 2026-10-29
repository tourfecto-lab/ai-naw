-- TOURFECTO AI CEO (المرحلة 3): قاعدة بيانات شركات السياحة العالمية.
-- الأساس هو platform_leads الموجود (استيراد Apify وغيره، التحليل، الرسايل). الجداول دي بتضيف اللي ناقص بس:
-- تاريخ التقارير، وسجل الموافقات والاعتراضات وطلبات الحذف، وقائمة منع بعد الحذف، والدمج القابل للرجوع.
-- منفصلة عن عملاء المنصة الفعليين (users). إضافة بس، مفيش ALTER على جداول موجودة.

-- تقارير كل شركة بتتحفظ مع الوقت (كل تحليل جديد بيتضاف، مابيتمسحش القديم)
CREATE TABLE IF NOT EXISTS tbi_reports (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 lead_id INT NOT NULL,
 kind VARCHAR(30) NOT NULL,
 score TINYINT NULL,
 summary_json MEDIUMTEXT NOT NULL,
 source VARCHAR(40) NOT NULL,
 captured_at DATETIME NOT NULL,
 KEY ix_tbi_reports_lead (lead_id, captured_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- الموافقات والاعتراضات وطلبات إيقاف التواصل والحذف (مين، إمتى، من أي قناة)
CREATE TABLE IF NOT EXISTS tbi_consent_events (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 lead_id INT NULL,
 event VARCHAR(30) NOT NULL,
 channel VARCHAR(20) NOT NULL,
 source VARCHAR(40) NOT NULL,
 note VARCHAR(500) NULL,
 actor_id BIGINT UNSIGNED NULL,
 created_at DATETIME NOT NULL,
 KEY ix_tbi_consent_lead (lead_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- بعد الحذف: بصمة (hash) للإيميل/الدومين/التليفون بس، عشان الشركة ماتتستوردش ولا يتبعتلها تاني
CREATE TABLE IF NOT EXISTS tbi_suppression (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 kind VARCHAR(10) NOT NULL,
 value_hash CHAR(64) NOT NULL,
 reason VARCHAR(30) NOT NULL,
 created_at DATETIME NOT NULL,
 UNIQUE KEY uq_tbi_suppression (kind, value_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- دمج السجلات المكررة: بنحفظ اللي اتغيّر عشان الدمج يترجع
CREATE TABLE IF NOT EXISTS tbi_merges (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 primary_id INT NOT NULL,
 duplicate_id INT NOT NULL,
 reason VARCHAR(60) NOT NULL,
 filled_json TEXT NULL,
 duplicate_prev_status VARCHAR(20) NOT NULL,
 actor_id BIGINT UNSIGNED NULL,
 merged_at DATETIME NOT NULL,
 undone_at DATETIME NULL,
 KEY ix_tbi_merges_dup (duplicate_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
