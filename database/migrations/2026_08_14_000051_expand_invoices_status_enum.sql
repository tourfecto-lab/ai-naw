-- ============================================================
-- Tourfecto - Migration: توسيع حالات الفاتورة (Section 10)
--
-- تأكدنا من القيم الحقيقية الموجودة فعليًا (enum('pending','paid',
-- 'failed','cancelled') - من الـ dump الحقيقي المرفوع من العميل) قبل
-- أي تعديل. الـ MODIFY ده إضافي بالكامل - بيحافظ على الأربع قيم
-- الحقيقية زي ما هي بالظبط + بيضيف خمس حالات جديدة من Section 10 -
-- صفر مخاطرة فقدان بيانات لأي صف موجود.
-- @version 1.0.0  @date 2026-08-14
-- ============================================================

ALTER TABLE `invoices`
    MODIFY COLUMN `status` ENUM('pending','paid','failed','cancelled','draft','issued','partially_paid','overdue','refunded')
    DEFAULT 'pending';
