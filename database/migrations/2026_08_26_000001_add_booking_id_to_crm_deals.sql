-- ============================================================
-- Tourfecto - Migration: ربط الصفقات بالحجوزات (Booking Engine)
--
-- يضيف عمود booking_id إلى crm_deals لربط الصفقة بالحجز الفعلي
-- المنشأ في محرك الحجز. آليّتين معتمدتين على هذا الربط:
--   1) إنشاء حجز => ربط تلقائي بأول صفقة open لنفس الحساب بنفس
--      العميل (customer_id / الإيميل / الهاتف) - لا ينشئ صفقة جديدة.
--   2) تأكيد الحجز (يدوي أو بعد الدفع) => ترقية الصفقة المربوطة
--      لـ won (صفقة رابحة).
-- @version 1.0.0  @date 2026-08-26
-- ============================================================

ALTER TABLE `crm_deals`
    ADD COLUMN `booking_id` INT(11) NULL DEFAULT NULL COMMENT 'يشير إلى bookings.id - ربط الصفقة بالحجز الفعلي' AFTER `contact_id`,
    ADD KEY `idx_crm_deals_booking_id` (`booking_id`),
    ADD CONSTRAINT `fk_crm_deals_booking` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`) ON DELETE SET NULL;
