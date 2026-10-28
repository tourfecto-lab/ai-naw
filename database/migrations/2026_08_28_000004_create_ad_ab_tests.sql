-- ============================================================
-- Tourfecto - Migration: Ad A/B Testing (بند 2)
-- @version 1.0.0  @date 2026-08-28
--
-- تجارب A/B على مستوى تنويعات الأصول الإعلانية (نص/صورة/فيديو) مع
-- توزيع نسب قابل للضبط (50/50 مثلاً) ودلالة إحصائية (chi-square على
-- CTR) تُحسب عند الطلب من بيانات الأداء الخام الحقيقية للتنويعات —
-- مفيش أي رقم مُختلق. كل تجربة مرتبطة بأصل إعلاني (creative) وحملة،
-- وتجمع تنويعات من ad_creative_variants مع وزن نسبي لكل تنويع.
--
-- إضافي و non-destructive: جداول جديدة فقط. عزل التينانت عبر user_id.
-- ============================================================

-- ------------------------------------------------------------
-- 1) التجارب (A/B Tests)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ad_ab_tests` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `user_id` INT(11) NOT NULL COMMENT 'المالك/التينانت',
    `campaign_id` INT(11) NOT NULL,
    `creative_id` INT(11) NOT NULL COMMENT 'الأصل الإعلاني موضوع التجربة',
    `name` VARCHAR(255) NOT NULL,
    `status` ENUM('draft','running','completed','archived') NOT NULL DEFAULT 'draft',
    `winning_variant_id` INT(11) DEFAULT NULL COMMENT 'تنويع الفائز بعد complete',
    `started_at` TIMESTAMP NULL DEFAULT NULL,
    `ended_at` TIMESTAMP NULL DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`campaign_id`) REFERENCES `ad_campaigns`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`creative_id`) REFERENCES `ad_creatives`(`id`) ON DELETE CASCADE,
    INDEX `idx_user_campaign` (`user_id`, `campaign_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='تجارب A/B على تنويعات الأصول الإعلانية - بند 2';

-- ------------------------------------------------------------
-- 2) تنويعات التجربة (أذرع التجربة + الأوزان النسبية)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ad_ab_test_variants` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `user_id` INT(11) NOT NULL,
    `ab_test_id` INT(11) NOT NULL,
    `creative_variant_id` INT(11) NOT NULL COMMENT 'التنويع من ad_creative_variants',
    `weight_pct` INT(11) NOT NULL DEFAULT 50 COMMENT 'النسبة المئوية لتوزيع الحركة (50/50...)',
    `is_control` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`ab_test_id`) REFERENCES `ad_ab_tests`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`creative_variant_id`) REFERENCES `ad_creative_variants`(`id`) ON DELETE CASCADE,
    UNIQUE KEY `idx_test_variant` (`ab_test_id`, `creative_variant_id`),
    INDEX `idx_user_test` (`user_id`, `ab_test_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='أذرع تجربة A/B وأوزانها النسبية - بند 2';
