-- ============================================================
-- Tourfecto - تنظيم الباقات والأسعار (v3)  @date 2026-10-24
--
-- 1) أسعار أعلى شوية: الانطلاقة 79$، النمو 199$، الريادة 449$
--    سيو أساسي 99$، سيو النمو 249$، سيو الوكالات 549$ - السنوي = 10 شهور.
-- 2) حدود استخدام أقل وواقعية (الحدود القديمة كانت كبيرة جدًا).
-- 3) نفس القيم في الشهري والسنوي (لازم يتطابقوا وإلا الباقة مش هتظهر).
--
-- بيعدّل بيانات حقيقية: اعمل نسخة احتياطية من الجدولين subscription_plans
-- و plan_pricing_display قبل التشغيل. آمن لو اتشغل أكتر من مرة.
-- ملحوظة: الحدود الجديدة بتسري على المشتركين الحاليين من أول الشهر الجاي في الاستخدام.
-- ============================================================

-- الانطلاقة
UPDATE `subscription_plans` SET
    `price` = CASE `billing_cycle` WHEN 'yearly' THEN 790.00 ELSE 79.00 END,
    `ai_analysis_limit` = 5, `ai_message_limit` = 400, `review_auto_reply_limit` = 40, `websites_limit` = 1,
    `features_json` = JSON_SET(COALESCE(`features_json`, JSON_OBJECT()),
        '$.competitor_analysis', 1, '$.multiple_websites', 1, '$.ai_assistant_messages', 400,
        '$.ai_visibility', 30, '$.seo_data', 200, '$.seo_markets', 1, '$.seo_cycles', 1, '$.seo_max_pages', 50,
        '$.website_generations', 2, '$.website_items', 20, '$.articles', 4, '$.marketing_tools', 40,
        '$.media_images', 10, '$.media_videos', 0, '$.ai_tools', 60)
WHERE `plan_code` IN ('starter_monthly', 'starter_yearly');

-- النمو
UPDATE `subscription_plans` SET
    `price` = CASE `billing_cycle` WHEN 'yearly' THEN 1990.00 ELSE 199.00 END,
    `ai_analysis_limit` = 20, `ai_message_limit` = 1500, `review_auto_reply_limit` = 150, `websites_limit` = 2,
    `features_json` = JSON_SET(COALESCE(`features_json`, JSON_OBJECT()),
        '$.competitor_analysis', 4, '$.multiple_websites', 2, '$.ai_assistant_messages', 1500,
        '$.ai_visibility', 120, '$.seo_data', 1500, '$.seo_markets', 2, '$.seo_cycles', 2, '$.seo_max_pages', 100,
        '$.website_generations', 5, '$.website_items', 80, '$.articles', 12, '$.marketing_tools', 150,
        '$.media_images', 40, '$.media_videos', 2, '$.ai_tools', 250)
WHERE `plan_code` IN ('professional_monthly', 'professional_yearly');

-- الريادة
UPDATE `subscription_plans` SET
    `price` = CASE `billing_cycle` WHEN 'yearly' THEN 4490.00 ELSE 449.00 END,
    `ai_analysis_limit` = 60, `ai_message_limit` = 5000, `review_auto_reply_limit` = 500, `websites_limit` = 5,
    `features_json` = JSON_SET(COALESCE(`features_json`, JSON_OBJECT()),
        '$.competitor_analysis', 12, '$.multiple_websites', 5, '$.ai_assistant_messages', 5000,
        '$.ai_visibility', 500, '$.seo_data', 6000, '$.seo_markets', 4, '$.seo_cycles', 4, '$.seo_max_pages', 300,
        '$.website_generations', 12, '$.website_items', 300, '$.articles', 40, '$.marketing_tools', 500,
        '$.media_images', 120, '$.media_videos', 6, '$.ai_tools', 800)
WHERE `plan_code` IN ('enterprise_monthly', 'enterprise_yearly');

-- سيو أساسي
UPDATE `subscription_plans` SET
    `price` = CASE `billing_cycle` WHEN 'yearly' THEN 990.00 ELSE 99.00 END,
    `ai_analysis_limit` = 3, `websites_limit` = 1,
    `features_json` = JSON_SET(COALESCE(`features_json`, JSON_OBJECT()),
        '$.competitor_analysis', 1, '$.multiple_websites', 1, '$.ai_assistant_messages', 60,
        '$.ai_visibility', 50, '$.seo_data', 300, '$.seo_markets', 1, '$.seo_cycles', 2, '$.seo_max_pages', 80,
        '$.articles', 2, '$.marketing_tools', 2, '$.ai_tools', 30)
WHERE `plan_code` IN ('seo-essential_monthly', 'seo-essential_yearly');

-- سيو النمو
UPDATE `subscription_plans` SET
    `price` = CASE `billing_cycle` WHEN 'yearly' THEN 2490.00 ELSE 249.00 END,
    `ai_analysis_limit` = 8, `websites_limit` = 1,
    `features_json` = JSON_SET(COALESCE(`features_json`, JSON_OBJECT()),
        '$.competitor_analysis', 3, '$.multiple_websites', 1, '$.ai_assistant_messages', 200,
        '$.ai_visibility', 150, '$.seo_data', 1200, '$.seo_markets', 3, '$.seo_cycles', 4, '$.seo_max_pages', 200,
        '$.articles', 4, '$.marketing_tools', 4, '$.ai_tools', 100)
WHERE `plan_code` IN ('seo-growth_monthly', 'seo-growth_yearly');

-- سيو الوكالات
UPDATE `subscription_plans` SET
    `price` = CASE `billing_cycle` WHEN 'yearly' THEN 5490.00 ELSE 549.00 END,
    `ai_analysis_limit` = 20, `websites_limit` = 5,
    `features_json` = JSON_SET(COALESCE(`features_json`, JSON_OBJECT()),
        '$.competitor_analysis', 10, '$.multiple_websites', 5, '$.ai_assistant_messages', 600,
        '$.ai_visibility', 600, '$.seo_data', 5000, '$.seo_markets', 5, '$.seo_cycles', 4, '$.seo_max_pages', 400,
        '$.articles', 12, '$.marketing_tools', 12, '$.ai_tools', 300)
WHERE `plan_code` IN ('seo-agency_monthly', 'seo-agency_yearly');

-- جدول العرض (الأسماء والترتيب)
UPDATE `plan_pricing_display` SET `price_monthly` = 79.00,  `price_yearly` = 790.00,  `ai_analysis` = 5,  `competitor_analysis` = 1,  `chat_credits` = 400,  `review_credits` = 40,  `multiple_websites` = 1 WHERE `plan_key` = 'starter';
UPDATE `plan_pricing_display` SET `price_monthly` = 199.00, `price_yearly` = 1990.00, `ai_analysis` = 20, `competitor_analysis` = 4,  `chat_credits` = 1500, `review_credits` = 150, `multiple_websites` = 2 WHERE `plan_key` = 'professional';
UPDATE `plan_pricing_display` SET `price_monthly` = 449.00, `price_yearly` = 4490.00, `ai_analysis` = 60, `competitor_analysis` = 12, `chat_credits` = 5000, `review_credits` = 500, `multiple_websites` = 5 WHERE `plan_key` = 'enterprise';
UPDATE `plan_pricing_display` SET `price_monthly` = 99.00,  `price_yearly` = 990.00,  `ai_analysis` = 3,  `competitor_analysis` = 1,  `multiple_websites` = 1 WHERE `plan_key` = 'seo-essential';
UPDATE `plan_pricing_display` SET `price_monthly` = 249.00, `price_yearly` = 2490.00, `ai_analysis` = 8,  `competitor_analysis` = 3,  `multiple_websites` = 1 WHERE `plan_key` = 'seo-growth';
UPDATE `plan_pricing_display` SET `price_monthly` = 549.00, `price_yearly` = 5490.00, `ai_analysis` = 20, `competitor_analysis` = 10, `multiple_websites` = 5 WHERE `plan_key` = 'seo-agency';

-- للمراجعة بعد التشغيل
SELECT `plan_code`, `price`, `ai_analysis_limit`, `ai_message_limit`, `review_auto_reply_limit`, `websites_limit` FROM `subscription_plans` WHERE `is_active` = 1 ORDER BY `id`;
