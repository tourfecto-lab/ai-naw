-- تغطية AEO لكل صفحة (FAQPage/HowTo موجودين على الصفحة؟). اختياري: الكود بيشتغل بدونه (لوحة الظهور تعرض AEO "مش مقاس").
-- MySQL/MariaDB. CREATE TABLE IF NOT EXISTS صالح في الاتنين. تُشغَّل يدويًا بعد أخذ نسخة احتياطية.
CREATE TABLE IF NOT EXISTS seo_aeo_pages (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  website_id INT NOT NULL,
  user_id INT NOT NULL,
  url_hash CHAR(40) NOT NULL,
  url VARCHAR(1000) NOT NULL,
  has_faq TINYINT(1) NOT NULL DEFAULT 0,
  has_howto TINYINT(1) NOT NULL DEFAULT 0,
  answers_found TINYINT UNSIGNED NOT NULL DEFAULT 0,
  checked_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_aeo_page (website_id, url_hash),
  KEY ix_aeo_user (user_id, website_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
