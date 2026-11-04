-- ============================================================
-- Tourfecto - باقات شركات العمرة في الرياض (المستهدفين: المقيمين في الرياض، بالعربي)
-- @date 2026-11-04
--
-- 3 باقات (شهري + سنوي = 10 شهور): انطلاقة العمرة 129$، نمو العمرة 299$، ريادة العمرة 599$.
-- كل باقة بتشمل كل خدمات المنصة بحدود صريحة (سيو، ظهور AI، بوت واتساب، ستوديو، تقييمات، أدوات AI).
-- متسجّلة مقفولة للبيع (is_active = 0) و custom=true: الأدمن يراجع الأسعار والحدود من صفحة الباقات ويفعّلها.
-- مبيعتمدش على خدمات Meta/Google لحد ما المراجعات تخلص: النشر وردود السوشيال وحملات Meta بتتباع كوعد بعد الموافقة بس.
-- حزم السوشيال (SocialPackages) نظام منفصل ومش جزء من الجدول ده.
-- حدود seo_max_pages لازم تكون 100 أو 300 أو 500 (BillingPlanCatalog::seoLimit).
-- أرقام التكلفة والربح في الخطة تقديرية؛ راجع PlanEconomics بعد أول مشتركين.
-- آمن للتشغيل أكتر من مرة: INSERT IGNORE ومش بيغيّر أي باقة الأدمن عدّلها.
-- بدون ADD COLUMN IF NOT EXISTS (الإنتاج MySQL).
-- ============================================================

INSERT IGNORE INTO `subscription_plans`
    (`plan_code`, `name`, `billing_cycle`, `price`, `currency`, `ai_analysis_limit`, `ai_message_limit`, `review_auto_reply_limit`, `features_json`, `is_active`, `sort_order`)
VALUES
    ('umrah-start_monthly', 'انطلاقة العمرة', 'monthly', 129.00, 'USD', 8, 600, 40,
     JSON_OBJECT('custom', true, 'competitor_analysis', 1, 'multiple_websites', 1, 'whatsapp_bot', true, 'auto_pilot', true, 'advanced_analytics', false,
                 'seo_cycles', 2, 'seo_max_pages', 100, 'seo_markets', 1, 'ai_visibility', 40, 'seo_data', 300, 'articles', 4, 'marketing_tools', 40,
                 'website_generations', 2, 'website_items', 20, 'media_images', 15, 'media_videos', 0, 'ai_tools', 60, 'ai_assistant_messages', 600), 0, 20),
    ('umrah-start_yearly', 'انطلاقة العمرة', 'yearly', 1290.00, 'USD', 8, 600, 40,
     JSON_OBJECT('custom', true, 'competitor_analysis', 1, 'multiple_websites', 1, 'whatsapp_bot', true, 'auto_pilot', true, 'advanced_analytics', false,
                 'seo_cycles', 2, 'seo_max_pages', 100, 'seo_markets', 1, 'ai_visibility', 40, 'seo_data', 300, 'articles', 4, 'marketing_tools', 40,
                 'website_generations', 2, 'website_items', 20, 'media_images', 15, 'media_videos', 0, 'ai_tools', 60, 'ai_assistant_messages', 600), 0, 20),
    ('umrah-growth_monthly', 'نمو العمرة', 'monthly', 299.00, 'USD', 20, 2500, 150,
     JSON_OBJECT('custom', true, 'competitor_analysis', 4, 'multiple_websites', 1, 'whatsapp_bot', true, 'auto_pilot', true, 'advanced_analytics', false,
                 'seo_cycles', 4, 'seo_max_pages', 300, 'seo_markets', 2, 'ai_visibility', 150, 'seo_data', 1200, 'articles', 12, 'marketing_tools', 150,
                 'website_generations', 5, 'website_items', 80, 'media_images', 50, 'media_videos', 4, 'ai_tools', 250, 'ai_assistant_messages', 2500), 0, 21),
    ('umrah-growth_yearly', 'نمو العمرة', 'yearly', 2990.00, 'USD', 20, 2500, 150,
     JSON_OBJECT('custom', true, 'competitor_analysis', 4, 'multiple_websites', 1, 'whatsapp_bot', true, 'auto_pilot', true, 'advanced_analytics', false,
                 'seo_cycles', 4, 'seo_max_pages', 300, 'seo_markets', 2, 'ai_visibility', 150, 'seo_data', 1200, 'articles', 12, 'marketing_tools', 150,
                 'website_generations', 5, 'website_items', 80, 'media_images', 50, 'media_videos', 4, 'ai_tools', 250, 'ai_assistant_messages', 2500), 0, 21),
    ('umrah-pro_monthly', 'ريادة العمرة', 'monthly', 599.00, 'USD', 60, 8000, 500,
     JSON_OBJECT('custom', true, 'competitor_analysis', 12, 'multiple_websites', 2, 'whatsapp_bot', true, 'auto_pilot', true, 'advanced_analytics', true,
                 'seo_cycles', 4, 'seo_max_pages', 500, 'seo_markets', 4, 'ai_visibility', 500, 'seo_data', 4000, 'articles', 30, 'marketing_tools', 500,
                 'website_generations', 12, 'website_items', 300, 'media_images', 150, 'media_videos', 12, 'ai_tools', 800, 'ai_assistant_messages', 8000), 0, 22),
    ('umrah-pro_yearly', 'ريادة العمرة', 'yearly', 5990.00, 'USD', 60, 8000, 500,
     JSON_OBJECT('custom', true, 'competitor_analysis', 12, 'multiple_websites', 2, 'whatsapp_bot', true, 'auto_pilot', true, 'advanced_analytics', true,
                 'seo_cycles', 4, 'seo_max_pages', 500, 'seo_markets', 4, 'ai_visibility', 500, 'seo_data', 4000, 'articles', 30, 'marketing_tools', 500,
                 'website_generations', 12, 'website_items', 300, 'media_images', 150, 'media_videos', 12, 'ai_tools', 800, 'ai_assistant_messages', 8000), 0, 22);

UPDATE `subscription_plans` SET `websites_limit` = 1 WHERE `plan_code` IN ('umrah-start_monthly', 'umrah-start_yearly', 'umrah-growth_monthly', 'umrah-growth_yearly') AND (`websites_limit` IS NULL OR `websites_limit` = 0);
UPDATE `subscription_plans` SET `websites_limit` = 2 WHERE `plan_code` IN ('umrah-pro_monthly', 'umrah-pro_yearly') AND (`websites_limit` IS NULL OR `websites_limit` < 2);

INSERT IGNORE INTO `plan_pricing_display`
    (`plan_key`, `name`, `price_monthly`, `price_yearly`, `currency`, `currency_symbol`, `ai_analysis`, `competitor_analysis`, `chat_credits`, `review_credits`, `multiple_websites`, `whatsapp_bot`, `auto_pilot`, `advanced_analytics`, `sort_order`, `is_active`)
VALUES
    ('umrah-start', 'انطلاقة العمرة', 129.00, 1290.00, 'USD', '$', 8, 1, 600, 40, 1, 1, 1, 0, 20, 0),
    ('umrah-growth', 'نمو العمرة', 299.00, 2990.00, 'USD', '$', 20, 4, 2500, 150, 1, 1, 1, 0, 21, 0),
    ('umrah-pro', 'ريادة العمرة', 599.00, 5990.00, 'USD', '$', 60, 12, 8000, 500, 2, 1, 1, 1, 22, 0);

-- للمراجعة بعد التشغيل
SELECT `plan_code`, `price`, `is_active`, `ai_message_limit`, `review_auto_reply_limit`, `websites_limit` FROM `subscription_plans` WHERE `plan_code` LIKE 'umrah-%' ORDER BY `id`;
