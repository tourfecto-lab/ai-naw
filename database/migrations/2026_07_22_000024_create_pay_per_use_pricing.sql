-- ============================================================
-- Tourfecto - Migration: تسعير "ادفع حسب الاستخدام" من المحفظة
-- العميل من غير اشتراك (أو اللي خلّص حد باقته) يقدر يستخدم أي ميزة
-- وتتخصم تلقائيًا من رصيد محفظته بدل ما يتمنع.
-- @version 1.0.0  @date 2026-07-22
-- ============================================================

CREATE TABLE IF NOT EXISTS `pay_per_use_pricing` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `feature_key` VARCHAR(50) NOT NULL COMMENT 'ai_analysis, chat_message, competitor_analysis, review_reply',
    `label` VARCHAR(150) NOT NULL,
    `price` DECIMAL(6,2) NOT NULL DEFAULT 0,
    `currency` VARCHAR(10) NOT NULL DEFAULT 'USD',
    `currency_symbol` VARCHAR(10) NOT NULL DEFAULT '$',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'لو 0، الميزة دي مش متاحة كـ "ادفع حسب الاستخدام" - محتاج اشتراك بس',
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_feature_key` (`feature_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='تسعير الاستخدام الفردي من المحفظة - قابل للتعديل من لوحة الأدمن';

INSERT INTO `pay_per_use_pricing` (`feature_key`, `label`, `price`, `currency`, `currency_symbol`, `is_active`) VALUES
    ('ai_analysis', 'تحليل SEO/AEO/GEO واحد', 2.00, 'USD', '$', 1),
    ('chat_message', 'رد شات واحد', 0.10, 'USD', '$', 1),
    ('competitor_analysis', 'تحليل منافس واحد', 3.00, 'USD', '$', 1),
    ('review_reply', 'رد على مراجعة واحد', 0.50, 'USD', '$', 1),
    ('article_generation', 'توليد مقال تسويقي واحد', 1.50, 'USD', '$', 1)
ON DUPLICATE KEY UPDATE `feature_key` = `feature_key`;
