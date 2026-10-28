-- ============================================================
-- Tourfecto - Migration: ربط معاملات الدفع بالحجوزات
--
-- إضافة عمود booking_id لجدول payment_transactions عشان نربط كل
-- محاولة دفع (Stripe Checkout) بالحجز اللي بتخصه. العمود اختياري
-- (NULL) عشان ما نكسرش المعاملات القديمة اللي مش تابعة للحجز.
-- الرابط هو booking_reference (المخزن في reference) + booking_id.
-- @version 1.0.0  @date 2026-08-21
-- ============================================================

ALTER TABLE `payment_transactions`
    ADD COLUMN `booking_id` INT(11) NULL DEFAULT NULL COMMENT 'ربط الدفع بالحجز (Booking Engine)' AFTER `reference`,
    ADD KEY `idx_payment_tx_booking_id` (`booking_id`);
