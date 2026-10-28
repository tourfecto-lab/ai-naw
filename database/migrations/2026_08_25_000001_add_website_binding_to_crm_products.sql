-- ============================================================
-- Tourfecto - Migration: ربط عناصر Website Builder بـ crm_products
-- @version 1.0.0  @date 2026-08-25
--
-- بيخلي جولات/غرف المواقع المولّدة (المخزنة كـ JSON في content_json)
-- مرتبطة بصفوف حقيقية في crm_products عبر (website_id, tour_slug)،
-- بحيث صفحات الموقع العامة (showTourDetail/showRoomDetail) تقدر تعرض
-- نموذج حجز مباشر بيفتح في Booking Engine + دفع Stripe Checkout.
--
-- إضافي بالكامل (non-destructive): عمودان nullable جديدان + فهرس +
-- FK اختياري (ON DELETE SET NULL) - لا يعدّل أي عمود/بيانات موجودة.
-- ============================================================

ALTER TABLE `crm_products`
    ADD COLUMN `website_id` INT(11) NULL DEFAULT NULL
        COMMENT 'معرّف الموقع المولّد (generated_websites.id) المرتبط بالعنصر' AFTER `user_id`,
    ADD COLUMN `tour_slug` VARCHAR(120) NULL DEFAULT NULL
        COMMENT 'slug الرحلة/الغرفة داخل الموقع المولّد (Website Builder)' AFTER `sku`,
    ADD KEY `idx_crm_products_website_tour` (`website_id`, `tour_slug`);

ALTER TABLE `crm_products`
    ADD CONSTRAINT `fk_crm_products_website`
        FOREIGN KEY (`website_id`) REFERENCES `generated_websites` (`id`) ON DELETE SET NULL;
