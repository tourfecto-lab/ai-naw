-- ============================================================
-- المرحلة 14 (G8) - Email Open Tracking (تتبع فتح البريد)
-- ============================================================
-- يسجّل "من فتح الإيميل ومتى وكم مرة" عبر بكسل تتبع 1x1 يُضمّن في
-- HTML الإيميل الصادر من CrmEmailService. البكسل يُحمَّل من
-- GET /api/crm/email-track/{token}.gif (مسار عام بلا AuthMiddleware
-- لأن عملاء البريد لا يحملون جلسات المستخدم).
--
-- Additive فقط: جدول جديد - لا يعدّل جداول قائمة ولا منطق إرسال أصلي.
CREATE TABLE IF NOT EXISTS `crm_email_trackings` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `user_id` INT(11) NOT NULL COMMENT 'صاحب الحساب (Tenant)',
    `contact_id` INT(11) DEFAULT NULL COMMENT 'جهة الاتصال المرسَل إليها',
    `message_id` INT(11) DEFAULT NULL COMMENT 'سجل crm_messages المرتبط',
    `token` VARCHAR(64) NOT NULL COMMENT 'توكن فريد للبكسل',
    `email_subject` VARCHAR(255) DEFAULT NULL,
    `sent_at` TIMESTAMP NULL DEFAULT NULL,
    `first_opened_at` TIMESTAMP NULL DEFAULT NULL,
    `last_opened_at` TIMESTAMP NULL DEFAULT NULL,
    `open_count` INT(11) NOT NULL DEFAULT 0,
    `ip_address` VARCHAR(45) DEFAULT NULL,
    `user_agent` VARCHAR(255) DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_crm_email_trackings_token` (`token`),
    INDEX `idx_crm_email_trackings_user` (`user_id`),
    INDEX `idx_crm_email_trackings_message` (`message_id`),
    INDEX `idx_crm_email_trackings_contact` (`contact_id`),
    CONSTRAINT `fk_crm_email_trackings_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='تتبع فتح البريد (بكسل)';
