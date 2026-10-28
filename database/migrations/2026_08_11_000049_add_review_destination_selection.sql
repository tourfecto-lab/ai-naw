-- ============================================================
-- Tourfecto - Migration: اختيار وجهة التقييم لكل طلب (Review Destination)
-- Section 3/4: "Select Review Destination" كخطوة في تدفق الإنشاء، بدل
-- ما كل طلبات الموقع تستخدم رابط تقييم واحد ثابت بغض النظر عن المنصة.
--
-- بيسمح بتخصيص رابط تقييم منفصل لكل منصة (Google Business/TripAdvisor)،
-- والـ default_review_link الموجود أصلاً بيفضل يشتغل كـ "Other"/احتياطي.
-- الروابط لسه بتتحط يدويًا من صاحب الموقع (مفيش auto-generation لرابط
-- Google الحقيقي لأن ده محتاج Place ID مش متوفر حاليًا من التكامل
-- الموجود GoogleBusinessAPI - بدل ما نخمّن رابط ممكن يبقى غلط، سيبناه
-- إدخال يدوي زي default_review_link بالظبط).
-- @version 1.0.0  @date 2026-08-11
-- ============================================================

ALTER TABLE `review_request_settings`
    ADD COLUMN `google_review_link` VARCHAR(500) DEFAULT NULL
        COMMENT 'رابط تقييم Google Business (يدوي) - يُستخدم لو destination_platform = google_business' AFTER `default_review_link`,
    ADD COLUMN `tripadvisor_review_link` VARCHAR(500) DEFAULT NULL
        COMMENT 'رابط تقييم TripAdvisor (يدوي) - يُستخدم لو destination_platform = tripadvisor' AFTER `google_review_link`;

ALTER TABLE `review_requests`
    ADD COLUMN `destination_platform` ENUM('google_business', 'tripadvisor', 'other') NOT NULL DEFAULT 'other'
        COMMENT 'وجهة التقييم المختارة وقت إنشاء الطلب' AFTER `review_link`;
