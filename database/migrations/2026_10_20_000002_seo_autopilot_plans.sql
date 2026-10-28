-- ============================================================
-- Tourfecto - باقات خدمة SEO / AEO / GEO (الطيار الآلي)
-- @date 2026-10-20
--
-- 1) 3 باقات سيو مستقلة (شهري + سنوي): سيو أساسي 79$، سيو النمو 199$، سيو الوكالات 449$
--    السنوي = 10 شهور (شهرين مجانًا). كل الحدود مكتوبة صراحةً، لأن أي حد ناقص
--    بياخد قيمة باقة "الانطلاقة" الافتراضية في BillingPlanCatalog.
--    marketing_tools = عدد دورات السيو (كل دورة بتتحسب استخدام واحد).
-- 2) حدود سيو أخف للباقات الحالية (seo_cycles / seo_max_pages) لو مش متحددة.
-- آمن للتشغيل أكتر من مرة: INSERT IGNORE ومش بيغيّر أي باقة الأدمن عدّلها.
-- ============================================================

INSERT IGNORE INTO `subscription_plans`
    (`plan_code`, `name`, `billing_cycle`, `price`, `currency`, `ai_analysis_limit`, `ai_message_limit`, `review_auto_reply_limit`, `features_json`, `is_active`, `sort_order`)
VALUES
    ('seo-essential_monthly', 'سيو أساسي', 'monthly', 79.00, 'USD', 4, 0, 0,
     JSON_OBJECT('competitor_analysis', 2, 'multiple_websites', 1, 'whatsapp_bot', false, 'auto_pilot', true, 'advanced_analytics', false,
                 'seo_cycles', 2, 'seo_max_pages', 100, 'seo_markets', 1, 'ai_visibility', 80, 'seo_data', 500, 'articles', 2, 'marketing_tools', 2,
                 'website_generations', 0, 'website_items', 0, 'media_images', 0, 'media_videos', 0, 'ai_tools', 50, 'ai_assistant_messages', 100), 1, 10),
    ('seo-essential_yearly', 'سيو أساسي', 'yearly', 790.00, 'USD', 4, 0, 0,
     JSON_OBJECT('competitor_analysis', 2, 'multiple_websites', 1, 'whatsapp_bot', false, 'auto_pilot', true, 'advanced_analytics', false,
                 'seo_cycles', 2, 'seo_max_pages', 100, 'seo_markets', 1, 'ai_visibility', 80, 'seo_data', 500, 'articles', 2, 'marketing_tools', 2,
                 'website_generations', 0, 'website_items', 0, 'media_images', 0, 'media_videos', 0, 'ai_tools', 50, 'ai_assistant_messages', 100), 1, 10),
    ('seo-growth_monthly', 'سيو النمو', 'monthly', 199.00, 'USD', 10, 0, 0,
     JSON_OBJECT('competitor_analysis', 5, 'multiple_websites', 1, 'whatsapp_bot', false, 'auto_pilot', true, 'advanced_analytics', false,
                 'seo_cycles', 4, 'seo_max_pages', 300, 'seo_markets', 3, 'ai_visibility', 300, 'seo_data', 2000, 'articles', 4, 'marketing_tools', 4,
                 'website_generations', 0, 'website_items', 0, 'media_images', 0, 'media_videos', 0, 'ai_tools', 150, 'ai_assistant_messages', 300), 1, 11),
    ('seo-growth_yearly', 'سيو النمو', 'yearly', 1990.00, 'USD', 10, 0, 0,
     JSON_OBJECT('competitor_analysis', 5, 'multiple_websites', 1, 'whatsapp_bot', false, 'auto_pilot', true, 'advanced_analytics', false,
                 'seo_cycles', 4, 'seo_max_pages', 300, 'seo_markets', 3, 'ai_visibility', 300, 'seo_data', 2000, 'articles', 4, 'marketing_tools', 4,
                 'website_generations', 0, 'website_items', 0, 'media_images', 0, 'media_videos', 0, 'ai_tools', 150, 'ai_assistant_messages', 300), 1, 11),
    ('seo-agency_monthly', 'سيو الوكالات', 'monthly', 449.00, 'USD', 30, 0, 0,
     JSON_OBJECT('competitor_analysis', 20, 'multiple_websites', 5, 'whatsapp_bot', false, 'auto_pilot', true, 'advanced_analytics', true,
                 'seo_cycles', 4, 'seo_max_pages', 500, 'seo_markets', 6, 'ai_visibility', 1200, 'seo_data', 8000, 'articles', 20, 'marketing_tools', 20,
                 'website_generations', 0, 'website_items', 0, 'media_images', 0, 'media_videos', 0, 'ai_tools', 500, 'ai_assistant_messages', 1000), 1, 12),
    ('seo-agency_yearly', 'سيو الوكالات', 'yearly', 4490.00, 'USD', 30, 0, 0,
     JSON_OBJECT('competitor_analysis', 20, 'multiple_websites', 5, 'whatsapp_bot', false, 'auto_pilot', true, 'advanced_analytics', true,
                 'seo_cycles', 4, 'seo_max_pages', 500, 'seo_markets', 6, 'ai_visibility', 1200, 'seo_data', 8000, 'articles', 20, 'marketing_tools', 20,
                 'website_generations', 0, 'website_items', 0, 'media_images', 0, 'media_videos', 0, 'ai_tools', 500, 'ai_assistant_messages', 1000), 1, 12);

UPDATE `subscription_plans` SET `websites_limit` = 1 WHERE `plan_code` IN ('seo-essential_monthly', 'seo-essential_yearly', 'seo-growth_monthly', 'seo-growth_yearly') AND (`websites_limit` IS NULL OR `websites_limit` = 0);
UPDATE `subscription_plans` SET `websites_limit` = 5 WHERE `plan_code` IN ('seo-agency_monthly', 'seo-agency_yearly') AND (`websites_limit` IS NULL OR `websites_limit` < 5);

INSERT IGNORE INTO `plan_pricing_display`
    (`plan_key`, `name`, `price_monthly`, `price_yearly`, `currency`, `currency_symbol`, `ai_analysis`, `competitor_analysis`, `chat_credits`, `review_credits`, `multiple_websites`, `whatsapp_bot`, `auto_pilot`, `advanced_analytics`, `sort_order`, `is_active`)
VALUES
    ('seo-essential', 'سيو أساسي', 79.00, 790.00, 'USD', '$', 4, 2, 0, 0, 1, 0, 1, 0, 10, 1),
    ('seo-growth', 'سيو النمو', 199.00, 1990.00, 'USD', '$', 10, 5, 0, 0, 1, 0, 1, 0, 11, 1),
    ('seo-agency', 'سيو الوكالات', 449.00, 4490.00, 'USD', '$', 30, 20, 0, 0, 5, 0, 1, 1, 12, 1);

-- الباقات الحالية: مستوى سيو أخف (الانطلاقة: فحص في الشهر، النمو: فحصين، الريادة: كل أسبوع)
UPDATE `subscription_plans` SET `features_json` = JSON_SET(COALESCE(`features_json`, JSON_OBJECT()), '$.seo_cycles', 1, '$.seo_max_pages', 100)
    WHERE `plan_code` IN ('starter_monthly', 'starter_yearly') AND JSON_EXTRACT(COALESCE(`features_json`, JSON_OBJECT()), '$.seo_cycles') IS NULL;
UPDATE `subscription_plans` SET `features_json` = JSON_SET(COALESCE(`features_json`, JSON_OBJECT()), '$.seo_cycles', 2, '$.seo_max_pages', 100)
    WHERE `plan_code` IN ('professional_monthly', 'professional_yearly') AND JSON_EXTRACT(COALESCE(`features_json`, JSON_OBJECT()), '$.seo_cycles') IS NULL;
UPDATE `subscription_plans` SET `features_json` = JSON_SET(COALESCE(`features_json`, JSON_OBJECT()), '$.seo_cycles', 4, '$.seo_max_pages', 300)
    WHERE `plan_code` IN ('enterprise_monthly', 'enterprise_yearly') AND JSON_EXTRACT(COALESCE(`features_json`, JSON_OBJECT()), '$.seo_cycles') IS NULL;
