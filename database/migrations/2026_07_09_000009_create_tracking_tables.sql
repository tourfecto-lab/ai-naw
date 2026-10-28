-- ============================================
-- Tourfecto - Migration: Create Tracking Tables
-- جداول تتبع الزوار وسجل تسجيل الدخول والانتحال الإداري (Impersonation)
-- @version 1.0.0
-- @author Tourfecto Team
-- @copyright 2026 Tourfecto
-- ============================================

-- ============================================
-- 1. جدول سجل تسجيل الدخول (login_history)
-- يسجّل كل محاولة دخول ناجحة/فاشلة لكل حساب، مع الموقع الجغرافي ونوع الجهاز
-- ============================================
CREATE TABLE IF NOT EXISTS `login_history` (
    `id` INT(11) NOT NULL AUTO_INCREMENT COMMENT 'المعرف الفريد للسجل',
    `user_id` INT(11) DEFAULT NULL COMMENT 'معرف المستخدم (NULL لو المحاولة فشلت قبل تحديد المستخدم)',
    `email_attempted` VARCHAR(255) DEFAULT NULL COMMENT 'البريد المستخدم في محاولة الدخول',
    `status` ENUM('success', 'failed') NOT NULL DEFAULT 'success' COMMENT 'نتيجة محاولة الدخول',
    `ip_address` VARCHAR(45) DEFAULT NULL COMMENT 'عنوان IP',
    `user_agent` TEXT DEFAULT NULL COMMENT 'الـ User Agent الخام',
    `device_type` VARCHAR(50) DEFAULT NULL COMMENT 'desktop / mobile / tablet / bot',
    `browser` VARCHAR(100) DEFAULT NULL COMMENT 'اسم المتصفح',
    `platform` VARCHAR(100) DEFAULT NULL COMMENT 'نظام التشغيل',
    `country` VARCHAR(100) DEFAULT NULL COMMENT 'الدولة (Geo IP)',
    `city` VARCHAR(100) DEFAULT NULL COMMENT 'المدينة (Geo IP)',
    `region` VARCHAR(100) DEFAULT NULL COMMENT 'المنطقة/المحافظة (Geo IP)',
    `latitude` DECIMAL(10,6) DEFAULT NULL COMMENT 'خط العرض',
    `longitude` DECIMAL(10,6) DEFAULT NULL COMMENT 'خط الطول',
    `session_id` VARCHAR(255) DEFAULT NULL COMMENT 'معرف الجلسة',
    `is_impersonation` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'هل الدخول كان عبر انتحال الأدمن',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'تاريخ ووقت المحاولة',
    PRIMARY KEY (`id`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    INDEX `idx_user_id` (`user_id`),
    INDEX `idx_status` (`status`),
    INDEX `idx_created_at` (`created_at`),
    INDEX `idx_ip_address` (`ip_address`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='سجل تسجيل الدخول لكل حساب';

-- ============================================
-- 2. جدول سجلات انتحال الأدمن لحسابات العملاء (impersonation_logs)
-- كل مرة الأدمن يدخل بحساب عميل لأغراض الدعم الفني، لازم يتسجل هنا
-- ============================================
CREATE TABLE IF NOT EXISTS `impersonation_logs` (
    `id` INT(11) NOT NULL AUTO_INCREMENT COMMENT 'المعرف الفريد للسجل',
    `admin_id` INT(11) NOT NULL COMMENT 'معرف الأدمن اللي بدأ الجلسة',
    `target_user_id` INT(11) NOT NULL COMMENT 'معرف العميل المستهدف',
    `ip_address` VARCHAR(45) DEFAULT NULL COMMENT 'عنوان IP الخاص بالأدمن',
    `reason` VARCHAR(255) DEFAULT NULL COMMENT 'سبب الدخول (اختياري، لأغراض التوثيق)',
    `started_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'وقت بدء الانتحال',
    `ended_at` TIMESTAMP NULL DEFAULT NULL COMMENT 'وقت انتهاء الانتحال',
    PRIMARY KEY (`id`),
    FOREIGN KEY (`admin_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`target_user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    INDEX `idx_admin_id` (`admin_id`),
    INDEX `idx_target_user_id` (`target_user_id`),
    INDEX `idx_started_at` (`started_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='سجل انتحال الأدمن لحسابات العملاء';

-- ============================================
-- 3. جدول تتبع الزوار (visitor_logs)
-- يسجّل زيارات الموقع التسويقي العام + تصفح العملاء داخل المنصة بعد الدخول
-- ============================================
CREATE TABLE IF NOT EXISTS `visitor_logs` (
    `id` INT(11) NOT NULL AUTO_INCREMENT COMMENT 'المعرف الفريد للزيارة',
    `visitor_id` VARCHAR(64) NOT NULL COMMENT 'معرف الزائر الثابت (كوكي)، بيربط زيارات نفس المتصفح ببعض',
    `user_id` INT(11) DEFAULT NULL COMMENT 'معرف المستخدم لو كان مسجل دخول وقت الزيارة',
    `session_id` VARCHAR(255) DEFAULT NULL COMMENT 'معرف جلسة PHP وقت الزيارة',
    `page_url` VARCHAR(500) NOT NULL COMMENT 'المسار اللي اتزار',
    `referrer` VARCHAR(500) DEFAULT NULL COMMENT 'المصدر (Referrer)',
    `ip_address` VARCHAR(45) DEFAULT NULL COMMENT 'عنوان IP',
    `user_agent` TEXT DEFAULT NULL COMMENT 'الـ User Agent الخام',
    `device_type` VARCHAR(50) DEFAULT NULL COMMENT 'desktop / mobile / tablet / bot',
    `browser` VARCHAR(100) DEFAULT NULL COMMENT 'اسم المتصفح',
    `platform` VARCHAR(100) DEFAULT NULL COMMENT 'نظام التشغيل',
    `country` VARCHAR(100) DEFAULT NULL COMMENT 'الدولة (Geo IP)',
    `city` VARCHAR(100) DEFAULT NULL COMMENT 'المدينة (Geo IP)',
    `is_authenticated` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'هل الزائر كان مسجل دخول',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'تاريخ ووقت الزيارة',
    PRIMARY KEY (`id`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    INDEX `idx_visitor_id` (`visitor_id`),
    INDEX `idx_user_id` (`user_id`),
    INDEX `idx_created_at` (`created_at`),
    INDEX `idx_page_url` (`page_url`(191)),
    INDEX `idx_is_authenticated` (`is_authenticated`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='سجل تتبع الزوار (الموقع التسويقي + داخل المنصة)';
