-- ============================================================
-- Tourfecto - Migration: idempotency_key لجدول wallet_transactions
--
-- الهدف: منع تكرار خصم/إيداع نفس العملية مرتين بسبب دبل-كليك، إعادة
-- محاولة الشبكة، أو استدعاء API مكرر - خصوصًا عند تغيير الباقة
-- (upgrade/downgrade) اللي بتخصم فرق السعر من المحفظة.
--
-- إضافية بالكامل (ADD COLUMN + ADD UNIQUE INDEX) - لا تحذف ولا تعدّل
-- أي عمود موجود، ولا تفقد أي بيانات حالية. NULL مسموح عشان الحركات
-- القديمة (قبل هذا الإصدار) تفضل شغالة من غير تعديل.
-- @version 1.0.0  @date 2026-08-09
-- ============================================================

ALTER TABLE `wallet_transactions`
    ADD COLUMN `idempotency_key` VARCHAR(80) NULL DEFAULT NULL
        COMMENT 'مفتاح فريد للعملية - بيمنع تكرار الخصم لو اتبعت نفس الطلب مرتين'
        AFTER `related_subscription_plan`;

ALTER TABLE `wallet_transactions`
    ADD UNIQUE KEY `uq_wallet_tx_idempotency_key` (`idempotency_key`);
