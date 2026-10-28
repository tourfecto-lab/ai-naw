-- ============================================================
-- Tourfecto - Migration: Auto Pilot (Phase 13)
-- إضافي بالكامل - مفيش أي عمود أو جدول موجود اتحذف. الوضع الافتراضي
-- 'conservative' يعني السلوك الحالي (اقتراحات بس، مفيش تطبيق تلقائي)
-- بيفضل زي ما هو بالظبط لأي موقع موجود بالفعل - محدش هيتفاجئ بتغيير تلقائي.
-- ============================================================

ALTER TABLE `generated_websites`
    ADD COLUMN `auto_pilot_mode` ENUM('conservative','balanced','aggressive') NOT NULL DEFAULT 'conservative'
        COMMENT 'conservative = اقتراحات بس (الافتراضي - نفس السلوك الحالي). balanced/aggressive = تطبيق تلقائي للإصلاحات منخفضة الخطورة المدعومة (title_tag/meta_description حاليًا)'
        AFTER `custom_domain`;

CREATE TABLE IF NOT EXISTS `auto_pilot_change_log` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `generated_website_id` BIGINT UNSIGNED NOT NULL,
    `fix_id` BIGINT UNSIGNED NULL COMMENT 'مرجع wo_fixes اللي سبب التعديل ده (ممكن يبقى NULL لو المصدر مش Website Optimizer)',
    `field_name` VARCHAR(50) NOT NULL COMMENT 'اسم عمود generated_websites اللي اتغيّر (seo_title/seo_description...)',
    `old_value` TEXT NULL COMMENT 'القيمة قبل التعديل - ده اللي بيرجعله الـRollback',
    `new_value` TEXT NULL,
    `trigger` ENUM('manual_click','audit_auto_pilot') NOT NULL DEFAULT 'manual_click'
        COMMENT 'manual_click = العميل ضغط تطبيق تلقائي بنفسه، audit_auto_pilot = اتطبق تلقائيًا وقت Audit بسبب auto_pilot_mode',
    `applied_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `rolled_back_at` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    INDEX `idx_apcl_website` (`generated_website_id`),
    INDEX `idx_apcl_fix` (`fix_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='سجل كل تعديل Auto-Apply/Auto-Pilot - يسمح بـRollback فعلي (Phase 13)';
