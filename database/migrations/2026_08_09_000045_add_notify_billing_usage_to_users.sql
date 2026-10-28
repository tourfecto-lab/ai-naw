-- ============================================================
-- Tourfecto - Migration: تفضيل إشعارات استهلاك الباقة
--
-- نفس نمط notify_email/notify_chat/notify_reviews الموجود بالفعل
-- (2026_07_19_000019) - عمود واحد إضافي بس، نفس الافتراضي (مفعّل).
-- @version 1.0.0  @date 2026-08-09
-- ============================================================

ALTER TABLE `users`
    ADD COLUMN `notify_billing_usage` TINYINT(1) NOT NULL DEFAULT 1
        COMMENT 'تفعيل إشعارات نسب استهلاك الباقة (50/75/90/100%)'
        AFTER `notify_reviews`;
