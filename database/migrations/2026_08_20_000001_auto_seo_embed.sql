-- ============================================
-- Tourfecto - Auto SEO Embed (External Sites)
-- توسعة Auto-Pilot ليشتغل على أي موقع خارجي (WordPress/Shopify/عادي)
-- بدل ما يكون محصور على generated_websites بتاعة الـ Website Builder.
-- مبني على نفس منطق 2026_08_08_000049_auto_pilot.sql
-- @version 1.0.0
-- ============================================

-- 1) ربط المواقع الخارجية بالمنصة (Embed Script)
ALTER TABLE `websites`
    ADD COLUMN `is_connected` TINYINT(1) NOT NULL DEFAULT 0
        COMMENT 'الموقع مربوط بسكربت Tourfecto ولا لأ' AFTER `deleted_at`,
    ADD COLUMN `connection_method` ENUM('script','api','wordpress','shopify') NOT NULL DEFAULT 'script'
        COMMENT 'طريقة الربط' AFTER `is_connected`,
    ADD COLUMN `embed_token` VARCHAR(100) DEFAULT NULL
        COMMENT 'توكن السكربت العام - بيتحط في src بتاع embed.js' AFTER `connection_method`,
    ADD COLUMN `embed_api_key` VARCHAR(100) DEFAULT NULL
        COMMENT 'مفتاح API سرّي للتحكم البرمجي' AFTER `embed_token`,
    ADD COLUMN `auto_pilot_mode` ENUM('off','conservative','balanced','aggressive') NOT NULL DEFAULT 'off'
        COMMENT 'وضع الطيار الآلي للإصلاحات الخارجية' AFTER `embed_api_key`,
    ADD COLUMN `auto_fix_enabled` TINYINT(1) NOT NULL DEFAULT 0
        COMMENT 'تفعيل التنفيذ التلقائي' AFTER `auto_pilot_mode`,
    ADD COLUMN `connected_at` TIMESTAMP NULL DEFAULT NULL AFTER `auto_fix_enabled`,
    ADD COLUMN `last_sync_at` TIMESTAMP NULL DEFAULT NULL AFTER `connected_at`,
    ADD COLUMN `total_fixes_applied` INT(11) NOT NULL DEFAULT 0 AFTER `last_sync_at`,
    ADD COLUMN `total_rollbacks` INT(11) NOT NULL DEFAULT 0 AFTER `total_fixes_applied`,
    ADD UNIQUE KEY `uniq_embed_token` (`embed_token`),
    ADD INDEX `idx_is_connected` (`is_connected`);

-- 2) الإصلاحات المطبّقة فعليًا واللي بيحقنها embed.js في المتصفح
CREATE TABLE IF NOT EXISTS `auto_seo_applied_fixes` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `website_id` INT(11) NOT NULL,
    `user_id` INT(11) NOT NULL,
    `fix_id` INT(11) DEFAULT NULL COMMENT 'wo_fixes.id لو الإصلاح جاي من Website Optimizer',
    `finding_id` INT(11) DEFAULT NULL COMMENT 'wo_audit_findings.id',
    `category` VARCHAR(50) NOT NULL COMMENT 'seo|aeo|geo|speed|security|mobile|accessibility',
    `check_key` VARCHAR(100) DEFAULT NULL COMMENT 'مفتاح الفحص - title_tag/meta_description_missing/llms_txt_missing...',
    `field_name` VARCHAR(100) NOT NULL COMMENT 'الحقل المستهدف في الصفحة',
    `injected_code` TEXT NOT NULL COMMENT 'الكود اللي هيتحقن فعليًا في <head>',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '0 = اتعمله rollback فمابيتحقنش',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    FOREIGN KEY (`website_id`) REFERENCES `websites`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    INDEX `idx_website_active` (`website_id`, `is_active`),
    INDEX `idx_check_key` (`check_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='الإصلاحات المحقونة على المواقع الخارجية عبر embed.js';

-- 3) سجل تغييرات Auto-Pilot للمواقع الخارجية (نفس فكرة auto_pilot_change_log)
CREATE TABLE IF NOT EXISTS `auto_seo_change_log` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `website_id` INT(11) NOT NULL,
    `user_id` INT(11) NOT NULL,
    `audit_id` INT(11) DEFAULT NULL,
    `finding_id` INT(11) DEFAULT NULL,
    `applied_fix_id` INT(11) DEFAULT NULL,
    `field_name` VARCHAR(100) NOT NULL,
    `old_value` TEXT DEFAULT NULL COMMENT 'القيمة القديمة قبل الحقن - أساس الـRollback',
    `new_value` TEXT NOT NULL,
    `trigger` ENUM('manual_click','audit_auto_pilot','scheduled_cron') NOT NULL DEFAULT 'manual_click',
    `mode` ENUM('off','conservative','balanced','aggressive') NOT NULL DEFAULT 'conservative',
    `rolled_back_at` TIMESTAMP NULL DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    FOREIGN KEY (`website_id`) REFERENCES `websites`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    INDEX `idx_website_created` (`website_id`, `created_at`),
    INDEX `idx_rolled_back` (`rolled_back_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='سجل تغييرات Auto SEO الخارجي مع دعم Rollback';

-- 4) توسعة wo_fixes بمفاتيح التنفيذ التلقائي الخارجي (idempotent)
ALTER TABLE `wo_fixes`
    ADD COLUMN `can_auto_apply_external` TINYINT(1) NOT NULL DEFAULT 0
        COMMENT 'الإصلاح ده ينفع يتحقن على موقع خارجي عبر embed.js',
    ADD COLUMN `field_name` VARCHAR(100) DEFAULT NULL
        COMMENT 'الحقل المستهدف - seo_title/seo_description/canonical_url/json_ld/llms_txt';
