-- ============================================================
-- Tourfecto - Phase 2: Indexing + SEO A/B Testing Schema
-- @version 1.0.0  @date 2026-08-20
--
-- 1) IndexNow: فهرسة فورية (مفتاح لكل موقع + تفعيل).
-- 2) SEO A/B Testing: تجربة عناوين/أوصاف مختلفة وقياس الفائز عبر GSC.
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;

-- ============================================================
-- 1) أعمدة IndexNow على websites
-- ============================================================
ALTER TABLE `websites`
    ADD COLUMN `indexnow_key` VARCHAR(128) DEFAULT NULL
        COMMENT 'مفتاح IndexNow للفهرسة الفورية (Bing/Yandex/...)',
    ADD COLUMN `indexnow_enabled` TINYINT(1) NOT NULL DEFAULT 0
        COMMENT 'تفعيل الفهرسة الفورية التلقائية بعد كل إصلاح';

-- ============================================================
-- 2) تجارب SEO A/B (control vs variant)
-- ============================================================
CREATE TABLE IF NOT EXISTS `seo_ab_tests` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `website_id` INT(11) NOT NULL,
    `user_id` INT(11) NOT NULL,
    `name` VARCHAR(255) NOT NULL,
    `target_field` VARCHAR(50) NOT NULL COMMENT 'seo_title/seo_description/canonical_url/json_ld...',
    `target_path` VARCHAR(500) DEFAULT NULL COMMENT 'المسار المستهدف (NULL = كل الصفحات)',
    `status` ENUM('draft','running','paused','completed') NOT NULL DEFAULT 'draft',
    `winner_variant_id` INT(11) DEFAULT NULL,
    `started_at` TIMESTAMP NULL DEFAULT NULL,
    `ended_at` TIMESTAMP NULL DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_ab_website` (`website_id`),
    KEY `idx_ab_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='تجارب SEO A/B Testing';

CREATE TABLE IF NOT EXISTS `seo_ab_variants` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `test_id` INT(11) NOT NULL,
    `name` VARCHAR(100) NOT NULL COMMENT 'control / variant A / variant B...',
    `value` TEXT NOT NULL COMMENT 'القيمة المقترحة للحقل المستهدف',
    `is_control` TINYINT(1) NOT NULL DEFAULT 0,
    `traffic_weight` INT(11) NOT NULL DEFAULT 50 COMMENT 'نسبة التوزيع 0-100',
    `served_count` INT(11) NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_abv_test` (`test_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='نسخ تجربة SEO A/B';

CREATE TABLE IF NOT EXISTS `seo_ab_servings` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `test_id` INT(11) NOT NULL,
    `variant_id` INT(11) NOT NULL,
    `page_url` VARCHAR(500) NOT NULL,
    `user_agent` VARCHAR(255) DEFAULT NULL,
    `is_bot` TINYINT(1) NOT NULL DEFAULT 0,
    `served_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_abs_test` (`test_id`),
    KEY `idx_abs_page` (`page_url`(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='سجل عرض نسخ تجربة SEO A/B (للمطابقة مع GSC)';

SET FOREIGN_KEY_CHECKS = 1;
