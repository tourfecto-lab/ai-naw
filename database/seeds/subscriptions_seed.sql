-- ============================================
-- Tourfecto - Seed: Subscriptions Table
-- إدخال بيانات الاشتراكات الافتراضية
-- @version 1.0.0
-- @author Tourfecto Team
-- @copyright 2026 Tourfecto
-- ============================================

-- ============================================
-- 1. تحديد معرفات المستخدمين
-- ============================================
-- ملاحظة: يتم تحديد المعرفات بناءً على ترتيب الإدراج في جدول users
-- المعرفات المتوقعة: admin=1, system=2, arabic=3, egypt=4, europe=5

-- ============================================
-- 2. إدخال اشتراكات للمستخدمين
-- ============================================

-- اشتراك للمدير العام (Super Admin) - باقة Enterprise
INSERT INTO `subscriptions` (
    `user_id`,
    `plan_name`,
    `plan_type`,
    `status`,
    `price`,
    `currency`,
    `ai_credits`,
    `ai_credits_used`,
    `chat_credits`,
    `chat_credits_used`,
    `review_credits`,
    `review_credits_used`,
    `competitor_analysis_limit`,
    `competitor_analysis_used`,
    `auto_pilot`,
    `start_date`,
    `expiry_date`,
    `last_billed_at`,
    `next_billing_at`,
    `payment_method`,
    `payment_gateway`,
    `created_at`
) VALUES (
    1, -- admin@tourfecto.com
    'enterprise',
    'yearly',
    'active',
    2990.00,
    'USD',
    1000,
    50,
    2000,
    100,
    200,
    10,
    100,
    5,
    1,
    NOW(),
    DATE_ADD(NOW(), INTERVAL 1 YEAR),
    NOW(),
    DATE_ADD(NOW(), INTERVAL 1 YEAR),
    'credit_card',
    'stripe',
    NOW()
);

-- اشتراك لمدير النظام - باقة Professional
INSERT INTO `subscriptions` (
    `user_id`,
    `plan_name`,
    `plan_type`,
    `status`,
    `price`,
    `currency`,
    `ai_credits`,
    `ai_credits_used`,
    `chat_credits`,
    `chat_credits_used`,
    `review_credits`,
    `review_credits_used`,
    `competitor_analysis_limit`,
    `competitor_analysis_used`,
    `auto_pilot`,
    `start_date`,
    `expiry_date`,
    `last_billed_at`,
    `next_billing_at`,
    `payment_method`,
    `payment_gateway`,
    `created_at`
) VALUES (
    2, -- system@tourfecto.com
    'professional',
    'monthly',
    'active',
    99.00,
    'USD',
    200,
    30,
    500,
    50,
    50,
    5,
    20,
    3,
    1,
    NOW(),
    DATE_ADD(NOW(), INTERVAL 1 MONTH),
    NOW(),
    DATE_ADD(NOW(), INTERVAL 1 MONTH),
    'credit_card',
    'stripe',
    NOW()
);

-- اشتراك لشركة العربي للسياحة - باقة Professional
INSERT INTO `subscriptions` (
    `user_id`,
    `plan_name`,
    `plan_type`,
    `status`,
    `price`,
    `currency`,
    `ai_credits`,
    `ai_credits_used`,
    `chat_credits`,
    `chat_credits_used`,
    `review_credits`,
    `review_credits_used`,
    `competitor_analysis_limit`,
    `competitor_analysis_used`,
    `auto_pilot`,
    `start_date`,
    `expiry_date`,
    `last_billed_at`,
    `next_billing_at`,
    `payment_method`,
    `payment_gateway`,
    `created_at`
) VALUES (
    3, -- info@arabic-travel.com
    'professional',
    'monthly',
    'active',
    99.00,
    'USD',
    200,
    15,
    500,
    20,
    50,
    2,
    20,
    8,
    1,
    NOW(),
    DATE_ADD(NOW(), INTERVAL 1 MONTH),
    NOW(),
    DATE_ADD(NOW(), INTERVAL 1 MONTH),
    'credit_card',
    'paypal',
    NOW()
);

-- اشتراك لشركة مصر للسياحة - باقة Starter
INSERT INTO `subscriptions` (
    `user_id`,
    `plan_name`,
    `plan_type`,
    `status`,
    `price`,
    `currency`,
    `ai_credits`,
    `ai_credits_used`,
    `chat_credits`,
    `chat_credits_used`,
    `review_credits`,
    `review_credits_used`,
    `competitor_analysis_limit`,
    `competitor_analysis_used`,
    `auto_pilot`,
    `start_date`,
    `expiry_date`,
    `last_billed_at`,
    `next_billing_at`,
    `payment_method`,
    `payment_gateway`,
    `created_at`
) VALUES (
    4, -- info@egypt-travel.com
    'starter',
    'monthly',
    'active',
    49.00,
    'USD',
    50,
    10,
    100,
    5,
    10,
    1,
    5,
    2,
    0,
    NOW(),
    DATE_ADD(NOW(), INTERVAL 1 MONTH),
    NOW(),
    DATE_ADD(NOW(), INTERVAL 1 MONTH),
    'credit_card',
    'stripe',
    NOW()
);

-- اشتراك لشركة European Travel - باقة Enterprise
INSERT INTO `subscriptions` (
    `user_id`,
    `plan_name`,
    `plan_type`,
    `status`,
    `price`,
    `currency`,
    `ai_credits`,
    `ai_credits_used`,
    `chat_credits`,
    `chat_credits_used`,
    `review_credits`,
    `review_credits_used`,
    `competitor_analysis_limit`,
    `competitor_analysis_used`,
    `auto_pilot`,
    `start_date`,
    `expiry_date`,
    `last_billed_at`,
    `next_billing_at`,
    `payment_method`,
    `payment_gateway`,
    `created_at`
) VALUES (
    5, -- info@europe-travel.com
    'enterprise',
    'yearly',
    'active',
    2990.00,
    'USD',
    1000,
    200,
    2000,
    300,
    200,
    25,
    100,
    15,
    1,
    NOW(),
    DATE_ADD(NOW(), INTERVAL 1 YEAR),
    NOW(),
    DATE_ADD(NOW(), INTERVAL 1 YEAR),
    'bank_transfer',
    'bank',
    NOW()
);

-- ============================================
-- 3. إدخال اشتراكات منتهية للاختبار
-- ============================================

-- اشتراك منتهي (للمستخدم غير المفعل)
INSERT INTO `subscriptions` (
    `user_id`,
    `plan_name`,
    `plan_type`,
    `status`,
    `price`,
    `currency`,
    `ai_credits`,
    `ai_credits_used`,
    `chat_credits`,
    `chat_credits_used`,
    `review_credits`,
    `review_credits_used`,
    `competitor_analysis_limit`,
    `competitor_analysis_used`,
    `auto_pilot`,
    `start_date`,
    `expiry_date`,
    `created_at`
) VALUES (
    6, -- inactive@test.com
    'starter',
    'monthly',
    'expired',
    49.00,
    'USD',
    50,
    50,
    100,
    100,
    10,
    10,
    5,
    5,
    0,
    DATE_SUB(NOW(), INTERVAL 2 MONTH),
    DATE_SUB(NOW(), INTERVAL 1 MONTH),
    DATE_SUB(NOW(), INTERVAL 2 MONTH)
);

-- اشتراك ملغي
INSERT INTO `subscriptions` (
    `user_id`,
    `plan_name`,
    `plan_type`,
    `status`,
    `price`,
    `currency`,
    `ai_credits`,
    `ai_credits_used`,
    `chat_credits`,
    `chat_credits_used`,
    `review_credits`,
    `review_credits_used`,
    `competitor_analysis_limit`,
    `competitor_analysis_used`,
    `auto_pilot`,
    `start_date`,
    `expiry_date`,
    `cancelled_at`,
    `cancellation_reason`,
    `created_at`
) VALUES (
    6, -- inactive@test.com
    'professional',
    'monthly',
    'cancelled',
    99.00,
    'USD',
    200,
    100,
    500,
    200,
    50,
    20,
    20,
    10,
    0,
    DATE_SUB(NOW(), INTERVAL 3 MONTH),
    DATE_SUB(NOW(), INTERVAL 2 MONTH),
    DATE_SUB(NOW(), INTERVAL 2 MONTH),
    'غير راضٍ عن الخدمة',
    DATE_SUB(NOW(), INTERVAL 3 MONTH)
);

-- ============================================
-- 4. تحديث المعرفات المتسلسلة
-- ============================================
ALTER TABLE `subscriptions` AUTO_INCREMENT = 1000;

-- ============================================
-- 5. إضافة سجلات الفواتير للاشتراكات النشطة
-- ============================================

INSERT INTO `invoices` (
    `user_id`,
    `invoice_number`,
    `plan_name`,
    `plan_type`,
    `amount`,
    `currency`,
    `status`,
    `payment_method`,
    `transaction_id`,
    `items`,
    `due_date`,
    `paid_at`,
    `created_at`
) VALUES
(1, 'INV-20260101-001', 'enterprise', 'yearly', 2990.00, 'USD', 'paid', 'credit_card', 'txn_stripe_001', '[{"description":"Enterprise Plan - Yearly","amount":2990,"quantity":1}]', DATE_ADD(NOW(), INTERVAL 7 DAY), NOW(), NOW()),
(2, 'INV-20260101-002', 'professional', 'monthly', 99.00, 'USD', 'paid', 'credit_card', 'txn_stripe_002', '[{"description":"Professional Plan - Monthly","amount":99,"quantity":1}]', DATE_ADD(NOW(), INTERVAL 7 DAY), NOW(), NOW()),
(3, 'INV-20260101-003', 'professional', 'monthly', 99.00, 'USD', 'paid', 'credit_card', 'txn_paypal_001', '[{"description":"Professional Plan - Monthly","amount":99,"quantity":1}]', DATE_ADD(NOW(), INTERVAL 7 DAY), NOW(), NOW()),
(4, 'INV-20260101-004', 'starter', 'monthly', 49.00, 'USD', 'pending', 'credit_card', NULL, '[{"description":"Starter Plan - Monthly","amount":49,"quantity":1}]', DATE_ADD(NOW(), INTERVAL 7 DAY), NULL, NOW()),
(5, 'INV-20260101-005', 'enterprise', 'yearly', 2990.00, 'USD', 'paid', 'bank_transfer', 'txn_bank_001', '[{"description":"Enterprise Plan - Yearly","amount":2990,"quantity":1}]', DATE_ADD(NOW(), INTERVAL 7 DAY), NOW(), NOW());

-- ============================================
-- 6. ملاحظات
-- ============================================
-- الباقات المتاحة:
-- starter: 49$ شهرياً / 490$ سنوياً
-- professional: 99$ شهرياً / 990$ سنوياً
-- enterprise: 299$ شهرياً / 2990$ سنوياً
-- 
-- حالة الاشتراكات:
-- active: نشط
-- expired: منتهي
-- cancelled: ملغي
-- pending: قيد الانتظار
-- ============================================