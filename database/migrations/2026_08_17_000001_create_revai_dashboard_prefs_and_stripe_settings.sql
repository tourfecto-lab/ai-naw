-- ============================================
-- Tourfecto - AI Revenue Intelligence v1.6.0
-- Migration: Dashboard Personalization + Stripe Webhook Settings
-- Created: 2026-08-17
--
-- ملاحظات:
--   1. إضافي بالكامل (CREATE TABLE IF NOT EXISTS) - لا يعدّل أي جدول موجود.
--   2. `revai_dashboard_prefs`: تخصيص الداشبورد لكل مستخدم (Tenant Isolation) -
--      أي مقياس يظهر/يختفي/بأي ترتيب. التخطيط مخزّن JSON، والخدمة تنقّحه
--      دائمًا ضد قائمة المقياس المعروفة (مفيش مقياس مخترع).
--   3. `revai_stripe_settings`: إعدادات اتصال Stripe لكل مستخدم. سر الـ
--      webhook مخزّن **مشفّرًا** (Encryption AES-256-CBC) - لا نص صريح أبدًا.
--      الإعدادات مربوطة بـ user_id (كل مستخدم يربط حساب Stripe خاصته).
--   4. شغّل هذا الملف مرة واحدة بعد نسخة احتياطية من قاعدة البيانات.
-- ============================================

SET FOREIGN_KEY_CHECKS = 0;

-- ============================================
-- 0) أعمدة ربط Stripe على biz_subscriptions (إضافة فقط)
--    لتمكين الـ upsert الآمن (idempotent) من أحداث Stripe:
--    stripe_subscription_id فريد للربط، customer_email اختياري للعرض.
-- ============================================
ALTER TABLE `biz_subscriptions`
    ADD COLUMN `stripe_subscription_id` VARCHAR(100) DEFAULT NULL COMMENT 'معرّف اشتراك Stripe (ربط idempotent)' AFTER `id`,
    ADD COLUMN `customer_email` VARCHAR(255) DEFAULT NULL COMMENT 'بريد عميل Stripe (اختياري للعرض)' AFTER `customer_name`,
    ADD UNIQUE KEY `uq_biz_sub_stripe_id` (`stripe_subscription_id`(50));

-- ============================================
-- 1) تخصيص الداشبورد (Dashboard Personalization)
--    layout JSON مثال:
--    {"widgets":[{"key":"current_revenue","visible":true,"order":0},
--                {"key":"growth_percent","visible":false,"order":1}]}
--    خدمة RevenueDashboardService هي المسؤولة عن النقاء: أي مفتاح خارج
--    القائمة المعروفة يُتجاهل ولا يُحفظ أبدًا.
-- ============================================
CREATE TABLE IF NOT EXISTS `revai_dashboard_prefs` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `user_id` INT(11) NOT NULL COMMENT 'صاحب الحساب (Tenant Isolation)',
    `layout` JSON NOT NULL COMMENT 'تخصيص أجزاء الداشبورد (widgets, order, visibility)',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_revai_prefs_user` (`user_id`),
    CONSTRAINT `fk_revai_prefs_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='تخصيص داشبورد ذكاء الإيرادات لكل مستخدم';

-- ============================================
-- 2) إعدادات اتصال Stripe لكل مستخدم
--    webhook_secret مخزّن مشفرًا عبر Encryption::encrypt() - لا يُحفظ
--    أبدًا كنص صريح في قاعدة البيانات. هو المفتاح اللازم للتحقق من توقيع
--    Stripe-Signature على أحداث الـ webhook الحقيقية.
-- ============================================
CREATE TABLE IF NOT EXISTS `revai_stripe_settings` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `user_id` INT(11) NOT NULL COMMENT 'صاحب الحساب (Tenant Isolation)',
    `webhook_secret_enc` VARCHAR(500) DEFAULT NULL COMMENT 'سر webhook مشفر (Encryption::encrypt) - لا نص صريح',
    `connected_account_id` VARCHAR(100) DEFAULT NULL COMMENT 'Stripe Account ID (اختياري، للعرض فقط)',
    `mode` ENUM('test', 'live') NOT NULL DEFAULT 'test' COMMENT 'وضع الاتصال - test/live',
    `is_enabled` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'هل الـ webhook مفعّل لهذا المستخدم',
    `last_event_at` DATETIME DEFAULT NULL COMMENT 'آخر حدث webhook مستلم ومعالج',
    `last_event_type` VARCHAR(100) DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_revai_stripe_user` (`user_id`),
    CONSTRAINT `fk_revai_stripe_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='إعدادات اتصال Stripe للمستخدم (السر مشفر، Tenant Isolation)';

-- ============================================
-- 3) سجل أحداث Stripe المستلمة (Idempotency + Audit)
--    `stripe_event_id` فريد لكل مستخدم: أي حدث يُرسله Stripe أكثر من مرة
--    (Stripe يعيد المحاولة تلقائيًا عند فشل) لا يُدخل صفوفًا مكررة أبدًا.
-- ============================================
CREATE TABLE IF NOT EXISTS `revai_stripe_events` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `user_id` INT(11) NOT NULL COMMENT 'صاحب الحساب (Tenant Isolation)',
    `stripe_event_id` VARCHAR(100) NOT NULL,
    `event_type` VARCHAR(100) NOT NULL DEFAULT '',
    `status` VARCHAR(20) NOT NULL DEFAULT 'processed' COMMENT 'processed/ignored_type/duplicate',
    `received_at` DATETIME NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_revai_stripe_evt_user_event` (`user_id`, `stripe_event_id`),
    INDEX `idx_revai_stripe_evt_user_date` (`user_id`, `received_at`),
    CONSTRAINT `fk_revai_stripe_evt_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='سجل أحداث Stripe المستلمة (منع التكرار + تتبع)';

SET FOREIGN_KEY_CHECKS = 1;
