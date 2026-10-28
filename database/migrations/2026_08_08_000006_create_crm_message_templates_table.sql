-- ============================================================
-- Tourfecto - Migration: قوالب رسائل CRM (Email/WhatsApp/SMS)
-- @date 2026-08-08
--
-- الملف ده كان بالغلط نسخة من migration الـ ad_campaigns.platform، فجدول
-- القوالب ماكانش بيتعمل. التعريف من TOURFECTO_SCHEMA_REPAIR.sql (مع
-- user_id INT(11) زي users.id) ومطابق لـ app/Models/CrmMessageTemplate.php.
-- ============================================================

CREATE TABLE IF NOT EXISTS `crm_message_templates` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `user_id` INT(11) NOT NULL COMMENT 'صاحب الحساب (Tenant)',
    `channel` ENUM('email', 'whatsapp', 'sms') NOT NULL,
    `name` VARCHAR(255) NOT NULL,
    `subject` VARCHAR(255) DEFAULT NULL COMMENT 'للإيميل فقط',
    `body` TEXT NOT NULL,
    `variables` TEXT DEFAULT NULL COMMENT 'JSON: قائمة المتغيرات المسموحة [{"key":"name","label":"الاسم"}]',
    `created_by_user_id` INT(11) DEFAULT NULL COMMENT 'الفاعل الحقيقي اللي أنشأ القالب (Actor)',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_crm_msg_tpl_user` (`user_id`),
    INDEX `idx_crm_msg_tpl_channel` (`channel`),
    CONSTRAINT `fk_crm_msg_tpl_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='قوالب رسائل CRM (Email/WhatsApp/SMS)';
