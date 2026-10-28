-- ============================================================
-- Tourfecto - Migration: نظام الاسترجاعات (refunds)
--
-- جدول جديد بالكامل - يتتبّع أي عملية استرجاع (كاملة أو جزئية) مربوطة
-- بمعاملة دفع حقيقية في payment_transactions. الحالة النهائية دايمًا
-- بتُحدَّث من نتيجة فعلية (WalletGatewayAdapter::refund() أو مستقبلًا
-- أي Gateway حقيقي) - مفيش "نجح" افتراضي قبل التحقق.
-- @version 1.0.0  @date 2026-08-12
-- ============================================================

CREATE TABLE IF NOT EXISTS `refunds` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `payment_transaction_id` INT(11) NOT NULL,
    `user_id` INT(11) NOT NULL,
    `amount` DECIMAL(12,2) NOT NULL,
    `currency` VARCHAR(10) NOT NULL DEFAULT 'USD',
    `reason` VARCHAR(255) NULL DEFAULT NULL,
    `status` ENUM('pending','processing','succeeded','failed') NOT NULL DEFAULT 'pending',
    `gateway_refund_reference` VARCHAR(191) NULL DEFAULT NULL,
    `created_by_admin_id` INT(11) NOT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_refunds_payment_tx` (`payment_transaction_id`),
    KEY `idx_refunds_user_id` (`user_id`),
    FOREIGN KEY (`payment_transaction_id`) REFERENCES `payment_transactions`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='استرجاعات كاملة/جزئية لمعاملات دفع حقيقية';
