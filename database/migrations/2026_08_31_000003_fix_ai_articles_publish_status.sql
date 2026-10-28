-- ============================================================
-- Tourfecto - Migration: إصلاح انحراف enum حالة النشر في ai_articles
--
-- المشكلة: ميجريشن 2026_08_07_000041 (إضافة الجدولة) أعاد تعريف
-- status بـ ENUM('generating','completed','failed','scheduled',
-- 'schedule_failed') و"أسقط" قيمة 'published' اللي كانت مضافة في
-- 2026_07_18_000018. لكن الكود (AIController::publishArticle +
-- PublishScheduledArticleJob) بيضبط status='published' عند النشر
-- الناجح، فكان التخزين يفشل (STRICT mode) أو يُسجَّل صامتًا.
--
-- هذا الملف يعيد الحالة بلا "نقاط ملتصقة": يضيف 'published'
-- و'publish_failed' (حالة فشل النشر الفعلي بمعزل عن schedule_failed
-- الخاص بفشل الجدولة) مع الاحتفاظ بكل القيم القديمة. الملف قابل
-- لإعادة التشغيل (نفس نتيجة MODIFY في كل مرة) - آمن للتطبيق المتكرر.
-- @version 1.0.0  @date 2026-08-31
-- ============================================================

ALTER TABLE `ai_articles`
    MODIFY COLUMN `status` ENUM('generating','completed','failed','scheduled','schedule_failed','published','publish_failed') NOT NULL DEFAULT 'generating';
