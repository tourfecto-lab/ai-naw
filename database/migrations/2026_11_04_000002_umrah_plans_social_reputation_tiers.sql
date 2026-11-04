-- ============================================================
-- Tourfecto - ربط باقات العمرة بالسوشيال والسمعة (تكملة 2026_11_04_000001)
-- @date 2026-11-04
--
-- باقة المنصة بتديك السوشيال من social_tier في features_json (SocialPackages::tierFromPlan)،
-- والسمعة/التقييمات من reputation_tier (ReputationLimits::tierFor). من غيرهم الباقة بتاخد
-- افتراضي السوشيال وتتعامل كـ «مجانية» في السمعة.
--
--   انطلاقة العمرة: social basic  (3 حسابات / 90 منشور / 100 كابشن)   + reputation starter
--   نمو العمرة:     social pro    (8 حسابات / 250 منشور / 400 كابشن)  + reputation professional
--   ريادة العمرة:   social agency (25 حساب / منشورات بلا حد / 1500 كابشن) + reputation enterprise
--
-- النشر وردود السوشيال وحملات Meta بتفضل متوقفة عن البيع لحد ما مراجعة Meta تخلص.
-- آمن للتشغيل أكتر من مرة (JSON_SET بيكتب نفس القيمة).
-- ============================================================

UPDATE `subscription_plans` SET `features_json` = JSON_SET(COALESCE(`features_json`, JSON_OBJECT()), '$.social_tier', 'basic', '$.reputation_tier', 'starter')
    WHERE `plan_code` IN ('umrah-start_monthly', 'umrah-start_yearly');
UPDATE `subscription_plans` SET `features_json` = JSON_SET(COALESCE(`features_json`, JSON_OBJECT()), '$.social_tier', 'pro', '$.reputation_tier', 'professional')
    WHERE `plan_code` IN ('umrah-growth_monthly', 'umrah-growth_yearly');
UPDATE `subscription_plans` SET `features_json` = JSON_SET(COALESCE(`features_json`, JSON_OBJECT()), '$.social_tier', 'agency', '$.reputation_tier', 'enterprise')
    WHERE `plan_code` IN ('umrah-pro_monthly', 'umrah-pro_yearly');

SELECT `plan_code`, JSON_UNQUOTE(JSON_EXTRACT(`features_json`, '$.social_tier')) AS social_tier, JSON_UNQUOTE(JSON_EXTRACT(`features_json`, '$.reputation_tier')) AS reputation_tier
    FROM `subscription_plans` WHERE `plan_code` LIKE 'umrah-%' ORDER BY `id`;
