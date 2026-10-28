-- ============================================
-- Tourfecto - Seed: Bot Settings Table
-- إدخال بيانات إعدادات البوت الافتراضية
-- @version 1.0.0
-- @author Tourfecto Team
-- @copyright 2026 Tourfecto
-- ============================================

-- ============================================
-- 1. الحصول على معرفات المستخدمين والمواقع
-- ============================================
-- ملاحظة: يتم إنشاء مواقع افتراضية للمستخدمين أولاً

-- ============================================
-- 2. إنشاء مواقع افتراضية للمستخدمين
-- ============================================

-- موقع للمدير العام
INSERT INTO `websites` (
    `user_id`,
    `main_url`,
    `company_name`,
    `industry`,
    `target_language`,
    `target_country`,
    `meta_description`,
    `is_verified`,
    `created_at`
) VALUES (
    1,
    'https://tourfecto.com',
    'Tourfecto Main',
    'tourism',
    'ar',
    'SA',
    'منصة سياحية ذكية متكاملة',
    1,
    NOW()
);

-- موقع لشركة العربي للسياحة
INSERT INTO `websites` (
    `user_id`,
    `main_url`,
    `company_name`,
    `industry`,
    `target_language`,
    `target_country`,
    `meta_description`,
    `competitor_1_url`,
    `competitor_2_url`,
    `competitor_3_url`,
    `is_verified`,
    `created_at`
) VALUES (
    3,
    'https://arabic-travel.com',
    'العربي للسياحة',
    'tourism',
    'ar',
    'SA',
    'أفضل عروض السياحة في العالم العربي',
    'https://competitor1.com',
    'https://competitor2.com',
    'https://competitor3.com',
    1,
    NOW()
);

-- موقع لشركة مصر للسياحة
INSERT INTO `websites` (
    `user_id`,
    `main_url`,
    `company_name`,
    `industry`,
    `target_language`,
    `target_country`,
    `meta_description`,
    `is_verified`,
    `created_at`
) VALUES (
    4,
    'https://egypt-travel.com',
    'مصر للسياحة',
    'tourism',
    'ar',
    'EG',
    'اكتشف جمال مصر مع أفضل العروض',
    1,
    NOW()
);

-- موقع لشركة European Travel
INSERT INTO `websites` (
    `user_id`,
    `main_url`,
    `company_name`,
    `industry`,
    `target_language`,
    `target_country`,
    `meta_description`,
    `is_verified`,
    `created_at`
) VALUES (
    5,
    'https://europe-travel.com',
    'European Travel Group',
    'tourism',
    'en',
    'GB',
    'Discover Europe with the best travel deals',
    1,
    NOW()
);

-- ============================================
-- 3. تحديث المعرفات المتسلسلة للمواقع
-- ============================================
ALTER TABLE `websites` AUTO_INCREMENT = 100;

-- ============================================
-- 4. إدخال إعدادات البوت
-- ============================================

-- إعدادات البوت للمدير العام (جميع المنصات)
INSERT INTO `bot_settings` (
    `user_id`,
    `website_id`,
    `platform`,
    `is_enabled`,
    `auto_pilot`,
    `requires_approval`,
    `ai_model`,
    `ai_temperature`,
    `ai_max_tokens`,
    `ai_language`,
    `greeting_message`,
    `farewell_message`,
    `fallback_message`,
    `business_hours_start`,
    `business_hours_end`,
    `timezone`,
    `auto_response_timeout`,
    `max_conversation_length`,
    `created_at`
) VALUES (
    1, -- admin@tourfecto.com
    1, -- tourfecto.com
    'all',
    1,
    1,
    0,
    'gemini-1.5-flash',
    0.70,
    2000,
    'auto',
    'مرحباً بك في Tourfecto! كيف يمكننا مساعدتك اليوم؟',
    'شكراً لتواصلك معنا. نتمنى لك يوماً سعيداً!',
    'نعتذر، لم نتمكن من فهم طلبك. يرجى المحاولة مرة أخرى أو التواصل مع فريق الدعم.',
    '09:00:00',
    '18:00:00',
    'Asia/Riyadh',
    60,
    50,
    NOW()
);

-- إعدادات البوت لشركة العربي للسياحة (WhatsApp فقط)
INSERT INTO `bot_settings` (
    `user_id`,
    `website_id`,
    `platform`,
    `is_enabled`,
    `auto_pilot`,
    `requires_approval`,
    `ai_model`,
    `ai_temperature`,
    `ai_max_tokens`,
    `ai_language`,
    `greeting_message`,
    `farewell_message`,
    `fallback_message`,
    `whatsapp_phone_number`,
    `business_hours_start`,
    `business_hours_end`,
    `timezone`,
    `auto_response_timeout`,
    `max_conversation_length`,
    `created_at`
) VALUES (
    3, -- info@arabic-travel.com
    2, -- arabic-travel.com
    'whatsapp',
    1,
    1,
    0,
    'gemini-1.5-flash',
    0.75,
    2000,
    'ar',
    'مرحباً بك في العربي للسياحة! 🕌 نقدم لك أفضل عروض السياحة في العالم العربي. كيف يمكننا مساعدتك؟',
    'شكراً لتواصلك مع العربي للسياحة. نتمنى لك رحلة سعيدة! ✈️',
    'عذراً، لم نستطع فهم طلبك. يمكنك التواصل مع فريق الدعم على الرقم 966500000003',
    '+966500000003',
    '08:00:00',
    '20:00:00',
    'Asia/Riyadh',
    45,
    30,
    NOW()
);

-- إعدادات البوت لشركة مصر للسياحة (Webchat فقط)
INSERT INTO `bot_settings` (
    `user_id`,
    `website_id`,
    `platform`,
    `is_enabled`,
    `auto_pilot`,
    `requires_approval`,
    `ai_model`,
    `ai_temperature`,
    `ai_max_tokens`,
    `ai_language`,
    `greeting_message`,
    `farewell_message`,
    `fallback_message`,
    `business_hours_start`,
    `business_hours_end`,
    `timezone`,
    `auto_response_timeout`,
    `max_conversation_length`,
    `created_at`
) VALUES (
    4, -- info@egypt-travel.com
    3, -- egypt-travel.com
    'webchat',
    1,
    0,
    1,
    'gemini-1.5-flash',
    0.65,
    1500,
    'ar',
    'أهلاً بك في مصر للسياحة! 🏛️ اكتشف جمال مصر معنا.',
    'نشكرك على زيارتنا. نتمنى لك رحلة ممتعة في مصر!',
    'نعتذر، لم نتمكن من معالجة طلبك. يرجى التواصل مع فريق الدعم.',
    '09:00:00',
    '17:00:00',
    'Africa/Cairo',
    30,
    20,
    NOW()
);

-- إعدادات البوت لشركة European Travel (جميع المنصات)
INSERT INTO `bot_settings` (
    `user_id`,
    `website_id`,
    `platform`,
    `is_enabled`,
    `auto_pilot`,
    `requires_approval`,
    `ai_model`,
    `ai_temperature`,
    `ai_max_tokens`,
    `ai_language`,
    `greeting_message`,
    `farewell_message`,
    `fallback_message`,
    `business_hours_start`,
    `business_hours_end`,
    `timezone`,
    `auto_response_timeout`,
    `max_conversation_length`,
    `created_at`
) VALUES (
    5, -- info@europe-travel.com
    4, -- europe-travel.com
    'all',
    1,
    1,
    0,
    'gemini-1.5-pro',
    0.80,
    3000,
    'en',
    'Welcome to European Travel Group! 🌍 How can we help you explore Europe?',
    'Thank you for choosing European Travel. Have a wonderful journey! 🗺️',
    'Sorry, we couldn\'t understand your request. Please contact our support team.',
    '09:00:00',
    '18:00:00',
    'Europe/London',
    90,
    100,
    NOW()
);

-- ============================================
-- 5. إعدادات البوت للمستخدمين غير المفعلين
-- ============================================

-- إعدادات بوت افتراضية (للمستخدم غير المفعل)
INSERT INTO `bot_settings` (
    `user_id`,
    `website_id`,
    `platform`,
    `is_enabled`,
    `auto_pilot`,
    `requires_approval`,
    `ai_model`,
    `ai_temperature`,
    `ai_max_tokens`,
    `ai_language`,
    `greeting_message`,
    `business_hours_start`,
    `business_hours_end`,
    `timezone`,
    `created_at`
) VALUES (
    6, -- inactive@test.com
    1, -- سيتم إضافة موقع لهذا المستخدم
    'all',
    0,
    0,
    1,
    'gemini-1.5-flash',
    0.70,
    2000,
    'ar',
    'مرحباً بك! برجاء تفعيل اشتراكك للاستفادة من الخدمات.',
    '09:00:00',
    '18:00:00',
    'UTC',
    NOW()
);

-- ============================================
-- 6. تحديث المعرفات المتسلسلة
-- ============================================
ALTER TABLE `bot_settings` AUTO_INCREMENT = 100;

-- ============================================
-- 7. إضافة كلمات محظورة وإعدادات أمان
-- ============================================

UPDATE `bot_settings` 
SET 
    `blocked_keywords` = '["سب","شتم","عنصرية","إباحية","مخدرات","حشيش","خمر","خنزير"]',
    `allowed_domains` = '["arabic-travel.com","egypt-travel.com","europe-travel.com"]',
    `webhook_secret` = 'whsec_' . MD5(RAND())
WHERE `user_id` = 3;

UPDATE `bot_settings` 
SET 
    `blocked_keywords` = '["سب","شتم","عنصرية","إباحية","مخدرات"]',
    `webhook_secret` = 'whsec_' . MD5(RAND())
WHERE `user_id` = 4;

UPDATE `bot_settings` 
SET 
    `blocked_keywords` = '["abuse","hate","racism","pornography","drugs"]',
    `allowed_domains` = '["europe-travel.com","europe-travel.co.uk"]',
    `webhook_secret` = 'whsec_' . MD5(RAND())
WHERE `user_id` = 5;

-- ============================================
-- 8. تحديث webhook URLs
-- ============================================

UPDATE `bot_settings` 
SET 
    `whatsapp_webhook_url` = 'https://api.tourfecto.com/webhook/chat/whatsapp',
    `whatsapp_api_key` = 'wa_' . MD5(RAND())
WHERE `whatsapp_phone_number` IS NOT NULL;

-- ============================================
-- 9. ملاحظات
-- ============================================
-- إعدادات البوت تشمل:
-- - is_enabled: تفعيل/إلغاء البوت
-- - auto_pilot: تفعيل الردود التلقائية
-- - requires_approval: طلب موافقة قبل الإرسال
-- - ai_model: نموذج الذكاء الاصطناعي المستخدم
-- - ai_temperature: درجة الإبداع (0-1)
-- - business_hours_start/end: ساعات العمل
-- - blocked_keywords: الكلمات المحظورة
-- - allowed_domains: النطاقات المسموحة
-- ============================================