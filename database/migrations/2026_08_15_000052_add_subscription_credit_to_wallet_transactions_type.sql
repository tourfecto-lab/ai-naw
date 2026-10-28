-- ============================================================
-- Tourfecto - Migration: إضافة type = 'subscription_credit'
-- لجدول wallet_transactions
-- @version 1.0.0  @date 2026-08-15
--
-- تحليل تنافسي (Stripe Billing / Chargebee): المنصات العالمية بتعمل
-- رصيد تلقائي (Credit) للعميل عند التخفيض من باقة لأرخص. عمود
-- wallet_transactions.type كان ENUM فيه (deposit, subscription_charge,
-- refund, admin_adjustment, card_redemption) - إضافة 'subscription_credit'
-- تسمح بتسجيل "رصيد فرق التخفيض" كحركة موجبة منفصلة واضحة في سجل
-- المحفظة، بدل استخدام 'refund' (المعنى مختلف: الاسترجاع بياخد
-- reference لمعاملة دفع أصلية، ده رصيد تسوية تغيير باقة).
--
-- غير مدمّر بالكامل: بيضيف قيمة واحدة للـ ENUM من غير ما يحذف أو
-- يعدّل أي قيمة موجودة. فعّال فعليًا فقط لو المنصة شغّلت
-- ALLOW_PRORATED_DOWNGRADE_CREDIT في WalletService (قيمتها الحالية
-- false افتراضيًا - قرار مالي لمالك المنصة).
-- ============================================================

ALTER TABLE `wallet_transactions`
    MODIFY COLUMN `type` ENUM(
        'deposit', 'subscription_charge', 'refund', 'admin_adjustment',
        'card_redemption', 'subscription_credit'
    ) NOT NULL DEFAULT 'deposit'
    COMMENT 'نوع الحركة - subscription_credit = رصيد فرق التخفيض التلقائي';
