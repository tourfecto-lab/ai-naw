-- ============================================================
-- BATCH6 - AI Website Optimizer
-- بادئة wo_ لكل الجداول. اتجاهلنا جدول sites الأصلي بتاع الموديول
-- وربطنا الأودت مباشرة بجدول websites الموجود عندك بالفعل بدل ما
-- نكرر إدارة "مواقع" موازية.
-- ============================================================

CREATE TABLE IF NOT EXISTS `wo_audits` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `website_id` INT(11) NOT NULL,
    `user_id` INT(11) NOT NULL,
    `status` VARCHAR(20) NOT NULL DEFAULT 'running' COMMENT 'running|completed|failed',
    `overall_score` DECIMAL(5,1) NULL,
    `started_at` TIMESTAMP NULL DEFAULT NULL,
    `completed_at` TIMESTAMP NULL DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_wo_website` (`website_id`),
    FOREIGN KEY (`website_id`) REFERENCES `websites`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='تدقيقات تقنية على مواقع المستخدم الموجودة';

CREATE TABLE IF NOT EXISTS `wo_audit_findings` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `audit_id` BIGINT UNSIGNED NOT NULL,
    `category` VARCHAR(30) NOT NULL COMMENT 'speed/seo/accessibility/mobile/security/images/broken_links',
    `check_key` VARCHAR(60) NOT NULL,
    `title` VARCHAR(255) NOT NULL,
    `status` VARCHAR(10) NOT NULL COMMENT 'pass|warn|fail|info',
    `severity` VARCHAR(10) NOT NULL COMMENT 'critical|high|medium|low|info',
    `message` TEXT NOT NULL,
    `details` JSON NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_wo_audit_cat` (`audit_id`, `category`),
    KEY `idx_wo_audit_status` (`audit_id`, `status`),
    FOREIGN KEY (`audit_id`) REFERENCES `wo_audits`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `wo_broken_links` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `audit_id` BIGINT UNSIGNED NOT NULL,
    `source_url` VARCHAR(500) NOT NULL,
    `target_url` VARCHAR(500) NOT NULL,
    `link_type` VARCHAR(20) NOT NULL COMMENT 'internal|external|image',
    `status_code` SMALLINT UNSIGNED DEFAULT 0,
    `error` VARCHAR(255) NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_wo_bl_audit` (`audit_id`),
    FOREIGN KEY (`audit_id`) REFERENCES `wo_audits`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `wo_fixes` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `audit_id` BIGINT UNSIGNED NOT NULL,
    `category` VARCHAR(30) NOT NULL,
    `title` VARCHAR(255) NOT NULL,
    `description` TEXT NOT NULL,
    `fix_type` VARCHAR(20) NOT NULL COMMENT 'code_snippet|config|content',
    `code_snippet` MEDIUMTEXT NULL,
    `target_file` VARCHAR(255) NULL,
    `status` VARCHAR(20) NOT NULL DEFAULT 'pending' COMMENT 'pending|applied|dismissed',
    `applied_at` TIMESTAMP NULL DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_wo_fix_audit_status` (`audit_id`, `status`),
    FOREIGN KEY (`audit_id`) REFERENCES `wo_audits`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
