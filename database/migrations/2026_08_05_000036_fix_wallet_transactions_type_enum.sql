-- ============================================================
-- Tourfecto - إصلاح: عمود wallet_transactions.type كان ENUM بس فيه
-- 4 قيم (deposit, subscription_charge, refund, admin_adjustment)،
-- لكن كود WalletService::redeemCard() بيحفظ 'card_redemption' وهي
-- مش موجودة في الـ ENUM، فكان بيطلع:
--   SQLSTATE[01000]: Warning: 1265 Data truncated for column 'type'
-- عند شحن أي بطاقة رصيد.
-- @date 2026-08-05
-- ============================================================

ALTER TABLE `wallet_transactions`
    MODIFY COLUMN `type` ENUM('deposit', 'subscription_charge', 'refund', 'admin_adjustment', 'card_redemption')
        NOT NULL DEFAULT 'deposit';
