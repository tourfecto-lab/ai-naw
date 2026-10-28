-- ============================================================
-- Tourfecto - Migration: Ad Creative Assets (بند 1)
-- @version 1.0.0  @date 2026-08-28
--
-- إدارة أصول الإعلانات (Creative Assets) على مستوى الإعلان/الـ Ad نفسه
-- بدل الاقتصار على نصوص ad_copies على مستوى الحملة: أصل إعلاني من نوع
-- نص/صورة/فيديو (creative_type) له مجموعة Variants (A/B/C) مع أداء حقيقي
-- (ظهور/نقرات/إنفاق/تحويلات/إيرادات) لكل Variant. CTR/CPC تُحسب عند
-- القراءة من البيانات الخام - مفيش أي رقم مُختلق، والأداء يُحدَّث فقط
-- بأرقام فعلية من المزامنة/الإدخال عبر recordPerformance.
--
-- إضافي و non-destructive تمامًا: جداول جديدة فقط، لا تعديل على أي
-- جدول/منطق قائم. عزل التينانت عبر عمود user_id في كل صف (نفس نمط
-- ad_campaigns) + فحوص ملكية في الـ Service/Controller.
-- ============================================================

-- ------------------------------------------------------------
-- 1) الأصول الإعلانية (Creative Assets) - لكل حملة
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ad_creatives` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `user_id` INT(11) NOT NULL COMMENT 'المالك/التينانت - أساس عزل البيانات',
    `campaign_id` INT(11) NOT NULL,
    `name` VARCHAR(255) NOT NULL COMMENT 'اسم الأصول الإعلانية',
    `creative_type` ENUM('text','image','video') NOT NULL DEFAULT 'text',
    `headline` VARCHAR(255) DEFAULT NULL COMMENT 'العنوان (يُستخدم لنوع text)',
    `primary_text` TEXT DEFAULT NULL COMMENT 'النص الأساسي الطويل (text)',
    `media_url` VARCHAR(500) DEFAULT NULL COMMENT 'رابط الصورة/الفيديو (image/video)',
    `status` ENUM('active','paused','archived') NOT NULL DEFAULT 'active',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`campaign_id`) REFERENCES `ad_campaigns`(`id`) ON DELETE CASCADE,
    INDEX `idx_user_campaign` (`user_id`, `campaign_id`),
    INDEX `idx_campaign_type` (`campaign_id`, `creative_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='أصول إعلانية (نص/صورة/فيديو) لكل حملة - بند 1';

-- ------------------------------------------------------------
-- 2) تنويعات الأصول (Creative Variants A/B/C) - الأداء لكل Variant
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ad_creative_variants` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `user_id` INT(11) NOT NULL COMMENT 'المالك/التينانت - نفس قيمة creative.user_id',
    `creative_id` INT(11) NOT NULL,
    `variant_label` VARCHAR(20) NOT NULL DEFAULT 'A' COMMENT 'A/B/C لاختبار التنويعات',
    `headline` VARCHAR(255) DEFAULT NULL,
    `primary_text` TEXT DEFAULT NULL,
    `media_url` VARCHAR(500) DEFAULT NULL,
    `impressions` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `clicks` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `spend` DECIMAL(12,2) NOT NULL DEFAULT 0,
    `conversions` DECIMAL(12,2) NOT NULL DEFAULT 0,
    `revenue` DECIMAL(12,2) NOT NULL DEFAULT 0,
    `is_control` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'هل هو Variant التحكم (Control)؟',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`creative_id`) REFERENCES `ad_creatives`(`id`) ON DELETE CASCADE,
    INDEX `idx_user_creative` (`user_id`, `creative_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='تنويعات الأصول الإعلانية وأداؤها الفعلي - بند 1';
