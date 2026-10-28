-- ============================================================
-- Tourfecto - Migration: تتبّع آخر تغيير لكلمة المرور
-- (جزء من Security Center - Phase 2 من تطوير /profile/settings)
-- ملاحظة: عمود `updated_at` الموجود بيتغيّر مع أي تعديل على أي حقل
-- تاني في المستخدم (الاسم، الصورة...)، فمش مصدر موثوق لـ "آخر تغيير
-- لكلمة المرور" - محتاج عمود مستقل.
-- @version 1.0.0  @date 2026-08-09
-- ============================================================

ALTER TABLE `users`
    ADD COLUMN `password_changed_at` TIMESTAMP NULL DEFAULT NULL COMMENT 'آخر تغيير فعلي لكلمة المرور (مش updated_at العام)' AFTER `password_hash`;

-- تعبئة أولية منطقية: للمستخدمين الحاليين اللي معندهمش قيمة، نستخدم
-- created_at كأفضل تقدير متاح (مفيش بيانات أدق من كده رجوعًا للماضي).
UPDATE `users` SET `password_changed_at` = `created_at` WHERE `password_changed_at` IS NULL;
