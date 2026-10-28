-- Tourfecto - SEO Phase 23: Performance / Reports / Scheduling
-- 1) seo_gsc_page_metrics: كاش بيانات GSC لكل صفحة (قياس CTR أسرع + تقارير قبل/بعد)
-- 2) seo_reports: لقطات قبل/بعد (درجة التدقيق + إصلاحات + مقاييس GSC)
-- 3) أعمدة الجدولة الدورية على websites (re-audit + re-index)

CREATE TABLE IF NOT EXISTS `seo_gsc_page_metrics` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `website_id` INT(11) NOT NULL,
    -- 🐛 باگ حقيقي: كانت VARCHAR(768) مع utf8mb4 (4 بايت/حرف) = 3072 بايت
    -- بس لعمود page_path لوحده، وده قبل ما نضيف الـ4 بايت بتاعة website_id
    -- في نفس الـ UNIQUE KEY المركب تحت - يتخطى الحد الأقصى لطول أي مفتاح
    -- في InnoDB (3072 بايت)، فكان CREATE TABLE بالكامل بيفشل بـ"Specified
    -- key was too long". قللناها لـ255 حرف (255×4=1020 بايت + 4 بايت
    -- website_id = 1024 بايت، بأمان تحت الحد الأقصى) - كافية لأغلب
    -- مسارات URL الحقيقية.
    `page_path` VARCHAR(255) NOT NULL,
    `clicks` INT(11) NOT NULL DEFAULT 0,
    `impressions` INT(11) NOT NULL DEFAULT 0,
    `ctr` DECIMAL(6,2) NOT NULL DEFAULT 0,
    `position` DECIMAL(6,1) NOT NULL DEFAULT 0,
    `date_start` DATE NULL,
    `date_end` DATE NULL,
    `fetched_at` DATETIME NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_site_page` (`website_id`, `page_path`),
    KEY `idx_website` (`website_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `seo_reports` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `website_id` INT(11) NOT NULL,
    `user_id` INT(11) NULL,
    `audit_id` INT(11) NULL,
    `overall_score` DECIMAL(5,2) NULL,
    `findings_total` INT(11) NOT NULL DEFAULT 0,
    `fixes_applied` INT(11) NOT NULL DEFAULT 0,
    `clicks` INT(11) NULL,
    `impressions` INT(11) NULL,
    `ctr` DECIMAL(6,2) NULL,
    `avg_position` DECIMAL(6,1) NULL,
    `source` VARCHAR(50) NOT NULL DEFAULT 'manual',
    `created_at` DATETIME NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_website` (`website_id`),
    KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- أعمدة الجدولة الدورية
ALTER TABLE `websites`
    ADD COLUMN `seo_audit_frequency` ENUM('daily','weekly','monthly') NOT NULL DEFAULT 'weekly' AFTER `last_sync_at`,
    ADD COLUMN `last_seo_audit_at` DATETIME NULL AFTER `seo_audit_frequency`,
    ADD COLUMN `last_indexnow_at` DATETIME NULL AFTER `last_seo_audit_at`;
