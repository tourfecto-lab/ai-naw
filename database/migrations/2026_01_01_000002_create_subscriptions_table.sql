-- ============================================
-- Tourfecto - Migration: Create Subscriptions Table
-- إنشاء جدول الاشتراكات
-- @version 1.0.0
-- @author Tourfecto Team
-- @copyright 2026 Tourfecto
-- ============================================

-- ============================================
-- 1. إنشاء جدول الاشتراكات
-- ============================================
CREATE TABLE IF NOT EXISTS `subscriptions` (
    `id` INT(11) NOT NULL AUTO_INCREMENT COMMENT 'المعرف الفريد للاشتراك',
    `user_id` INT(11) NOT NULL COMMENT 'معرف المستخدم',
    `plan_name` ENUM('starter', 'professional', 'enterprise') NOT NULL DEFAULT 'starter' COMMENT 'اسم الباقة',
    `plan_type` ENUM('monthly', 'yearly') NOT NULL DEFAULT 'monthly' COMMENT 'نوع الاشتراك',
    `status` ENUM('active', 'expired', 'cancelled', 'pending') NOT NULL DEFAULT 'pending' COMMENT 'حالة الاشتراك',
    `price` DECIMAL(10, 2) NOT NULL DEFAULT 0.00 COMMENT 'السعر',
    `currency` VARCHAR(3) DEFAULT 'USD' COMMENT 'العملة',
    `ai_credits` INT(11) NOT NULL DEFAULT 100 COMMENT 'رصيد الذكاء الاصطناعي',
    `ai_credits_used` INT(11) NOT NULL DEFAULT 0 COMMENT 'الرصيد المستخدم',
    `chat_credits` INT(11) NOT NULL DEFAULT 500 COMMENT 'رصيد الشات',
    `chat_credits_used` INT(11) NOT NULL DEFAULT 0 COMMENT 'رصيد الشات المستخدم',
    `review_credits` INT(11) NOT NULL DEFAULT 50 COMMENT 'رصيد المراجعات',
    `review_credits_used` INT(11) NOT NULL DEFAULT 0 COMMENT 'رصيد المراجعات المستخدم',
    `competitor_analysis_limit` INT(11) NOT NULL DEFAULT 10 COMMENT 'حد تحليل المنافسين',
    `competitor_analysis_used` INT(11) NOT NULL DEFAULT 0 COMMENT 'تحليل المنافسين المستخدم',
    `auto_pilot` TINYINT(1) DEFAULT 0 COMMENT 'تفعيل الطيار الآلي',
    `start_date` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'تاريخ البدء',
    `expiry_date` TIMESTAMP NULL DEFAULT NULL COMMENT 'تاريخ الانتهاء',
    `cancelled_at` TIMESTAMP NULL DEFAULT NULL COMMENT 'تاريخ الإلغاء',
    `cancellation_reason` TEXT DEFAULT NULL COMMENT 'سبب الإلغاء',
    `last_billed_at` TIMESTAMP NULL DEFAULT NULL COMMENT 'تاريخ آخر فاتورة',
    `next_billing_at` TIMESTAMP NULL DEFAULT NULL COMMENT 'تاريخ الفاتورة التالية',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'تاريخ الإنشاء',
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'تاريخ التحديث',
    PRIMARY KEY (`id`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    INDEX `idx_user_id` (`user_id`),
    INDEX `idx_status` (`status`),
    INDEX `idx_expiry` (`expiry_date`),
    INDEX `idx_plan` (`plan_name`),
    INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول الاشتراكات';

-- ============================================
-- 2. إضافة عمود invoice_id
-- ============================================
ALTER TABLE `subscriptions` 
ADD COLUMN `invoice_id` INT(11) DEFAULT NULL COMMENT 'معرف الفاتورة الحالية' 
AFTER `next_billing_at`;

-- ============================================
-- 3. إضافة عمود payment_method
-- ============================================
ALTER TABLE `subscriptions` 
ADD COLUMN `payment_method` VARCHAR(50) DEFAULT NULL COMMENT 'طريقة الدفع' 
AFTER `invoice_id`;

-- ============================================
-- 4. إضافة عمود payment_gateway
-- ============================================
ALTER TABLE `subscriptions` 
ADD COLUMN `payment_gateway` VARCHAR(50) DEFAULT NULL COMMENT 'بوابة الدفع' 
AFTER `payment_method`;

-- ============================================
-- 5. إضافة عمود subscription_id_external
-- ============================================
ALTER TABLE `subscriptions` 
ADD COLUMN `subscription_id_external` VARCHAR(255) DEFAULT NULL COMMENT 'معرف الاشتراك الخارجي' 
AFTER `payment_gateway`;