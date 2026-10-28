-- ============================================================
-- Tourfecto - Migration: منشئ المواقع بالذكاء الاصطناعي (نسخة أولى)
-- العميل يدردش مع المساعد، يجاوب على أسئلة موجّهة، والنظام يولّد موقع
-- سياحي متعدد الصفحات (رئيسية/عنّا/الباقات/تواصل) بقالب احترافي ثابت
-- + محتوى AI. معاينة قبل النشر، ونشر فعلي على رابط فرعي في نطاقك.
-- @version 1.0.0  @date 2026-07-27
-- ============================================================

CREATE TABLE IF NOT EXISTS `generated_websites` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `user_id` INT(11) NOT NULL,
    `slug` VARCHAR(80) NOT NULL COMMENT 'يظهر في الرابط: tourfecto.pro/sites/{slug}',
    `status` ENUM('draft', 'published') NOT NULL DEFAULT 'draft',
    `theme_color` VARCHAR(20) NOT NULL DEFAULT 'gold' COMMENT 'gold, blue, green, red',
    `content_json` LONGTEXT NOT NULL COMMENT 'كل محتوى الموقع (نصوص، باقات، تواصل) كـ JSON',
    `custom_domain` VARCHAR(255) DEFAULT NULL COMMENT 'دومين مخصص لو ربطه العميل لاحقًا',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_slug` (`slug`),
    KEY `idx_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='المواقع المولّدة بالذكاء الاصطناعي';

-- تسعير الاستخدام (توليد موقع = عملية أكبر من رسالة شات عادية)
INSERT INTO `pay_per_use_pricing` (`feature_key`, `label`, `price`, `currency`, `currency_symbol`, `is_active`) VALUES
    ('website_generation', 'توليد موقع كامل بالذكاء الاصطناعي', 5.00, 'USD', '$', 1)
ON DUPLICATE KEY UPDATE `feature_key` = `feature_key`;

INSERT INTO `feature_flags` (`feature_key`, `label`, `is_enabled`) VALUES
    ('website_builder', 'منشئ المواقع بالذكاء الاصطناعي', 1)
ON DUPLICATE KEY UPDATE `feature_key` = `feature_key`;
