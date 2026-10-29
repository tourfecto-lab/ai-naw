-- TOURFECTO AI CEO (المرحلة 5): فحص موقع Tourfecto نفسه (SEO/AEO). كل فحص بيتحفظ عشان نشوف التحسن مع الوقت.
-- إضافة بس.
CREATE TABLE IF NOT EXISTS aiceo_brand_audits (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 site_url VARCHAR(255) NOT NULL,
 score TINYINT NULL,
 summary_json MEDIUMTEXT NOT NULL,
 pages_json MEDIUMTEXT NOT NULL,
 run_at DATETIME NOT NULL,
 KEY ix_aiceo_brand_audits_time (run_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
