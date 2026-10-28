-- ============================================================
-- Tourfecto - Migration: قوالب رسائل جاهزة (Message Templates)
-- Section 9: قوالب قابلة للتخصيص (Friendly/Professional/Short/Thank You)
-- بدل قالب واحد بس لكل موقع. أول ما موقع يفتح صفحة الإعدادات وما
-- عندوش قوالب، الكود بيزرع 4 قوالب افتراضية جاهزة (نص ثابت مكتوب في
-- الكود، مش بيانات مستخدم وهمية) - العميل بعدين يقدر يعدّلهم أو يمسحهم
-- أو يضيف قوالب مخصصة.
-- @version 1.0.0  @date 2026-08-10
-- ============================================================

CREATE TABLE IF NOT EXISTS `review_request_templates` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `website_id` INT(11) NOT NULL,
    `name` VARCHAR(100) NOT NULL COMMENT 'اسم القالب يظهر للمستخدم',
    `preset_type` ENUM('friendly', 'professional', 'short', 'thank_you', 'custom') NOT NULL DEFAULT 'custom',
    `message_template` TEXT NOT NULL COMMENT 'يدعم {name} و {review_link}',
    `email_subject` VARCHAR(190) DEFAULT NULL,
    `is_default` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'القالب المطبّق حاليًا كـ message_template في الإعدادات',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_rrt_website` (`website_id`),
    FOREIGN KEY (`website_id`) REFERENCES `websites`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='قوالب رسائل طلب المراجعة الجاهزة لكل موقع';
