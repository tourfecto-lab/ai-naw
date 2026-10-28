-- ============================================================
-- Tourfecto - Migration: Email Marketing Module
--
-- موديول تسويق بالبريد احترافي (منافس لـ Brevo/Mailchimp):
--   - email_lists            قوائم جمهور
--   - email_subscribers      مشتركون (مع حالة اشتراك + سجل إلغاء)
--   - email_list_subscriber  ربط مشترك بقائمة (Many-to-Many)
--   - email_templates        قوالب بريدية بمتغيرات تخصيص
--   - email_campaigns        حملات (جدولة/إرسال/تقرير)
--   - email_campaign_recipients  مستلمي حملة + تتبع فتح/كليك/إلغاء
--
-- كل الجداول IF NOT EXISTS (idempotent زي باقي ميجريشنز المشروع).
-- المبالغ/الإحصاءات DECIMAL/INT - ملاحظات التصميم جوه كل جدول.
-- @version 1.0.0  @date 2026-08-21
-- ============================================================

CREATE TABLE IF NOT EXISTS `email_lists` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `user_id` INT(11) NOT NULL COMMENT 'صاحب الحساب (tenant)',
    `name` VARCHAR(191) NOT NULL,
    `description` VARCHAR(500) NULL DEFAULT NULL,
    `subscriber_count` INT(11) NOT NULL DEFAULT 0 COMMENT 'عدّاد مُحدّث عند الاشتراك/الإلغاء',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_email_lists_user_id` (`user_id`),
    CONSTRAINT `fk_email_lists_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='قوائم الجمهور لتسويق البريد';

CREATE TABLE IF NOT EXISTS `email_subscribers` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `user_id` INT(11) NOT NULL,
    `email` VARCHAR(191) NOT NULL,
    `name` VARCHAR(191) NULL DEFAULT NULL,
    `attributes` JSON NULL DEFAULT NULL COMMENT 'خصائص إضافية للتخصيص (company, city...)',
    `status` ENUM('subscribed','unsubscribed','bounced') NOT NULL DEFAULT 'subscribed' COMMENT 'حالة الاشتراك',
    `unsubscribe_token` VARCHAR(64) NOT NULL COMMENT 'توكن فريد لرابط إلغاء الاشتراك',
    `source` VARCHAR(50) NOT NULL DEFAULT 'manual' COMMENT 'manual / import / form / campaign',
    `unsubscribed_at` TIMESTAMP NULL DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_email_subscribers_user_email` (`user_id`, `email`),
    UNIQUE KEY `uq_email_subscribers_token` (`unsubscribe_token`),
    KEY `idx_email_subscribers_status` (`status`),
    CONSTRAINT `fk_email_subscribers_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='مشتركو تسويق البريد';

CREATE TABLE IF NOT EXISTS `email_list_subscriber` (
    `list_id` INT(11) NOT NULL,
    `subscriber_id` INT(11) NOT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`list_id`, `subscriber_id`),
    KEY `idx_els_subscriber_id` (`subscriber_id`),
    CONSTRAINT `fk_els_list` FOREIGN KEY (`list_id`) REFERENCES `email_lists` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_els_subscriber` FOREIGN KEY (`subscriber_id`) REFERENCES `email_subscribers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='ربط المشترك بالقوائم';

CREATE TABLE IF NOT EXISTS `email_templates` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `user_id` INT(11) NOT NULL,
    `name` VARCHAR(191) NOT NULL,
    `subject` VARCHAR(255) NOT NULL DEFAULT '',
    `html_body` MEDIUMTEXT NULL DEFAULT NULL COMMENT 'قالب HTML مع {{variables}}',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_email_templates_user_id` (`user_id`),
    CONSTRAINT `fk_email_templates_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='قوالب حملات تسويق البريد';

CREATE TABLE IF NOT EXISTS `email_campaigns` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `user_id` INT(11) NOT NULL,
    `name` VARCHAR(191) NOT NULL,
    `subject` VARCHAR(255) NOT NULL,
    `from_name` VARCHAR(191) NULL DEFAULT NULL,
    `from_email` VARCHAR(191) NULL DEFAULT NULL COMMENT 'NULL = سيرفر الـ SMTP من .env',
    `template_id` INT(11) NULL DEFAULT NULL COMMENT 'مصدر الـ HTML اختياري',
    `list_id` INT(11) NULL DEFAULT NULL COMMENT 'الجمهور: قائمة محددة',
    `audience_ids` JSON NULL DEFAULT NULL COMMENT 'بديل: قائمة قوائم مستهدفة',
    `html_body` MEDIUMTEXT NULL DEFAULT NULL,
    `status` ENUM('draft','scheduled','sending','sent','cancelled','failed') NOT NULL DEFAULT 'draft',
    `scheduled_at` DATETIME NULL DEFAULT NULL,
    `sent_at` DATETIME NULL DEFAULT NULL,
    `total_recipients` INT(11) NOT NULL DEFAULT 0,
    `sent_count` INT(11) NOT NULL DEFAULT 0,
    `opened_count` INT(11) NOT NULL DEFAULT 0,
    `clicked_count` INT(11) NOT NULL DEFAULT 0,
    `unsubscribed_count` INT(11) NOT NULL DEFAULT 0,
    `bounced_count` INT(11) NOT NULL DEFAULT 0,
    `error_message` TEXT NULL DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_email_campaigns_user_id` (`user_id`),
    KEY `idx_email_campaigns_status` (`status`),
    CONSTRAINT `fk_email_campaigns_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_email_campaigns_list` FOREIGN KEY (`list_id`) REFERENCES `email_lists` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='حملات تسويق البريد';

CREATE TABLE IF NOT EXISTS `email_campaign_recipients` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `campaign_id` INT(11) NOT NULL,
    `subscriber_id` INT(11) NULL DEFAULT NULL,
    `email` VARCHAR(191) NOT NULL,
    `name` VARCHAR(191) NULL DEFAULT NULL,
    `status` ENUM('pending','sent','opened','clicked','unsubscribed','bounced','failed') NOT NULL DEFAULT 'pending',
    `open_token` VARCHAR(64) NULL DEFAULT NULL COMMENT 'توكن فريد لتتبع الفتح (pixel)',
    `click_token` VARCHAR(64) NULL DEFAULT NULL COMMENT 'توكن فريد لتتبع الكليك',
    `opened_at` DATETIME NULL DEFAULT NULL,
    `clicked_at` DATETIME NULL DEFAULT NULL,
    `open_count` INT(11) NOT NULL DEFAULT 0,
    `click_count` INT(11) NOT NULL DEFAULT 0,
    `error_message` TEXT NULL DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_email_cr_open_token` (`open_token`),
    UNIQUE KEY `uq_email_cr_click_token` (`click_token`),
    KEY `idx_email_cr_campaign_id` (`campaign_id`),
    KEY `idx_email_cr_status` (`status`),
    CONSTRAINT `fk_email_cr_campaign` FOREIGN KEY (`campaign_id`) REFERENCES `email_campaigns` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_email_cr_subscriber` FOREIGN KEY (`subscriber_id`) REFERENCES `email_subscribers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='مستلمو الحملة وتتبع التفاعل';
