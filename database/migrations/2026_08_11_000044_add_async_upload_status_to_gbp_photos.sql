-- ============================================================
-- Tourfecto - Migration: رفع صور GBP بشكل غير متزامن (Queue)
-- @date 2026-08-11
--
-- الملف ده كان بالغلط نسخة من 2026_08_10_000048 (review_request_templates).
-- GbpPhotoService بيعمل INSERT بـ status='uploading' و google_media_name=NULL،
-- وبعدين UPDATE لـ ready/failed + error_message - فالأعمدة دي لازمة وإلا رفع
-- الصور بيفشل. (أخطاء "عمود موجود" بيتجاهلها الـ runner، فالملف آمن يتكرر.)
-- ============================================================

ALTER TABLE `gbp_photos`
    MODIFY COLUMN `google_media_name` VARCHAR(255) NULL DEFAULT NULL COMMENT 'accounts/{a}/locations/{l}/media/{m} - NULL لحد ما الرفع يخلص';

ALTER TABLE `gbp_photos`
    ADD COLUMN `status` ENUM('uploading', 'ready', 'failed') NOT NULL DEFAULT 'ready' AFTER `is_primary`;

ALTER TABLE `gbp_photos`
    ADD COLUMN `error_message` VARCHAR(500) DEFAULT NULL AFTER `status`;
