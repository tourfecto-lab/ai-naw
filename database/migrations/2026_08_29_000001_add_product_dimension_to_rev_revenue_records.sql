-- ============================================================
-- Tourfecto - Migration: Product dimension for rev_revenue_records
-- @version 1.0.0  @date 2026-08-29
--
-- G2 (التحليل التنافسي Revenue Intelligence): "الإيراد حسب
-- المنتج/الخدمة". يضيف بُعدًا اختياريًا للمنتج (اسم + تصنيف) على
-- سجل الإيراد الفعلي، فيتحول `getRevenueByProduct()` من "Not enough
-- data" صريحة إلى تجميع حقيقي حسب المنتج/التصنيف مع fallback آمن
-- للمصدر لما مفيش بيانات منتج. إضافة فقط - لا تغيّر أي سلوك قائم.
-- ============================================================

ALTER TABLE `rev_revenue_records`
    ADD COLUMN `product_name` VARCHAR(255) NULL COMMENT 'اسم المنتج/الخدمة (اختياري - G2)' AFTER `source`,
    ADD COLUMN `category` VARCHAR(100) NULL COMMENT 'تصنيف المنتج: rooms/tours/transfers/packages/other (اختياري - G2)' AFTER `product_name`;
