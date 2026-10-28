-- ============================================================
-- Tourfecto - Migration: المساعد الذكي (شات AI عام بهوية موقعك)
-- محادثات ورسائل محفوظة لكل عميل، مدفوعة من رصيد المحفظة (ادفع حسب
-- الاستخدام) أو مجانية حسب باقة الاشتراك - قابل للتحكم بالكامل من
-- لوحة الأدمن (تشغيل/إيقاف عن طريق نظام feature_flags الموجود بالفعل).
-- @version 1.0.0  @date 2026-07-26
-- ============================================================

CREATE TABLE IF NOT EXISTS `ai_assistant_conversations` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `user_id` INT(11) NOT NULL,
    `title` VARCHAR(200) NOT NULL DEFAULT 'محادثة جديدة',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='محادثات المساعد الذكي';

CREATE TABLE IF NOT EXISTS `ai_assistant_messages` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `conversation_id` INT(11) NOT NULL,
    `role` ENUM('user', 'assistant') NOT NULL,
    `content` LONGTEXT NOT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_conversation_id` (`conversation_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='رسائل محادثات المساعد الذكي';

-- تسعير الاستخدام (نفس جدول "ادفع حسب الاستخدام" الموجود بالفعل من المحفظة)
INSERT INTO `pay_per_use_pricing` (`feature_key`, `label`, `price`, `currency`, `currency_symbol`, `is_active`) VALUES
    ('ai_assistant_message', 'رسالة واحدة للمساعد الذكي', 0.05, 'USD', '$', 1)
ON DUPLICATE KEY UPDATE `feature_key` = `feature_key`;

-- تسجيل الميزة في نظام التحكم بالميزات (تشغيل/إيقاف من لوحة الأدمن)
INSERT INTO `feature_flags` (`feature_key`, `label`, `is_enabled`) VALUES
    ('ai_assistant', 'المساعد الذكي', 1)
ON DUPLICATE KEY UPDATE `feature_key` = `feature_key`;
