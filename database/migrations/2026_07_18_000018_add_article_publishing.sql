-- ============================================================
-- Tourfecto - Migration: دعم نشر المقالات التسويقية مباشرة على
-- ووردبريس عن طريق WP REST API (Application Passwords)
-- @version 1.0.0  @date 2026-07-18
--
-- ملاحظة: بيانات اتصال ووردبريس (site_url/username/app_password) بتتخزن
-- في جدول platform_connections الموجود أصلاً (platform = 'wordpress'،
-- مفتاح على website_id) - زي نفس نمط google_business بالظبط، فمفيش
-- جدول جديد لده. التعديل هنا بس على ai_articles عشان نعرف نتبع حالة
-- النشر لكل مقال.
-- ============================================================

ALTER TABLE `ai_articles`
    MODIFY COLUMN `status` ENUM('generating','completed','failed','published') NOT NULL DEFAULT 'generating',
    ADD COLUMN `published_at` TIMESTAMP NULL DEFAULT NULL AFTER `status`,
    ADD COLUMN `published_url` VARCHAR(500) DEFAULT NULL COMMENT 'رابط المقال بعد النشر على موقع العميل' AFTER `published_at`,
    ADD COLUMN `wp_post_id` INT(11) DEFAULT NULL COMMENT 'معرف الـ post في ووردبريس (لإعادة النشر/التحديث لاحقًا)' AFTER `published_url`;

-- توثيق فقط - platform_connections.platform عمود VARCHAR مفتوح أصلاً
-- (مش ENUM) فمفيش تعديل بنية مطلوب، لكن القيم الجديدة المستخدمة هي:
--   platform = 'wordpress'    (نشر مباشر على موقع ووردبريس عن طريق WP REST API)
--   platform = 'custom_api'   (نشر على أي موقع تاني عن طريق webhook يجهّزه مبرمج العميل)
--   كلاهما مفتاح على website_id + user_id زي الباقي
