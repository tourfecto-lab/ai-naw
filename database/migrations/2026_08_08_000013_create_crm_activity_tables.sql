-- ============================================================
-- Tourfecto - Migration: CRM Custom Activity Types (المرحلة 14 - G10)
-- @version 1.0.0  @date 2026-08-16
--
-- أنشطة/نتائج مخصصة (مثل زيارات الموقع، مكالمات، متابعات) مرتبطة
-- بأي كيان CRM (contact/lead/deal/company). يملكها المنافسون
-- (HubSpot/Pipedrive/Freshsales) ولا توجد حاليًا بالمشروع.
--
-- Additive: جدولان جديدان - لا يمس أي جدول/منطق قائم.
-- أنواع افتراضية عامة (user_id NULL) تظهر لكل الحسابات، والمستخدم
-- يستطيع إضافة أنواع مخصصة خاصة بحسابه (user_id = صاحب الحساب).
-- ============================================================

CREATE TABLE IF NOT EXISTS `crm_activity_types` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `user_id` INT(11) DEFAULT NULL COMMENT 'NULL = نوع افتراضي عام لكل الحسابات',
    `type_key` VARCHAR(50) NOT NULL,
    `name` VARCHAR(150) NOT NULL COMMENT 'التسمية الظاهرة',
    `icon` VARCHAR(50) DEFAULT NULL,
    `color` VARCHAR(20) DEFAULT '#6366f1',
    `is_system` TINYINT(1) NOT NULL DEFAULT 0,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_crm_act_types_user` (`user_id`),
    CONSTRAINT `fk_crm_act_types_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='أنواع الأنشطة القابلة للتخصيص - المرحلة 14 (G10)';

CREATE TABLE IF NOT EXISTS `crm_activities` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `user_id` INT(11) NOT NULL COMMENT 'صاحب الحساب (Tenant)',
    `activity_type_id` INT(11) NOT NULL,
    `related_type` VARCHAR(30) DEFAULT NULL COMMENT 'contact / lead / deal / company',
    `related_id` INT(11) DEFAULT NULL,
    `subject` VARCHAR(255) NOT NULL,
    `notes` TEXT DEFAULT NULL,
    `performed_at` TIMESTAMP NULL DEFAULT NULL COMMENT 'متى حدث النشاط فعليًا',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_crm_acts_user` (`user_id`),
    INDEX `idx_crm_acts_type` (`activity_type_id`),
    INDEX `idx_crm_acts_related` (`related_type`, `related_id`),
    CONSTRAINT `fk_crm_acts_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_crm_acts_type` FOREIGN KEY (`activity_type_id`) REFERENCES `crm_activity_types` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='سجلات الأنشطة المخصصة - المرحلة 14 (G10)';

-- أنواع افتراضية عامة (لو مش موجودة) - قيم ثابتة لكل الحسابات
INSERT INTO `crm_activity_types` (`user_id`, `type_key`, `name`, `icon`, `color`, `is_system`)
SELECT NULL, 'call', 'مكالمة', 'phone', '#6366f1', 1
WHERE NOT EXISTS (SELECT 1 FROM `crm_activity_types` WHERE `type_key` = 'call' AND `user_id` IS NULL);

INSERT INTO `crm_activity_types` (`user_id`, `type_key`, `name`, `icon`, `color`, `is_system`)
SELECT NULL, 'site_visit', 'زيارة موقع', 'globe', '#0ea5e9', 1
WHERE NOT EXISTS (SELECT 1 FROM `crm_activity_types` WHERE `type_key` = 'site_visit' AND `user_id` IS NULL);

INSERT INTO `crm_activity_types` (`user_id`, `type_key`, `name`, `icon`, `color`, `is_system`)
SELECT NULL, 'follow_up', 'متابعة', 'refresh', '#10b981', 1
WHERE NOT EXISTS (SELECT 1 FROM `crm_activity_types` WHERE `type_key` = 'follow_up' AND `user_id` IS NULL);

INSERT INTO `crm_activity_types` (`user_id`, `type_key`, `name`, `icon`, `color`, `is_system`)
SELECT NULL, 'meeting', 'اجتماع', 'users', '#f59e0b', 1
WHERE NOT EXISTS (SELECT 1 FROM `crm_activity_types` WHERE `type_key` = 'meeting' AND `user_id` IS NULL);

INSERT INTO `crm_activity_types` (`user_id`, `type_key`, `name`, `icon`, `color`, `is_system`)
SELECT NULL, 'email', 'بريد إلكتروني', 'mail', '#8b5cf6', 1
WHERE NOT EXISTS (SELECT 1 FROM `crm_activity_types` WHERE `type_key` = 'email' AND `user_id` IS NULL);

INSERT INTO `crm_activity_types` (`user_id`, `type_key`, `name`, `icon`, `color`, `is_system`)
SELECT NULL, 'quote', 'عرض سعر', 'file-text', '#ef4444', 1
WHERE NOT EXISTS (SELECT 1 FROM `crm_activity_types` WHERE `type_key` = 'quote' AND `user_id` IS NULL);
