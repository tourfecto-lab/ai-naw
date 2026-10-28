-- ============================================================
-- Tourfecto - Migration: نظام المحفظة (رصيد مسبق الدفع)
-- العميل يودع مبلغ (تحويل بنكي/PayPal يدوي، تأكيد عن طريق واتساب)،
-- الأدمن يوافق، والرصيد يتخصم تلقائيًا لما العميل يشترك في باقة.
-- @version 1.0.0  @date 2026-07-22
-- ============================================================

CREATE TABLE IF NOT EXISTS `wallet_transactions` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `user_id` INT(11) NOT NULL,
    `type` ENUM('deposit', 'subscription_charge', 'refund', 'admin_adjustment') NOT NULL DEFAULT 'deposit',
    `amount` DECIMAL(10,2) NOT NULL COMMENT 'موجب للإيداع/الاسترجاع، سالب للخصم',
    `currency` VARCHAR(10) NOT NULL DEFAULT 'USD',
    `status` ENUM('pending', 'completed', 'rejected') NOT NULL DEFAULT 'pending',
    `payment_method` VARCHAR(30) DEFAULT NULL COMMENT 'iban, paypal',
    `reference_note` VARCHAR(255) DEFAULT NULL COMMENT 'ملاحظة العميل وقت طلب الإيداع',
    `admin_note` VARCHAR(255) DEFAULT NULL COMMENT 'ملاحظة الأدمن وقت الموافقة/الرفض',
    `related_subscription_plan` VARCHAR(50) DEFAULT NULL COMMENT 'لو النوع subscription_charge - اسم الباقة اللي اتخصمت عشانها',
    `approved_by` INT(11) DEFAULT NULL,
    `approved_at` TIMESTAMP NULL DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_user_id` (`user_id`),
    KEY `idx_status` (`status`),
    KEY `idx_user_status` (`user_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='حركات المحفظة - إيداعات وخصومات';

-- إعدادات بيانات الدفع (IBAN/PayPal) - قابلة للتعديل من لوحة الأدمن
CREATE TABLE IF NOT EXISTS `wallet_payment_settings` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `setting_key` VARCHAR(50) NOT NULL,
    `setting_value` TEXT DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_setting_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='بيانات دفع المحفظة (IBAN/PayPal) - عدّلها من لوحة الأدمن';

INSERT INTO `wallet_payment_settings` (`setting_key`, `setting_value`) VALUES
    ('iban', 'حط رقم الـ IBAN بتاعك هنا من لوحة الأدمن'),
    ('iban_bank_name', 'اسم البنك'),
    ('iban_account_name', 'اسم صاحب الحساب'),
    ('paypal_email', 'حط إيميل الـ PayPal بتاعك هنا من لوحة الأدمن'),
    ('whatsapp_number', '201000000000')
ON DUPLICATE KEY UPDATE `setting_key` = `setting_key`;
