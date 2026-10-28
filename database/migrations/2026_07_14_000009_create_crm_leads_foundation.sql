-- ============================================================
-- Tourfecto - Migration: أساس CRM حقيقي من ai-marketing-automation-pro
-- @version 1.0.0  @date 2026-07-14
--
-- نطاق هذه الهجرة (مقصود ومحدود): جهات الاتصال (Contacts) والعملاء
-- المحتملين (Leads) فقط - تقوّي لوحة CRM الموجودة فعلاً برصيد بيانات
-- حقيقي بدل الاكتفاء بعرض المواقع/المراجعات فقط.
--
-- الموديول الأصلي كان نظام Multi-Tenant منفصل بالكامل (`tenants` table
-- خاص به). تم استبدال `tenant_id` هنا بـ `agency_id` (اختياري، NULL
-- لو العميل مباشر بدون وكالة) ليشير لجدول `agencies` الموحّد الموجود
-- بالفعل من دمج White-Label، بدل نظام تعدد مساحات عمل موازٍ.
--
-- محرك الحملات البريدية/SMS/WhatsApp/الـ Workflows الآلية (24 جدول
-- إضافي في الموديول الأصلي: workflows, journeys, email_campaigns,
-- sms_messages, whatsapp_messages...) **لم يُدمج في هذه المرحلة** -
-- نطاقه وتعقيده (محرك تنفيذ Workflow كامل + تكامل بريد/SMS/WhatsApp
-- حقيقي) يحتاج مرحلة منفصلة مخطط لها بعناية بدل دمج جزئي غير مكتمل.
-- ============================================================

CREATE TABLE IF NOT EXISTS `crm_contacts` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `user_id` INT(11) NOT NULL COMMENT 'صاحب سجل جهة الاتصال (وكالة أو مستخدم مباشر)',
    `agency_id` INT(11) DEFAULT NULL COMMENT 'NULL = عميل مباشر بدون وكالة',
    `name` VARCHAR(255) NOT NULL,
    `email` VARCHAR(255) DEFAULT NULL,
    `phone` VARCHAR(50) DEFAULT NULL,
    `source` VARCHAR(100) DEFAULT NULL COMMENT 'website_form, manual, import...',
    `notes` TEXT DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`agency_id`) REFERENCES `agencies`(`id`) ON DELETE SET NULL,
    INDEX `idx_user_id` (`user_id`),
    INDEX `idx_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جهات اتصال CRM';

CREATE TABLE IF NOT EXISTS `crm_leads` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `contact_id` INT(11) NOT NULL,
    `owner_user_id` INT(11) DEFAULT NULL COMMENT 'المسؤول عن متابعة هذا العميل المحتمل',
    `status` ENUM('new','nurturing','qualified','disqualified','converted') NOT NULL DEFAULT 'new',
    `score` SMALLINT NOT NULL DEFAULT 0,
    `last_engagement_at` TIMESTAMP NULL DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    FOREIGN KEY (`contact_id`) REFERENCES `crm_contacts`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`owner_user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    INDEX `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='عملاء محتملون (Leads) مرتبطون بجهة اتصال';
