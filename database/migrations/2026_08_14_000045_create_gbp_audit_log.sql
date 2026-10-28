-- ============================================
-- Tourfecto - Migration: GBP Audit Log
-- سجل تدقيق لعمليات GBP المهمة (بند 16 بالسبيك الأصلي، وبند 15 من
-- سبيك Finalization: Security Audit). جدول جديد بس، مفيش تعديل على أي
-- جدول موجود.
-- @version 1.0.0
-- @date 2026-08-14 (GBP Module Upgrade - Round 7: Production Finalization)
-- ============================================

CREATE TABLE IF NOT EXISTS `gbp_audit_log` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `website_id` INT(11) DEFAULT NULL,
    `user_id` INT(11) DEFAULT NULL,
    `action` VARCHAR(50) NOT NULL COMMENT 'connect, disconnect, sync, profile_update, hours_update, attributes_update, photo_upload, photo_delete, post_create, post_update, post_publish, post_delete, ai_analysis',
    `status` ENUM('success', 'failed') NOT NULL DEFAULT 'success',
    `details` TEXT DEFAULT NULL COMMENT 'JSON بمعلومات وصفية بس - ممنوع تسجيل access_token/refresh_token/client_secret هنا',
    `ip_address` VARCHAR(45) DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_gbp_audit_website` (`website_id`),
    KEY `idx_gbp_audit_user` (`user_id`),
    KEY `idx_gbp_audit_action` (`action`),
    KEY `idx_gbp_audit_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='سجل تدقيق عمليات موديول GBP - بدون أي Secrets';
