-- ============================================================
-- Tourfecto - Migration: أعمدة الضريبة على الفواتير
--
-- إضافية بالكامل (ADD COLUMN فقط - آمنة 100%، بعكس تعديل ENUM، مفيش
-- أي احتمال فقدان بيانات هنا). كل الأعمدة NULL افتراضيًا فالفواتير
-- القديمة تفضل زي ما هي تمامًا.
--
-- ملحوظة مهمة: الضريبة هنا معلوماتية بس حاليًا - العميل بيدفع سعر
-- الباقة زي ما هو من المحفظة (amount يفضل زي ما هو تمامًا، من غير أي
-- إضافة تلقائية للضريبة فوقه). لو النظام قرر يفعّل تحصيل ضريبة فعلي
-- بكرة، وقتها amount هيحتاج يتغيّر بوعي (مش تلقائيًا من هنا) عشان
-- يشمل الضريبة في المبلغ المخصوم فعليًا من المحفظة.
-- @version 1.0.0  @date 2026-08-12
-- ============================================================

ALTER TABLE `invoices`
    ADD COLUMN `subtotal` DECIMAL(10,2) NULL DEFAULT NULL COMMENT 'المبلغ قبل الضريبة' AFTER `amount`,
    ADD COLUMN `tax_country` CHAR(2) NULL DEFAULT NULL AFTER `subtotal`,
    ADD COLUMN `tax_type` VARCHAR(30) NULL DEFAULT NULL COMMENT 'VAT / GST / ... - NULL يعني Not Configured' AFTER `tax_country`,
    ADD COLUMN `tax_amount` DECIMAL(10,2) NULL DEFAULT NULL COMMENT 'معلوماتي حاليًا - غير مضاف لمبلغ amount المخصوم فعليًا' AFTER `tax_type`;
