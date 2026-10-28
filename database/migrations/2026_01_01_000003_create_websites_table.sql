-- ============================================
-- Tourfecto - Migration: Create Websites Table
-- إنشاء جدول المواقع الإلكترونية
-- @version 1.0.0
-- @author Tourfecto Team
-- @copyright 2026 Tourfecto
-- ============================================

-- ============================================
-- 1. إنشاء جدول المواقع الإلكترونية
-- ============================================
CREATE TABLE IF NOT EXISTS `websites` (
    `id` INT(11) NOT NULL AUTO_INCREMENT COMMENT 'المعرف الفريد للموقع',
    `user_id` INT(11) NOT NULL COMMENT 'معرف المستخدم',
    `main_url` VARCHAR(500) NOT NULL COMMENT 'الرابط الرئيسي للموقع',
    `company_name` VARCHAR(255) DEFAULT NULL COMMENT 'اسم الشركة',
    `industry` VARCHAR(100) DEFAULT 'tourism' COMMENT 'نشاط الشركة',
    `target_language` VARCHAR(10) DEFAULT 'ar' COMMENT 'اللغة المستهدفة',
    `target_country` VARCHAR(100) DEFAULT NULL COMMENT 'الدولة المستهدفة',
    `meta_description` TEXT DEFAULT NULL COMMENT 'وصف الميتا',
    `competitor_1_url` VARCHAR(500) DEFAULT NULL COMMENT 'رابط المنافس 1',
    `competitor_2_url` VARCHAR(500) DEFAULT NULL COMMENT 'رابط المنافس 2',
    `competitor_3_url` VARCHAR(500) DEFAULT NULL COMMENT 'رابط المنافس 3',
    `last_analysis_at` TIMESTAMP NULL DEFAULT NULL COMMENT 'تاريخ آخر تحليل',
    `is_verified` TINYINT(1) DEFAULT 0 COMMENT 'حالة التحقق من الموقع',
    `platform_user_id` VARCHAR(255) DEFAULT NULL COMMENT 'معرف المستخدم في المنصة',
    `platform_username` VARCHAR(255) DEFAULT NULL COMMENT 'اسم المستخدم في المنصة',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'تاريخ الإنشاء',
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'تاريخ التحديث',
    `deleted_at` TIMESTAMP NULL DEFAULT NULL COMMENT 'تاريخ الحذف (Soft Delete)',
    PRIMARY KEY (`id`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    INDEX `idx_user_id` (`user_id`),
    INDEX `idx_main_url` (`main_url`(255)),
    INDEX `idx_company` (`company_name`),
    INDEX `idx_industry` (`industry`),
    INDEX `idx_is_verified` (`is_verified`),
    INDEX `idx_created_at` (`created_at`),
    INDEX `idx_deleted_at` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول المواقع الإلكترونية';

-- ============================================
-- 2. إضافة عمود logo_url
-- ============================================
ALTER TABLE `websites` 
ADD COLUMN `logo_url` VARCHAR(500) DEFAULT NULL COMMENT 'رابط شعار الموقع' 
AFTER `meta_description`;

-- ============================================
-- 3. إضافة عمود social_links
-- ============================================
ALTER TABLE `websites` 
ADD COLUMN `social_links` JSON DEFAULT NULL COMMENT 'روابط التواصل الاجتماعي' 
AFTER `logo_url`;

-- ============================================
-- 4. إضافة عمود analytics_id
-- ============================================
ALTER TABLE `websites` 
ADD COLUMN `analytics_id` VARCHAR(100) DEFAULT NULL COMMENT 'معرف Google Analytics' 
AFTER `social_links`;