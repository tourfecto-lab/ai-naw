-- ============================================================================
-- Tourfecto - توسيع قيم ENUM (additive + idempotent، مفيش حذف قيم ولا مسّ بيانات)
-- المشكلة: عواميد في قاعدة الإنتاج قيمها المسموحة مختلفة عن اللي الكود بيكتبه،
-- فكان MySQL بيرفض الحفظ بـ "Data truncated for column ...".
-- المثال الفعلي: ai_reports.report_type (الكود بيكتب full والإنتاج مسموح فيه seo_aeo_geo_full...).
-- الحل: القيم الحالية بترتيبها + القيم اللي الكود محتاجها بتتضاف في الآخر.
-- ============================================================================

ALTER TABLE `ad_alert_rules` MODIFY COLUMN `rule_type` ENUM('budget_exhausted','cpc_spike','ctr_drop','landing_page_down','budget_pacing','creative_underperforming','creative_stale','variant_wasted_spend','ab_test_inconclusive') NOT NULL;
ALTER TABLE `wallet_transactions` MODIFY COLUMN `type` ENUM('deposit','subscription_charge','refund','admin_adjustment','card_redemption','subscription_credit') NOT NULL DEFAULT 'deposit';
ALTER TABLE `invoices` MODIFY COLUMN `status` ENUM('pending','paid','failed','cancelled','draft','issued','partially_paid','overdue','refunded') NULL DEFAULT 'pending';
ALTER TABLE `email_subscribers` MODIFY COLUMN `status` ENUM('subscribed','unsubscribed','bounced','pending_optin') NOT NULL DEFAULT 'subscribed' COMMENT 'حالة الاشتراك';
ALTER TABLE `reviews` MODIFY COLUMN `source_platform` ENUM('tripadvisor','google_business','booking','trustpilot','custom','expedia','other') NOT NULL;
ALTER TABLE `agency_commissions` MODIFY COLUMN `status` ENUM('pending','paid','voided') NOT NULL DEFAULT 'pending';
ALTER TABLE `ai_reports` MODIFY COLUMN `report_type` ENUM('seo_aeo_geo_full','seo_only','aeo_only','geo_only','seo','aeo','geo','full') NULL DEFAULT 'seo_aeo_geo_full';
ALTER TABLE `ai_articles` MODIFY COLUMN `status` ENUM('generating','completed','failed','scheduled','schedule_failed','published','publish_failed') NOT NULL DEFAULT 'generating';
ALTER TABLE `platform_connections` MODIFY COLUMN `status` ENUM('connected','disconnected','error','token_expired') NOT NULL DEFAULT 'connected';
ALTER TABLE `ci_insights` MODIFY COLUMN `status` ENUM('new','reviewed','dismissed','actioned') NOT NULL DEFAULT 'new';

-- تسجيل استخدام Claude
ALTER TABLE `api_usage_logs` MODIFY COLUMN `api_type` ENUM('gemini','openai','deepseek','kimi','whatsapp','tripadvisor','google','stripe','paypal','other','anthropic') NOT NULL;
