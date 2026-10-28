-- ============================================================
-- Tourfecto - Migration: Competitor Intelligence - Pricing & Careers
-- @version 1.5.1  @date 2026-08-15
--
-- إضافة غير هدّامة (Additive) فوق migration 2026_08_14_000048:
--   1) أعمدة سعر مهيكل (Structured Pricing) على ci_changes - عشان
--      نقدر نتبع تاريخ أسعار منافس فعليًا (ميزة Prisync/PriceIntelligence)
--      مش بس "نص اتغير". استخراج السعر بيتم من نص الصفحة وقت
--      pricing_change/offer_change/new_product (انظر PriceExtractor).
--   2) توسيع ENUM(page_type) في ci_snapshots و ci_changes بقيمة
--      'careers' جديدة - إشارة توظيف منافس (Job Postings) اللي بتتبعها
--      منصات Crayon/Kompyte كمصدر استخبارات استراتيجي.
-- كل القيم القديمة بتفضل شغالة زي ما هي - مفيش أي حذف أو تعديل هدّام.
-- ============================================================

ALTER TABLE `ci_changes`
    ADD COLUMN `price_before` DECIMAL(14,2) NULL DEFAULT NULL COMMENT 'سعر مهيكل قبل التغيير (مستخرج من نص الصفحة)' AFTER `new_value`,
    ADD COLUMN `price_after` DECIMAL(14,2) NULL DEFAULT NULL COMMENT 'سعر مهيكل بعد التغيير (مستخرج من نص الصفحة)' AFTER `price_before`,
    ADD COLUMN `currency` VARCHAR(8) NULL DEFAULT NULL COMMENT 'رمز/كود العملة المستخرج (مثال: USD / EGP / SAR / AED)' AFTER `price_after`;

ALTER TABLE `ci_snapshots`
    MODIFY COLUMN `page_type` ENUM('homepage','pricing','products','services','landing','blog','contact','offers','sitemap','careers') NOT NULL;

ALTER TABLE `ci_changes`
    MODIFY COLUMN `page_type` ENUM('homepage','pricing','products','services','landing','blog','contact','offers','sitemap','careers') NOT NULL;
