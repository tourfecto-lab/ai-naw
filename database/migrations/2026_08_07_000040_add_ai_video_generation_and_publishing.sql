-- ============================================================
-- Tourfecto - توليد فيديو قصير بالذكاء الاصطناعي (Veo عبر Gemini API)
-- + نشر/جدولة الصور والفيديوهات المولّدة من Creative Studio مباشرة
-- على فيسبوك/انستجرام (يعيد استخدام social_posts/social_post_targets
-- الموجودين فعلاً - مفيش جداول جديدة، فقط أعمدة إضافية).
-- @version 1.0.0
-- ============================================================

-- 1) media_items: أعمدة توليد الفيديو (Veo عملية غير متزامنة تحتاج
--    تتبّع operation name + محاولات الفحص لحد ما يخلص التوليد)
ALTER TABLE `media_items`
    ADD COLUMN `aspect_ratio` VARCHAR(10) DEFAULT NULL
        COMMENT 'نسبة الأبعاد المستخدمة فعليًا في التوليد (1:1, 16:9, 9:16...)' AFTER `prompt`,
    ADD COLUMN `duration_seconds` TINYINT DEFAULT NULL
        COMMENT 'مدة الفيديو بالثواني - فيديو قصير فقط' AFTER `aspect_ratio`,
    ADD COLUMN `provider_ref` VARCHAR(500) DEFAULT NULL
        COMMENT 'اسم عملية Veo الطويلة (operation name) أثناء توليد الفيديو - فاضي بعد الاكتمال' AFTER `job_id`,
    ADD COLUMN `poll_attempts` SMALLINT NOT NULL DEFAULT 0
        COMMENT 'عدد مرات فحص حالة عملية توليد الفيديو الطويلة (Veo)' AFTER `provider_ref`;

-- 2) social_post_targets: نشر الفيديو على انستجرام محتاج مرحلة معالجة
--    (container REELS) غير متزامنة قبل النشر الفعلي - نفس فكرة تتبّع
--    الفيديو فوق، لكن هنا لكل "هدف نشر" منفصل.
ALTER TABLE `social_post_targets`
    ADD COLUMN `provider_ref` VARCHAR(255) DEFAULT NULL
        COMMENT 'معرف container الفيديو (REELS) في انستجرام أثناء المعالجة قبل النشر النهائي' AFTER `external_post_id`,
    ADD COLUMN `poll_attempts` SMALLINT NOT NULL DEFAULT 0
        COMMENT 'عدد مرات فحص حالة معالجة فيديو انستجرام' AFTER `provider_ref`;
