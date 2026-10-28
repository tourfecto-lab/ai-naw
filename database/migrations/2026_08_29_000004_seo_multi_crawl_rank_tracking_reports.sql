-- ============================================================
-- Tourfecto - Migration: SEO/AutoSeo Module M6
-- @date 2026-08-29
--
-- يغلق فجوات من COMPETITIVE_ANALYSIS_SeoAutoSeo.md:
--   G1 زحف كامل للموقع (Multi-page crawl)
--   G3 الفهرسة لدى Google (Google Indexing API)
--   G6 تقرير بصري + تقارير مجدولة (Email)
--   G7 Rank Tracking (تتبع ترتيب يومي للكلمات المفتاحية)
--
-- كل الجداول CREATE TABLE IF NOT EXISTS - غير هدّامة، آمنة على قاعدة
-- بيانات حية بها بيانات فعلية (نفس نمط باقي ميجريشنات الموديول).
-- ============================================================

-- ------------------------------------------------------------
-- G1: صفحات الزحف المتعدد (نتائج الزحف لكل URL)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `seo_crawl_pages` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `website_id` INT(11) NOT NULL,
    `user_id` INT(11) NOT NULL,
    `crawl_id` VARCHAR(40) NOT NULL COMMENT 'معرّف جلسة الزحف (تجميع صفحات دورة واحدة)',
    `url` VARCHAR(1000) NOT NULL,
    `status_code` INT(11) DEFAULT NULL,
    `depth` TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'عمق الصفحة من الصفحة الرئيسية',
    `title` VARCHAR(500) DEFAULT NULL,
    `title_length` SMALLINT UNSIGNED DEFAULT NULL,
    `has_meta_description` TINYINT(1) NOT NULL DEFAULT 0,
    `h1_count` TINYINT UNSIGNED DEFAULT NULL,
    `h1_text` VARCHAR(500) DEFAULT NULL,
    `word_count` INT(11) DEFAULT NULL,
    `http_time_ms` INT(11) DEFAULT NULL,
    `fetch_error` VARCHAR(255) DEFAULT NULL,
    `checked_at` DATETIME NOT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_seo_crawl_website` (`website_id`, `crawl_id`),
    KEY `idx_seo_crawl_user` (`user_id`),
    KEY `idx_seo_crawl_url` (`url`(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='نتائج الزحف المتعدد للموقع (G1)';

-- ------------------------------------------------------------
-- G7: سجل تتبع ترتيب الكلمات المفتاحية عبر الزمن
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `seo_rank_tracking_history` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `website_id` INT(11) NOT NULL,
    `user_id` INT(11) NOT NULL,
    `keyword` VARCHAR(255) NOT NULL,
    `position` SMALLINT UNSIGNED DEFAULT NULL COMMENT 'ترتيب الظهور (1-100)؛ NULL = خارج أول 100',
    `url` VARCHAR(1000) DEFAULT NULL,
    `source` VARCHAR(100) NOT NULL DEFAULT 'manual' COMMENT 'مصدر القياس: manual / integration:{name}',
    `checked_at` DATETIME NOT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_seo_rth_website_keyword` (`website_id`, `keyword`, `checked_at`),
    KEY `idx_seo_rth_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='تاريخ تتبع ترتيب الكلمات المفتاحية للموقع (G7)';

-- ------------------------------------------------------------
-- G6: جدولة تقارير SEO البريدية
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `seo_report_schedules` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `website_id` INT(11) NOT NULL,
    `user_id` INT(11) NOT NULL,
    `frequency` ENUM('daily','weekly','monthly') NOT NULL DEFAULT 'weekly',
    `weekday` TINYINT(1) DEFAULT NULL COMMENT '0=الجمعة ... 6=الخميس (للتقارير الأسبوعية)',
    `hour` TINYINT(2) UNSIGNED NOT NULL DEFAULT 8 COMMENT '0-23',
    `recipient_email` VARCHAR(255) NOT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `last_sent_at` DATETIME DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_seo_rs_website` (`website_id`, `is_active`),
    KEY `idx_seo_rs_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='جدولة تقارير SEO البريدية (G6)';

-- ------------------------------------------------------------
-- G3: تفعيل الفهرسة عبر Google Indexing API لكل موقع
-- ------------------------------------------------------------
SET @__fx_col_1_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'websites' AND COLUMN_NAME = 'google_indexing_enabled'
);
SET @__fx_col_1_sql := IF(@__fx_col_1_exists = 0,
    'ALTER TABLE `websites` ADD COLUMN `google_indexing_enabled` TINYINT(1) NOT NULL DEFAULT 0
    COMMENT ''تفعيل الإبلاغ لـ Google Indexing API'' AFTER `indexnow_enabled`',
    'SELECT 1');
PREPARE __fx_col_1_stmt FROM @__fx_col_1_sql;
EXECUTE __fx_col_1_stmt;
DEALLOCATE PREPARE __fx_col_1_stmt;

SET @__fx_col_2_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'websites' AND COLUMN_NAME = 'last_google_indexed_at'
);
SET @__fx_col_2_sql := IF(@__fx_col_2_exists = 0,
    'ALTER TABLE `websites` ADD COLUMN `last_google_indexed_at` DATETIME DEFAULT NULL
    COMMENT ''آخر وقت إبلاغ لـ Google Indexing API'' AFTER `google_indexing_enabled`',
    'SELECT 1');
PREPARE __fx_col_2_stmt FROM @__fx_col_2_sql;
EXECUTE __fx_col_2_stmt;
DEALLOCATE PREPARE __fx_col_2_stmt;

SET @__fx_col_3_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'websites' AND COLUMN_NAME = 'last_rank_tracked_at'
);
SET @__fx_col_3_sql := IF(@__fx_col_3_exists = 0,
    'ALTER TABLE `websites` ADD COLUMN `last_rank_tracked_at` DATETIME DEFAULT NULL
    COMMENT ''آخر وقت فحص ترتيب الكلمات المفتاحية (Rank Tracking)'' AFTER `last_google_indexed_at`',
    'SELECT 1');
PREPARE __fx_col_3_stmt FROM @__fx_col_3_sql;
EXECUTE __fx_col_3_stmt;
DEALLOCATE PREPARE __fx_col_3_stmt;
