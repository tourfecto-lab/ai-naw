-- ============================================================
-- Tourfecto - Migration: CRM Contact Lifecycle (المرحلة 13 - G6)
-- @version 1.0.0  @date 2026-08-15
--
-- مراحل دورة حياة للعميل (جديد/مؤهل/عميل/خامل/مفقود) منفصلة عن
-- حالة الـLead - تفصل "حالة العلاقة" عن "مرحلة الفرصة". الميزة
-- يملكها كل المنافسين الكبار.
--
-- عمود lifecycle_stage يُضاف إلى crm_contacts (نفس نمط ALTER المستخدم
-- في migrations سابقة مثل 000044/000051)، ومراحل مخصصة قابلة للتعريف
-- في جدول crm_lifecycle_stages (user_id NULL = افتراضي عام). التعديل
-- إضافة خالصة على جدول موجود - لا يمس أي استعلام قائم.
-- ============================================================

ALTER TABLE `crm_contacts`
    ADD COLUMN `lifecycle_stage` VARCHAR(50) DEFAULT NULL COMMENT 'مرحلة دورة حياة العميل - المرحلة 13 (G6): lead/qualified/customer/inactive/churned أو مرحلة مخصصة' AFTER `status`;

CREATE TABLE IF NOT EXISTS `crm_lifecycle_stages` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `user_id` INT(11) DEFAULT NULL COMMENT 'NULL = مرحلة افتراضية عامة لكل الحسابات',
    `stage_key` VARCHAR(50) NOT NULL,
    `name` VARCHAR(150) NOT NULL COMMENT 'التسمية الظاهرة',
    `color` VARCHAR(20) DEFAULT '#6366f1',
    `sort_order` INT(11) NOT NULL DEFAULT 0,
    `is_system` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_crm_lcs_user` (`user_id`),
    CONSTRAINT `fk_crm_lcs_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='مراحل دورة حياة العملاء (قابلة للتخصيص) - المرحلة 13 (G6)';

-- مراحل افتراضية عامة (لو مش موجودة) - قيم ثابتة لكل الحسابات
INSERT INTO `crm_lifecycle_stages` (`user_id`, `stage_key`, `name`, `color`, `sort_order`, `is_system`)
SELECT NULL, 'lead', 'عميل محتمل', '#6366f1', 0, 1
WHERE NOT EXISTS (SELECT 1 FROM `crm_lifecycle_stages` WHERE `stage_key` = 'lead' AND `user_id` IS NULL);

INSERT INTO `crm_lifecycle_stages` (`user_id`, `stage_key`, `name`, `color`, `sort_order`, `is_system`)
SELECT NULL, 'qualified', 'مؤهل', '#10b981', 1, 1
WHERE NOT EXISTS (SELECT 1 FROM `crm_lifecycle_stages` WHERE `stage_key` = 'qualified' AND `user_id` IS NULL);

INSERT INTO `crm_lifecycle_stages` (`user_id`, `stage_key`, `name`, `color`, `sort_order`, `is_system`)
SELECT NULL, 'customer', 'عميل', '#0ea5e9', 2, 1
WHERE NOT EXISTS (SELECT 1 FROM `crm_lifecycle_stages` WHERE `stage_key` = 'customer' AND `user_id` IS NULL);

INSERT INTO `crm_lifecycle_stages` (`user_id`, `stage_key`, `name`, `color`, `sort_order`, `is_system`)
SELECT NULL, 'inactive', 'خامل', '#f59e0b', 3, 1
WHERE NOT EXISTS (SELECT 1 FROM `crm_lifecycle_stages` WHERE `stage_key` = 'inactive' AND `user_id` IS NULL);

INSERT INTO `crm_lifecycle_stages` (`user_id`, `stage_key`, `name`, `color`, `sort_order`, `is_system`)
SELECT NULL, 'churned', 'مفقود', '#ef4444', 4, 1
WHERE NOT EXISTS (SELECT 1 FROM `crm_lifecycle_stages` WHERE `stage_key` = 'churned' AND `user_id` IS NULL);
