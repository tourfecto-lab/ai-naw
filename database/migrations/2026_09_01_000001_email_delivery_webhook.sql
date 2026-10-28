-- Tourfecto - Email Delivery Webhook (بند 1: تتبع الارتدادات/الشكاوى)
-- يضيف لكل مستخدم داخل email_smtp_settings حقل تفعيل/تعطيل webhook تتبع
-- التسليم + مفتاح سري للتحقق من توقيع الطلبات الواردة من مزوّد البريد
-- (SendGrid/Mailgun/Postmark/أي متكامل يدعم header سري مخصص).
--
-- ملاحظة: نفس نمط بقية ALTERs في الموديول - لو اتعاد تشغيل الملف على
-- قاعدة محدّثة هيترمى Duplicate column ويتجاهله حلقة الميجريشن بأمان.

SET NAMES utf8mb4;

ALTER TABLE `email_smtp_settings`
    ADD COLUMN `delivery_webhook_enabled` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'تفعيل webhook تتبع التسليم (ارتداد/شكوى)' AFTER `is_active`,
    ADD COLUMN `delivery_webhook_secret` VARCHAR(64) NULL DEFAULT NULL COMMENT 'مفتاح سري للتحقق من توقيع webhook التسليم' AFTER `delivery_webhook_enabled`;
