-- ============================================================
-- Tourfecto - Migration: عمولات الوكالات (White-Label)
--
-- ملاحظة: جداول الوكالات الأساسية (agencies, agency_branding,
-- agency_domains, agency_clients, agency_email_templates) كانت
-- معرّفة بس في _PENDING_TO_RUN_ON_SERVER.sql (ملف تاريخي منتهي
-- لا يُنفَّذ). هذا الملف يعيد إنشاءها بشكل idempotent (IF NOT
-- EXISTS) لضمان أن قاعدة اختبار جديدة (وأي نشر جديد) تبنيها قبل
-- الاعتماد عليها في ALTER و agency_commissions.
--
-- 1) عمود commission_rate في agency_clients: نسبة عمولة الوكالة
--    من حجوزات هذا العميل (٪، قابل للتعديل لكل عميل على حدة).
-- 2) جدول agency_commissions: سجل عمولة لكل حجز مؤكد لعملاء
--    الوكالة. status: pending (مستحقة لم تُدفع بعد) / paid (سددها
--    الوكيل/الأدمن يدويًا — لا يوجد دفع تلقائي لهذه المرحلة).
-- @version 1.0.0  @date 2026-08-26
-- ============================================================

CREATE TABLE IF NOT EXISTS `agencies` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `owner_user_id` INT(11) NOT NULL COMMENT 'صاحب الوكالة - يشير لـ users.id',
    `name` VARCHAR(255) NOT NULL,
    `slug` VARCHAR(100) NOT NULL UNIQUE,
    `status` ENUM('active','suspended','pending') NOT NULL DEFAULT 'pending',
    `plan_seats` INT(11) NOT NULL DEFAULT 5 COMMENT 'أقصى عدد عملاء تحت الوكالة',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    FOREIGN KEY (`owner_user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    INDEX `idx_owner` (`owner_user_id`),
    INDEX `idx_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='وكالات White-Label';

CREATE TABLE IF NOT EXISTS `agency_branding` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `agency_id` INT(11) NOT NULL,
    `logo_path` VARCHAR(500) DEFAULT NULL,
    `favicon_path` VARCHAR(500) DEFAULT NULL,
    `primary_color` VARCHAR(20) DEFAULT '#4F46E5',
    `secondary_color` VARCHAR(20) DEFAULT '#0EA5E9',
    `custom_css` LONGTEXT DEFAULT NULL,
    `support_email` VARCHAR(255) DEFAULT NULL,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_agency` (`agency_id`),
    FOREIGN KEY (`agency_id`) REFERENCES `agencies`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='هوية بصرية مخصصة لكل وكالة';

CREATE TABLE IF NOT EXISTS `agency_domains` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `agency_id` INT(11) NOT NULL,
    `domain` VARCHAR(255) NOT NULL UNIQUE,
    `status` ENUM('pending_dns','verified','failed') NOT NULL DEFAULT 'pending_dns',
    `ssl_status` ENUM('pending','active','failed') NOT NULL DEFAULT 'pending',
    `verified_at` TIMESTAMP NULL DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    FOREIGN KEY (`agency_id`) REFERENCES `agencies`(`id`) ON DELETE CASCADE,
    INDEX `idx_domain` (`domain`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='نطاقات مخصصة لكل وكالة';

CREATE TABLE IF NOT EXISTS `agency_clients` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `agency_id` INT(11) NOT NULL,
    `client_user_id` INT(11) NOT NULL COMMENT 'يشير لـ users.id - العميل نفسه مستخدم عادي بدور مناسب',
    `status` ENUM('active','suspended') NOT NULL DEFAULT 'active',
    `added_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_agency_client` (`agency_id`, `client_user_id`),
    FOREIGN KEY (`agency_id`) REFERENCES `agencies`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`client_user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='ربط عملاء كل وكالة بمستخدمين حقيقيين';

CREATE TABLE IF NOT EXISTS `agency_email_templates` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `agency_id` INT(11) NOT NULL,
    `template_key` VARCHAR(100) NOT NULL COMMENT 'welcome, invoice, report_ready...',
    `subject` VARCHAR(255) NOT NULL,
    `body_html` LONGTEXT NOT NULL,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_agency_template` (`agency_id`, `template_key`),
    FOREIGN KEY (`agency_id`) REFERENCES `agencies`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='قوالب بريد مخصصة لكل وكالة';

SET @__fx_col_1_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'agency_clients' AND COLUMN_NAME = 'commission_rate'
);
SET @__fx_col_1_sql := IF(@__fx_col_1_exists = 0,
    'ALTER TABLE `agency_clients` ADD COLUMN `commission_rate` DECIMAL(5,2) NOT NULL DEFAULT 10.00 COMMENT ''نسبة عمولة الوكالة من حجوزات هذا العميل (%)'' AFTER `status`',
    'SELECT 1');
PREPARE __fx_col_1_stmt FROM @__fx_col_1_sql;
EXECUTE __fx_col_1_stmt;
DEALLOCATE PREPARE __fx_col_1_stmt;

CREATE TABLE IF NOT EXISTS `agency_commissions` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `agency_id` INT(11) NOT NULL COMMENT 'يشير إلى agencies.id',
    `agency_client_id` INT(11) NOT NULL COMMENT 'يشير إلى agency_clients.id (ربط العميل)',
    `booking_id` INT(11) NOT NULL COMMENT 'يشير إلى bookings.id - حجز واحد = عمولة واحدة كحد أقصى',
    `commission_amount` DECIMAL(12,2) NOT NULL COMMENT 'total_amount × commission_rate / 100',
    `status` ENUM('pending','paid') NOT NULL DEFAULT 'pending',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_agency_commissions_booking` (`booking_id`),
    KEY `idx_agency_commissions_agency` (`agency_id`),
    KEY `idx_agency_commissions_client` (`agency_client_id`),
    KEY `idx_agency_commissions_status` (`status`),
    CONSTRAINT `fk_agency_commissions_agency` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_agency_commissions_client` FOREIGN KEY (`agency_client_id`) REFERENCES `agency_clients` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_agency_commissions_booking` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='عمولات الوكالات من حجوزات عملائها';
