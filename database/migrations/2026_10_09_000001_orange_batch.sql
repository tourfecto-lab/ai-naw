-- =====================================================================
-- Tourfecto - 2026_10_09_000001: الدفعة البرتقالي
--   1) طلبات التقييم: ربط بالحجز + رابط متتبَّع (مين فتح الرابط) + تفعيل تلقائي من الحجوزات
-- =====================================================================
ALTER TABLE `review_requests` ADD COLUMN IF NOT EXISTS `booking_id` INT NULL DEFAULT NULL;
ALTER TABLE `review_requests` ADD COLUMN IF NOT EXISTS `track_token` VARCHAR(40) NULL DEFAULT NULL;
ALTER TABLE `review_requests` ADD COLUMN IF NOT EXISTS `clicked_at` DATETIME NULL DEFAULT NULL;
ALTER TABLE `review_requests` ADD COLUMN IF NOT EXISTS `click_count` INT NOT NULL DEFAULT 0;
ALTER TABLE `review_requests` ADD INDEX IF NOT EXISTS `idx_rr_track_token` (`track_token`);
ALTER TABLE `review_requests` ADD INDEX IF NOT EXISTS `idx_rr_booking` (`booking_id`);
ALTER TABLE `review_request_settings` ADD COLUMN IF NOT EXISTS `auto_from_bookings` TINYINT(1) NOT NULL DEFAULT 0;

--   2) منشورات Google Business: عروض وفعاليات حقيقية (عنوان/تواريخ/كود خصم) + زرار المنشور
ALTER TABLE `gbp_content` ADD COLUMN IF NOT EXISTS `post_title` VARCHAR(120) NULL DEFAULT NULL;
ALTER TABLE `gbp_content` ADD COLUMN IF NOT EXISTS `event_start` DATETIME NULL DEFAULT NULL;
ALTER TABLE `gbp_content` ADD COLUMN IF NOT EXISTS `event_end` DATETIME NULL DEFAULT NULL;
ALTER TABLE `gbp_content` ADD COLUMN IF NOT EXISTS `coupon_code` VARCHAR(60) NULL DEFAULT NULL;
ALTER TABLE `gbp_content` ADD COLUMN IF NOT EXISTS `offer_terms` VARCHAR(500) NULL DEFAULT NULL;
ALTER TABLE `gbp_content` ADD COLUMN IF NOT EXISTS `cta_type` VARCHAR(30) NULL DEFAULT NULL;
ALTER TABLE `gbp_content` ADD COLUMN IF NOT EXISTS `cta_url` VARCHAR(500) NULL DEFAULT NULL;
-- كان ناقص 'published' فالمنشور اللي اتنشر فعلاً كان بيتعلّم "فشل"
ALTER TABLE `gbp_content` MODIFY COLUMN `status` ENUM('draft','ready','failed','published') NOT NULL DEFAULT 'draft';
