-- ============================================================
-- Tourfecto - Migration: إنشاء جدول subscription_plans (محرك الفوترة الفعلي)
-- @version 1.0.0  @date 2026-07-25
--
-- 🐛 باگ حقيقي اتلقى أثناء اختبار تسجيل حساب جديد على تنصيب فاضي:
-- الجدول ده بيتقرا منه/بيتعمله JOIN في أكتر من 10 أماكن في الكود
-- (Subscription::createSubscription, Subscription::activeSubscriptionRow,
-- SubscriptionValidator, AdminController, WalletService,
-- RevenueDataGateway) لكن مفيش أي ملف migration في المشروع بينشئه -
-- غالبًا لأنه اتعمل يدويًا على السيرفر الفعلي زمان (فيه تعليق صريح في
-- SubscriptionPlan.php بيأكد كده: "جدول حقيقي بنفس الاسم subscription_plans
-- شغّال بالفعل... مش عارفين بنيته"). النتيجة: أي تنصيب جديد (تنصيب على
-- سيرفر تاني، أو بيئة تطوير/اختبار) هيفشل بمجرد ما أي مستخدم يحاول
-- يشترك في باقة، بخطأ "Table 'subscription_plans' doesn't exist".
--
-- الأعمدة هنا مبنية بالظبط على استعلامات SQL الفعلية في الكود
-- (SELECT sp.plan_code, sp.billing_cycle, sp.price, sp.currency,
-- sp.ai_analysis_limit, sp.ai_message_limit, sp.review_auto_reply_limit,
-- sp.features_json, sp.name, sp.is_active) + بيانات الأسعار/الحدود
-- الحقيقية من الثابت القديم SUBSCRIPTION_PLANS في app/Config/constants.php
-- (نفس الأرقام اللي migration التحويل لـUSD بترجع لها: 49/99/299 شهري،
-- 490/990/2990 سنوي).
-- ============================================================

CREATE TABLE IF NOT EXISTS `subscription_plans` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `plan_code` VARCHAR(50) NOT NULL COMMENT 'مثال: starter_monthly, professional_yearly',
    `name` VARCHAR(100) NOT NULL,
    `billing_cycle` ENUM('monthly','yearly') NOT NULL DEFAULT 'monthly',
    `price` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `currency` VARCHAR(10) NOT NULL DEFAULT 'USD',
    `ai_analysis_limit` INT(11) NOT NULL DEFAULT 0,
    `ai_message_limit` INT(11) NOT NULL DEFAULT 0,
    `review_auto_reply_limit` INT(11) NOT NULL DEFAULT 0,
    `features_json` JSON DEFAULT NULL COMMENT 'مميزات إضافية (competitor_analysis, auto_pilot, multiple_websites, advanced_analytics, whatsapp_bot)',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `sort_order` INT(11) NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_plan_code` (`plan_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='محرك الفوترة الفعلي - الباقات وحدودها (يُقرأ من هنا عند الاشتراك)';

-- زرع الباقات الست الافتراضية (نفس بيانات SUBSCRIPTION_PLANS في
-- app/Config/constants.php) لو الجدول لسه فاضي، عشان تسجيل/اشتراك أول
-- عميل يشتغل فورًا من غير ما يحتاج تدخل يدوي من الأدمن الأول.
INSERT INTO `subscription_plans`
    (`plan_code`, `name`, `billing_cycle`, `price`, `currency`,
     `ai_analysis_limit`, `ai_message_limit`, `review_auto_reply_limit`,
     `features_json`, `is_active`, `sort_order`)
SELECT * FROM (SELECT
    'starter_monthly' AS plan_code, 'الباقة الأساسية' AS name, 'monthly' AS billing_cycle,
    49.00 AS price, 'USD' AS currency,
    50 AS ai_analysis_limit, 100 AS ai_message_limit, 10 AS review_auto_reply_limit,
    JSON_OBJECT('competitor_analysis', 5, 'whatsapp_bot', true, 'auto_pilot', false, 'multiple_websites', 1, 'advanced_analytics', false) AS features_json,
    1 AS is_active, 1 AS sort_order
UNION ALL SELECT
    'starter_yearly', 'الباقة الأساسية', 'yearly',
    490.00, 'USD',
    50, 100, 10,
    JSON_OBJECT('competitor_analysis', 5, 'whatsapp_bot', true, 'auto_pilot', false, 'multiple_websites', 1, 'advanced_analytics', false),
    1, 2
UNION ALL SELECT
    'professional_monthly', 'الباقة الاحترافية', 'monthly',
    99.00, 'USD',
    200, 500, 50,
    JSON_OBJECT('competitor_analysis', 20, 'whatsapp_bot', true, 'auto_pilot', true, 'multiple_websites', 3, 'advanced_analytics', false),
    1, 3
UNION ALL SELECT
    'professional_yearly', 'الباقة الاحترافية', 'yearly',
    990.00, 'USD',
    200, 500, 50,
    JSON_OBJECT('competitor_analysis', 20, 'whatsapp_bot', true, 'auto_pilot', true, 'multiple_websites', 3, 'advanced_analytics', false),
    1, 4
UNION ALL SELECT
    'enterprise_monthly', 'الباقة المؤسسية', 'monthly',
    299.00, 'USD',
    1000, 2000, 200,
    JSON_OBJECT('competitor_analysis', 100, 'whatsapp_bot', true, 'auto_pilot', true, 'multiple_websites', 10, 'advanced_analytics', true),
    1, 5
UNION ALL SELECT
    'enterprise_yearly', 'الباقة المؤسسية', 'yearly',
    2990.00, 'USD',
    1000, 2000, 200,
    JSON_OBJECT('competitor_analysis', 100, 'whatsapp_bot', true, 'auto_pilot', true, 'multiple_websites', 10, 'advanced_analytics', true),
    1, 6
) AS seed_data
WHERE NOT EXISTS (SELECT 1 FROM `subscription_plans` LIMIT 1);
