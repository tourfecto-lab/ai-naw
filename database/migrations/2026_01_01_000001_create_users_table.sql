-- ============================================
-- Tourfecto - Migration: Create Users Table
-- إنشاء جدول المستخدمين
-- @version 1.0.0
-- @author Tourfecto Team
-- @copyright 2026 Tourfecto
-- ============================================

-- ============================================
-- 1. إنشاء جدول المستخدمين
-- ============================================
CREATE TABLE IF NOT EXISTS `users` (
    `id` INT(11) NOT NULL AUTO_INCREMENT COMMENT 'المعرف الفريد للمستخدم',
    `company_name` VARCHAR(255) NOT NULL COMMENT 'اسم الشركة',
    `email` VARCHAR(255) NOT NULL UNIQUE COMMENT 'البريد الإلكتروني',
    `password_hash` VARCHAR(255) NOT NULL COMMENT 'كلمة المرور (مشفرة) بعد password_hash()',
    `phone` VARCHAR(50) DEFAULT NULL COMMENT 'رقم الهاتف',
    `country` VARCHAR(100) DEFAULT NULL COMMENT 'الدولة',
    `language` VARCHAR(10) DEFAULT 'ar' COMMENT 'اللغة المفضلة',
    `timezone` VARCHAR(50) DEFAULT 'UTC' COMMENT 'المنطقة الزمنية',
    `role` ENUM('super_admin', 'admin', 'manager', 'agent', 'user') DEFAULT 'user' COMMENT 'دور المستخدم',
    `is_active` TINYINT(1) DEFAULT 1 COMMENT 'حالة النشاط',
    `email_verified` TINYINT(1) DEFAULT 0 COMMENT 'حالة التحقق من البريد',
    `api_token` VARCHAR(255) DEFAULT NULL COMMENT 'توكن API',
    `token_expiry` TIMESTAMP NULL DEFAULT NULL COMMENT 'تاريخ انتهاء التوكن',
    `remember_token` VARCHAR(255) DEFAULT NULL COMMENT 'توكن التذكر',
    `last_activity` TIMESTAMP NULL DEFAULT NULL COMMENT 'آخر نشاط',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'تاريخ الإنشاء',
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'تاريخ التحديث',
    `deleted_at` TIMESTAMP NULL DEFAULT NULL COMMENT 'تاريخ الحذف (Soft Delete)',
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_email` (`email`),
    INDEX `idx_company` (`company_name`),
    INDEX `idx_api_token` (`api_token`),
    INDEX `idx_role` (`role`),
    INDEX `idx_is_active` (`is_active`),
    INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول المستخدمين';

-- ============================================
-- 2. إضافة عمود last_login
-- ============================================
ALTER TABLE `users` 
ADD COLUMN `last_login` TIMESTAMP NULL DEFAULT NULL COMMENT 'آخر تسجيل دخول' 
AFTER `last_activity`;

-- ============================================
-- 3. إضافة عمود login_attempts
-- ============================================
ALTER TABLE `users` 
ADD COLUMN `login_attempts` INT(11) DEFAULT 0 COMMENT 'عدد محاولات تسجيل الدخول' 
AFTER `last_login`;

-- ============================================
-- 4. إضافة عمود blocked_until
-- ============================================
ALTER TABLE `users` 
ADD COLUMN `blocked_until` TIMESTAMP NULL DEFAULT NULL COMMENT 'ممنوع حتى التاريخ' 
AFTER `login_attempts`;

-- ============================================
-- 5. إضافة فهارس إضافية
-- ============================================
CREATE INDEX `idx_email_verified` ON `users` (`email_verified`);
CREATE INDEX `idx_deleted_at` ON `users` (`deleted_at`);