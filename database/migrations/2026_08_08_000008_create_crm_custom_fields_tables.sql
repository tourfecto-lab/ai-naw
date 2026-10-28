-- ============================================================
-- Tourfecto - Migration: CRM Custom Fields (المرحلة 12 - G2)
-- @version 1.0.0  @date 2026-08-15
--
-- حقول مخصصة قابلة للتعريف لكل كيان (contact/lead/deal/company).
-- الحقول تُعرَّف في جدول تعريفات، وقيمها تُخزَّن سجل-لكل-قيمة في
-- جدول منفصل (EAV بسيط) بدل تعديل سكيما الجداول الأصلية - يحافظ
-- على المبدأ المعماري "Additive فقط" بدون لمس جداول الموديول
-- الحالية أو استعلاماتها.
--
-- ملاحظة أمان: القيم تُخزَّن كـ JSON منفصل لكل سجل (value) وتُقرأ/
-- تُكتب عبر `CrmCustomFieldService` فقط - لا يوجد دمج SQL ديناميكي.
-- ============================================================

CREATE TABLE IF NOT EXISTS `crm_custom_fields` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `user_id` INT(11) NOT NULL COMMENT 'صاحب الحساب (Tenant)',
    `entity_type` ENUM('contact', 'lead', 'deal', 'company') NOT NULL,
    `field_key` VARCHAR(60) NOT NULL COMMENT 'مفتاح فريد داخل (user_id + entity_type)',
    `label` VARCHAR(255) NOT NULL COMMENT 'التسمية الظاهرة للمستخدم',
    `field_type` ENUM('text', 'number', 'date', 'select') NOT NULL DEFAULT 'text',
    `options` TEXT DEFAULT NULL COMMENT 'JSON: قائمة الخيارات لحقول select فقط',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_crm_cf_user_type_key` (`user_id`, `entity_type`, `field_key`),
    CONSTRAINT `fk_crm_cf_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='تعريفات الحقول المخصصة لكل كيان CRM - المرحلة 12 (G2)';

CREATE TABLE IF NOT EXISTS `crm_custom_field_values` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `user_id` INT(11) NOT NULL COMMENT 'صاحب الحساب (Tenant) - للتحديد السريع في البحث',
    `entity_type` ENUM('contact', 'lead', 'deal', 'company') NOT NULL,
    `entity_id` INT(11) NOT NULL,
    `field_id` INT(11) NOT NULL,
    `value` VARCHAR(500) DEFAULT NULL COMMENT 'القيمة المخزنة كنص (أي نوع)',
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_crm_cfv_entity_field` (`entity_type`, `entity_id`, `field_id`),
    INDEX `idx_crm_cfv_user_type` (`user_id`, `entity_type`),
    CONSTRAINT `fk_crm_cfv_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_crm_cfv_field` FOREIGN KEY (`field_id`) REFERENCES `crm_custom_fields` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='قيم الحقول المخصصة لكل سجل - المرحلة 12 (G2)';
