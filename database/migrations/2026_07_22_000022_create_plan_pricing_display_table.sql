-- ============================================================
-- Tourfecto - Migration: باقات وأسعار قابلة للتعديل من لوحة الأدمن
--
-- ⚠️ ملاحظة مهمة: لاحظنا وجود استعلام حقيقي في الكود
-- (Subscription::createSubscription) بيقرا من جدول اسمه "subscription_plans"
-- بعمود "plan_code" - يعني على الأغلب عندك جدول بنفس الاسم ده على
-- السيرفر الحقيقي ببنية مختلفة تمامًا عن اللي احتجناه هنا. عشان كده
-- استخدمنا اسم مختلف تمامًا (plan_pricing_display) بدل ما نخاطر بتعارض
-- صامت مع جدولك الموجود. الجدول ده بيتحكم في:
-- - صفحة الأسعار العامة (/plans)
-- - حدود المميزات المعروضة (تحليلات AI، رسائل شات...) في أماكن تانية
-- لو عندك جدول subscription_plans حقيقي شغّال بالفعل لمحرك الفوترة،
-- سيبه زي ما هو - الجدول ده منفصل تمامًا وميلمسوش.
-- @version 1.0.0  @date 2026-07-22
-- ============================================================

CREATE TABLE IF NOT EXISTS `plan_pricing_display` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `plan_key` VARCHAR(50) NOT NULL COMMENT 'المعرف الثابت (starter, professional, enterprise) - مبيتغيّرش',
    `name` VARCHAR(150) NOT NULL COMMENT 'اسم الباقة المعروض للعميل',
    `price_monthly` DECIMAL(10,2) NOT NULL DEFAULT 0,
    `price_yearly` DECIMAL(10,2) NOT NULL DEFAULT 0,
    `currency` VARCHAR(10) NOT NULL DEFAULT 'USD' COMMENT 'رمز العملة: USD, EGP, EUR...',
    `currency_symbol` VARCHAR(10) NOT NULL DEFAULT '$' COMMENT 'الرمز المعروض فعليًا: $, ج.م, €',
    `ai_analysis` INT(11) NOT NULL DEFAULT 0 COMMENT 'عدد تحليلات AI شهريًا',
    `competitor_analysis` INT(11) NOT NULL DEFAULT 0,
    `chat_credits` INT(11) NOT NULL DEFAULT 0,
    `review_credits` INT(11) NOT NULL DEFAULT 0,
    `multiple_websites` INT(11) NOT NULL DEFAULT 1,
    `whatsapp_bot` TINYINT(1) NOT NULL DEFAULT 1,
    `auto_pilot` TINYINT(1) NOT NULL DEFAULT 0,
    `advanced_analytics` TINYINT(1) NOT NULL DEFAULT 0,
    `sort_order` INT(11) NOT NULL DEFAULT 0,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'لو 0، الباقة مش هتظهر في صفحة الأسعار',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_plan_key` (`plan_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='باقات وأسعار العرض العام - قابلة للتعديل من لوحة الأدمن';

-- تعبئة الباقات الحالية (نفس الأسعار والمميزات الموجودة في الكود، بالدولار)
INSERT INTO `plan_pricing_display`
    (`plan_key`, `name`, `price_monthly`, `price_yearly`, `currency`, `currency_symbol`,
     `ai_analysis`, `competitor_analysis`, `chat_credits`, `review_credits`, `multiple_websites`,
     `whatsapp_bot`, `auto_pilot`, `advanced_analytics`, `sort_order`, `is_active`)
VALUES
    ('starter', 'الباقة الأساسية', 49.00, 490.00, 'USD', '$',
     50, 5, 100, 10, 1, 1, 0, 0, 1, 1),
    ('professional', 'الباقة الاحترافية', 99.00, 990.00, 'USD', '$',
     200, 20, 500, 50, 3, 1, 1, 0, 2, 1),
    ('enterprise', 'الباقة المؤسسية', 299.00, 2990.00, 'USD', '$',
     1000, 100, 2000, 200, 10, 1, 1, 1, 3, 1)
ON DUPLICATE KEY UPDATE `plan_key` = `plan_key`;
