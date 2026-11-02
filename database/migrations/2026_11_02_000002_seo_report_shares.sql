-- روابط مشاركة تقرير الظهور (SEO/AEO/GEO) مع العميل. اختياري: الكود بيشتغل بدونه (إنشاء الرابط بيقول إنه محتاج تفعيل).
-- بنخزّن SHA-256 للتوكن فقط (التوكن الخام بيظهر مرة واحدة وقت الإنشاء). MySQL/MariaDB. تُشغَّل يدويًا بعد أخذ نسخة احتياطية.
CREATE TABLE IF NOT EXISTS seo_report_shares (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT NOT NULL,
  website_id INT NOT NULL,
  token_hash CHAR(64) NOT NULL,
  lang CHAR(2) NOT NULL DEFAULT 'ar',
  created_at DATETIME NOT NULL,
  expires_at DATETIME NOT NULL,
  revoked_at DATETIME NULL,
  last_viewed_at DATETIME NULL,
  view_count INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_report_token (token_hash),
  KEY ix_report_site (website_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
