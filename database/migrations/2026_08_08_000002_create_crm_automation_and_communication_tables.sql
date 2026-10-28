-- ============================================================
-- Tourfecto - Migration: CRM Automation + Communication (المرحلة 3)
-- @version 1.0.0  @date 2026-08-08
-- ============================================================

-- ------------------------------------------------------------
-- 1) قواعد الأتمتة (بند 12، 36)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `crm_automation_rules` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `user_id` INT(11) NOT NULL COMMENT 'صاحب الحساب (Tenant)',
    `name` VARCHAR(255) NOT NULL,
    `trigger_event` VARCHAR(60) NOT NULL COMMENT 'lead.created, lead.status_changed, deal.created, deal.stage_changed, deal.won, deal.lost',
    `conditions` TEXT DEFAULT NULL COMMENT 'JSON: [{"field":"status","operator":"=","value":"qualified"}] - فاضي = بدون شرط',
    `actions` TEXT NOT NULL COMMENT 'JSON: [{"type":"assign_owner","owner_user_id":5}, {"type":"create_task","title":"..."}]',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_crm_automation_user` (`user_id`),
    INDEX `idx_crm_automation_trigger` (`trigger_event`),
    CONSTRAINT `fk_crm_automation_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='قواعد Automation Workflow لموديول CRM';

-- ------------------------------------------------------------
-- 2) محادثات القنوات (WhatsApp/Email) - بند 15، 16، 17
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `crm_conversations` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `user_id` INT(11) NOT NULL COMMENT 'صاحب الحساب (Tenant)',
    `contact_id` INT(11) DEFAULT NULL,
    `channel` ENUM('whatsapp', 'email', 'sms') NOT NULL,
    `external_thread_id` VARCHAR(255) DEFAULT NULL COMMENT 'مثال: رقم واتساب الطرف الآخر بصيغة E.164',
    `assigned_user_id` INT(11) DEFAULT NULL,
    `last_message_at` DATETIME DEFAULT NULL,
    `unread_count` INT(11) NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_crm_conv_user` (`user_id`),
    INDEX `idx_crm_conv_contact` (`contact_id`),
    INDEX `idx_crm_conv_channel_thread` (`channel`, `external_thread_id`),
    CONSTRAINT `fk_crm_conv_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_crm_conv_contact` FOREIGN KEY (`contact_id`) REFERENCES `crm_contacts` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='محادثات CRM عبر واتساب/إيميل/SMS';

-- ------------------------------------------------------------
-- 3) الرسائل داخل كل محادثة
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `crm_messages` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `conversation_id` INT(11) NOT NULL,
    `direction` ENUM('inbound', 'outbound') NOT NULL,
    `sender_user_id` INT(11) DEFAULT NULL COMMENT 'لو الرسالة outbound من عندنا',
    `body` TEXT NOT NULL,
    `subject` VARCHAR(255) DEFAULT NULL COMMENT 'للإيميل فقط',
    `status` VARCHAR(30) NOT NULL DEFAULT 'sent' COMMENT 'sent, delivered, read, failed',
    `external_message_id` VARCHAR(255) DEFAULT NULL,
    `error` VARCHAR(500) DEFAULT NULL,
    `sent_at` DATETIME NOT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_crm_msg_conversation` (`conversation_id`),
    CONSTRAINT `fk_crm_msg_conversation` FOREIGN KEY (`conversation_id`) REFERENCES `crm_conversations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='رسائل محادثات CRM';
