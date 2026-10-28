-- ============================================================
-- Tourfecto - Migration: Competitor Intelligence - Webhooks & Digest
-- @version 1.3.0  @date 2026-08-09
--
-- إضافات غير هدّامة: قناتي تنبيه جديدتين (webhook/slack) + تفضيلات
-- Weekly Digest المُجدوَل تلقائيًا. لا يحذف أو يعدّل أي قيمة قديمة.
-- ============================================================

ALTER TABLE `ci_alerts`
    MODIFY COLUMN `channel` ENUM('dashboard','email','in_app','webhook','slack') NOT NULL DEFAULT 'dashboard';

ALTER TABLE `ci_user_preferences`
    ADD COLUMN `webhook_url` VARCHAR(1000) DEFAULT NULL COMMENT 'رابط Webhook عام يستقبل JSON عند كل تنبيه (لو المستخدم اختار قناة webhook)' AFTER `default_alert_channels`,
    ADD COLUMN `slack_webhook_url` VARCHAR(1000) DEFAULT NULL COMMENT 'رابط Slack Incoming Webhook (لو المستخدم اختار قناة slack)' AFTER `webhook_url`,
    ADD COLUMN `weekly_digest_enabled` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'إرسال ملخص أسبوعي تلقائي بالإيميل كل يوم اثنين' AFTER `slack_webhook_url`;
