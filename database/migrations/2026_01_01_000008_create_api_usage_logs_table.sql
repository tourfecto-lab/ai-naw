-- ============================================
-- Tourfecto - Migration: Create API Usage Logs Table
-- إنشاء جدول سجل استخدام API
-- @version 1.0.0
-- @author Tourfecto Team
-- @copyright 2026 Tourfecto
-- ============================================

-- ============================================
-- 1. إنشاء جدول سجل استخدام API
-- ============================================
CREATE TABLE IF NOT EXISTS `api_usage_logs` (
    `id` INT(11) NOT NULL AUTO_INCREMENT COMMENT 'المعرف الفريد للسجل',
    `user_id` INT(11) NOT NULL COMMENT 'معرف المستخدم',
    `api_type` ENUM('gemini', 'whatsapp', 'tripadvisor', 'google', 'stripe', 'paypal', 'other') NOT NULL COMMENT 'نوع الـ API',
    `endpoint` VARCHAR(255) DEFAULT NULL COMMENT 'نقطة النهاية',
    `request_data` JSON DEFAULT NULL COMMENT 'بيانات الطلب',
    `response_data` JSON DEFAULT NULL COMMENT 'بيانات الاستجابة',
    `status_code` INT(11) DEFAULT NULL COMMENT 'رمز الحالة',
    `tokens_used` INT(11) DEFAULT 0 COMMENT 'عدد التوكنات المستخدمة',
    `cost_in_usd` DECIMAL(10, 6) DEFAULT 0.000000 COMMENT 'التكلفة بالدولار',
    `duration_ms` INT(11) DEFAULT 0 COMMENT 'مدة التنفيذ بالمللي ثانية',
    `ip_address` VARCHAR(45) DEFAULT NULL COMMENT 'عنوان IP',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'تاريخ الإنشاء',
    PRIMARY KEY (`id`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    INDEX `idx_user_id` (`user_id`),
    INDEX `idx_api_type` (`api_type`),
    INDEX `idx_status_code` (`status_code`),
    INDEX `idx_created_at` (`created_at`),
    INDEX `idx_tokens_used` (`tokens_used`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول سجل استخدام API';

-- ============================================
-- 2. إنشاء جدول إضافي لتتبع الاستخدام اليومي
-- ============================================
CREATE TABLE IF NOT EXISTS `daily_usage_logs` (
    `id` INT(11) NOT NULL AUTO_INCREMENT COMMENT 'المعرف الفريد للسجل',
    `user_id` INT(11) NOT NULL COMMENT 'معرف المستخدم',
    `usage_type` VARCHAR(50) NOT NULL COMMENT 'نوع الاستخدام',
    `amount` INT(11) NOT NULL DEFAULT 1 COMMENT 'الكمية',
    `metadata` JSON DEFAULT NULL COMMENT 'بيانات إضافية',
    `usage_date` DATE NOT NULL COMMENT 'تاريخ الاستخدام',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'تاريخ الإنشاء',
    PRIMARY KEY (`id`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    INDEX `idx_user_id` (`user_id`),
    INDEX `idx_usage_type` (`usage_type`),
    INDEX `idx_usage_date` (`usage_date`),
    UNIQUE KEY `unique_daily_usage` (`user_id`, `usage_type`, `usage_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول سجل الاستخدام اليومي';

-- ============================================
-- 3. إنشاء جدول للفواتير
-- ============================================
CREATE TABLE IF NOT EXISTS `invoices` (
    `id` INT(11) NOT NULL AUTO_INCREMENT COMMENT 'المعرف الفريد للفاتورة',
    `user_id` INT(11) NOT NULL COMMENT 'معرف المستخدم',
    `invoice_number` VARCHAR(50) NOT NULL UNIQUE COMMENT 'رقم الفاتورة',
    `plan_name` VARCHAR(50) NOT NULL COMMENT 'اسم الباقة',
    `plan_type` ENUM('monthly', 'yearly') NOT NULL COMMENT 'نوع الباقة',
    `amount` DECIMAL(10, 2) NOT NULL COMMENT 'المبلغ',
    `currency` VARCHAR(3) DEFAULT 'USD' COMMENT 'العملة',
    `status` ENUM('pending', 'paid', 'failed', 'cancelled') DEFAULT 'pending' COMMENT 'حالة الفاتورة',
    `payment_method` VARCHAR(50) DEFAULT NULL COMMENT 'طريقة الدفع',
    `transaction_id` VARCHAR(255) DEFAULT NULL COMMENT 'معرف المعاملة',
    `items` JSON DEFAULT NULL COMMENT 'بنود الفاتورة',
    `due_date` DATE NOT NULL COMMENT 'تاريخ الاستحقاق',
    `paid_at` TIMESTAMP NULL DEFAULT NULL COMMENT 'تاريخ الدفع',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'تاريخ الإنشاء',
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'تاريخ التحديث',
    PRIMARY KEY (`id`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    INDEX `idx_user_id` (`user_id`),
    INDEX `idx_status` (`status`),
    INDEX `idx_invoice_number` (`invoice_number`),
    INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول الفواتير';

-- ============================================
-- 4. إنشاء جدول لسجلات الموافقات
-- ============================================
CREATE TABLE IF NOT EXISTS `chat_approval_logs` (
    `id` INT(11) NOT NULL AUTO_INCREMENT COMMENT 'المعرف الفريد للسجل',
    `message_id` INT(11) NOT NULL COMMENT 'معرف الرسالة',
    `user_id` INT(11) NOT NULL COMMENT 'معرف المستخدم',
    `action` ENUM('approved', 'rejected') NOT NULL COMMENT 'الإجراء',
    `reason` TEXT DEFAULT NULL COMMENT 'السبب',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'تاريخ الإنشاء',
    PRIMARY KEY (`id`),
    FOREIGN KEY (`message_id`) REFERENCES `chat_messages`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    INDEX `idx_message_id` (`message_id`),
    INDEX `idx_user_id` (`user_id`),
    INDEX `idx_action` (`action`),
    INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول سجلات الموافقات';

-- ============================================
-- 5. إنشاء جدول لسجلات الـ GDPR
-- ============================================
CREATE TABLE IF NOT EXISTS `gdpr_consents` (
    `id` INT(11) NOT NULL AUTO_INCREMENT COMMENT 'المعرف الفريد للسجل',
    `user_id` INT(11) NOT NULL COMMENT 'معرف المستخدم',
    `consent_type` VARCHAR(100) NOT NULL COMMENT 'نوع الموافقة',
    `consent_data` JSON DEFAULT NULL COMMENT 'بيانات الموافقة',
    `ip_address` VARCHAR(45) DEFAULT NULL COMMENT 'عنوان IP',
    `user_agent` TEXT DEFAULT NULL COMMENT 'متصفح المستخدم',
    `revoked_at` TIMESTAMP NULL DEFAULT NULL COMMENT 'تاريخ سحب الموافقة',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'تاريخ الإنشاء',
    PRIMARY KEY (`id`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    INDEX `idx_user_id` (`user_id`),
    INDEX `idx_consent_type` (`consent_type`),
    INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول موافقات GDPR';

-- ============================================
-- 6. إنشاء جدول للـ Rate Limiting
-- ============================================
CREATE TABLE IF NOT EXISTS `rate_limit_blocks` (
    `id` INT(11) NOT NULL AUTO_INCREMENT COMMENT 'المعرف الفريد للسجل',
    `identifier` VARCHAR(255) NOT NULL COMMENT 'المعرف (IP, API Key, إلخ)',
    `reason` TEXT DEFAULT NULL COMMENT 'سبب الحظر',
    `expires_at` TIMESTAMP NOT NULL COMMENT 'تاريخ الانتهاء',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'تاريخ الإنشاء',
    PRIMARY KEY (`id`),
    INDEX `idx_identifier` (`identifier`),
    INDEX `idx_expires_at` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول حظر معدل الطلبات';

-- ============================================
-- 7. إنشاء جدول للـ CSRF Tokens
-- ============================================
CREATE TABLE IF NOT EXISTS `csrf_tokens` (
    `id` INT(11) NOT NULL AUTO_INCREMENT COMMENT 'المعرف الفريد للسجل',
    `token` VARCHAR(255) NOT NULL COMMENT 'توكن CSRF',
    `expires_at` TIMESTAMP NOT NULL COMMENT 'تاريخ الانتهاء',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'تاريخ الإنشاء',
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_token` (`token`),
    INDEX `idx_expires_at` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول توكنات CSRF';