-- مدونة الموقع العام (/blog) - المقالات بتتكتب من لوحة الأدمن ← المدونة
CREATE TABLE IF NOT EXISTS `blog_posts` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `slug` VARCHAR(160) NOT NULL,
  `lang` VARCHAR(5) NOT NULL DEFAULT 'ar',
  `title` VARCHAR(220) NOT NULL,
  `excerpt` VARCHAR(400) NULL DEFAULT NULL,
  `body` MEDIUMTEXT NULL,
  `cover_url` VARCHAR(500) NULL DEFAULT NULL,
  `tags` VARCHAR(255) NULL DEFAULT NULL,
  `meta_description` VARCHAR(300) NULL DEFAULT NULL,
  `status` VARCHAR(12) NOT NULL DEFAULT 'draft',
  `published_at` DATETIME NULL DEFAULT NULL,
  `author_id` INT NULL DEFAULT NULL,
  `views` INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_blog_slug_lang` (`slug`, `lang`),
  KEY `idx_blog_status_pub` (`status`, `lang`, `published_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
