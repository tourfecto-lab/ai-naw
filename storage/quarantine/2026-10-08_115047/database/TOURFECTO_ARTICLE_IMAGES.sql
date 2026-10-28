-- ============================================================================
-- Tourfecto - صور المقالات: أعمدة لتخزين الصورة البارزة وربطها بالمكتبة على WordPress.
-- additive + idempotent. من غيره المقالات بتتنشر زي الأول (من غير صورة) وزرار الصورة بيقولك تشغّل الملف.
-- ============================================================================
ALTER TABLE `ai_articles`
  ADD COLUMN IF NOT EXISTS `featured_image_path` VARCHAR(255) NULL,
  ADD COLUMN IF NOT EXISTS `featured_image_alt` VARCHAR(255) NULL,
  ADD COLUMN IF NOT EXISTS `featured_image_prompt` TEXT NULL,
  ADD COLUMN IF NOT EXISTS `wp_media_id` BIGINT UNSIGNED NULL,
  ADD COLUMN IF NOT EXISTS `image_error` VARCHAR(255) NULL;
