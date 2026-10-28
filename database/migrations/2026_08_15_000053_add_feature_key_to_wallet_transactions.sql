-- ============================================================
-- Tourfecto - Migration: feature_key لجدول wallet_transactions
--
-- تحليل تنافسي (Stripe Billing / Chargebee): المنصات العالمية بتقدّم
-- تقارير إيراد مفصّلة لكل ميزة ("Revenue per feature") - MRR من الاشتراكات
-- + إيراد "ادفع حسب الاستخدام" لكل ميزة على حدة. كان feature_key بتاع
-- خصم الاستخدام بيختفي (بيتحفظ Arabic label بس في reference_note) فمفيش
-- طريقة نظيفة لتجميع الإيراد لكل ميزة.
--
-- إضافية بالكامل (ADD COLUMN) - لا تحذف ولا تعدّل أي عمود موجود، ولا
-- تفقد أي بيانات حالية. NULL مسموح عشان الحركات القديمة (قبل هذا
-- الإصدار) تفضل شغالة من غير تعديل.
-- @version 1.0.0  @date 2026-08-15
-- ============================================================

ALTER TABLE `wallet_transactions`
    ADD COLUMN `feature_key` VARCHAR(50) NULL DEFAULT NULL
        COMMENT 'feature_key من pay_per_use_pricing (ai_analysis, chat_message...) - بيخصم عليه خصم الاستخدام الفردي'
        AFTER `related_subscription_plan`;
