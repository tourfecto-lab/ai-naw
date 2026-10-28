-- ============================================================
-- Tourfecto - Migration: سجل معاملات الدفع الموحّد (payment_transactions)
--
-- جدول جديد بالكامل - سجل موحّد لكل محاولة دفع بغض النظر عن الطريقة
-- (محفظة دلوقتي، أي بوابة حقيقية بكرة) - مطابق لمتطلبات القسم 3 من
-- الـ Billing Spec (Internal ID, Gateway, Status lifecycle, Idempotency,
-- Metadata). مش بديل لـ wallet_transactions - ده سجل إضافي أوسع (يشمل
-- أي طريقة دفع مستقبلية مش المحفظة بس)، wallet_transactions فاضل زي
-- ما هو تمامًا (العميل القديم لسه شغال 100%).
--
-- DECIMAL مش FLOAT للمبالغ (متطلب صريح في القسم 23).
-- @version 1.0.0  @date 2026-08-12
-- ============================================================

CREATE TABLE IF NOT EXISTS `payment_transactions` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `internal_transaction_id` VARCHAR(64) NOT NULL COMMENT 'UUID داخلي - يتولّد قبل أي محاولة دفع فعلية',
    `user_id` INT(11) NOT NULL,
    `amount` DECIMAL(12,2) NOT NULL,
    `currency` VARCHAR(10) NOT NULL DEFAULT 'USD',
    `payment_method` VARCHAR(50) NOT NULL COMMENT 'wallet / card / bank_transfer ...',
    `gateway` VARCHAR(50) NOT NULL COMMENT 'مفتاح الـ Gateway (wallet حاليًا - مطابق لـ PaymentGatewayInterface::key())',
    `gateway_transaction_id` VARCHAR(191) NULL DEFAULT NULL COMMENT 'رقم العملية عند البوابة الحقيقية (NULL للمحفظة)',
    `status` ENUM('pending','processing','succeeded','failed','cancelled','refunded','partially_refunded') NOT NULL DEFAULT 'pending',
    `reference` VARCHAR(191) NULL DEFAULT NULL COMMENT 'مرجع منطقي - رقم فاتورة، معرّف اشتراك...',
    `related_wallet_transaction_id` INT(11) NULL DEFAULT NULL COMMENT 'ربط بصف wallet_transactions المقابل لو الطريقة محفظة',
    `metadata` JSON NULL DEFAULT NULL,
    `idempotency_key` VARCHAR(80) NULL DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_payment_tx_internal_id` (`internal_transaction_id`),
    UNIQUE KEY `uq_payment_tx_idempotency_key` (`idempotency_key`),
    KEY `idx_payment_tx_user_id` (`user_id`),
    KEY `idx_payment_tx_status` (`status`),
    KEY `idx_payment_tx_gateway_tx_id` (`gateway_transaction_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='سجل موحّد لكل محاولات الدفع - أي طريقة دفع حالية أو مستقبلية';
