-- ============================================================
-- Tourfecto - Migration: تفضيلات إشعارات موسّعة بالفئة
-- (Settings Center - Phase 4)
--
-- الأعمدة القديمة (notify_email, notify_chat, notify_reviews) بتفضل
-- زي ما هي - مفيش حذف ولا تعديل عليها (No Destructive Migration).
-- العمود الجديد ده بيضيف تفضيل لكل فئة إشعار حقيقية موجودة بالفعل في
-- الكود (Notification::notify() calls) بدل 3 checkboxes عامة بس.
-- @version 1.0.0  @date 2026-08-09
-- ============================================================

ALTER TABLE `users`
    ADD COLUMN `notification_preferences` TEXT NULL DEFAULT NULL COMMENT 'JSON: تفضيل تشغيل/إيقاف لكل فئة إشعار (reviews, content_publishing, leads, system)' AFTER `notify_reviews`;

-- تعبئة أولية من القيم القديمة، عشان محدش يفقد تفضيله الحالي بعد الترقية.
UPDATE `users`
SET `notification_preferences` = JSON_OBJECT(
    'reviews', IF(`notify_reviews` = 1, TRUE, FALSE),
    'content_publishing', TRUE,
    'leads', IF(`notify_chat` = 1, TRUE, FALSE),
    'system', TRUE
)
WHERE `notification_preferences` IS NULL;
