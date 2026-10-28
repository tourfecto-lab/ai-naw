-- ============================================================
-- Tourfecto - Migration: توسعة CRM من ai-crm-leads-hub
-- @version 1.0.0  @date 2026-07-14
--
-- ملاحظة: جداول `tenants`/`users`/`customers`/`leads` الأصلية اتجوهلت
-- بالكامل - `agencies`/`users`/`crm_contacts`/`crm_leads` الموجودة
-- عندك فعلاً بتغطي نفس المفهوم. `lead_scores` اتجوهل (عمود
-- `crm_leads.score` الموجود يكفي). `activities` اتجوهل (استخدم
-- `activity_logs` الموحّد الموجود بدل جدول تكرار). `whatsapp_messages`/
-- `email_messages` اتجوهلوا لأنهم يحتاجوا تكامل API حقيقي (WhatsApp
-- Business API / SMTP) غير موجود بعد - إضافتهم فارغين هتكون واجهة
-- بلا وظيفة حقيقية.
-- ============================================================

CREATE TABLE IF NOT EXISTS `crm_pipeline_stages` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `agency_id` INT(11) DEFAULT NULL COMMENT 'NULL = مرحلة افتراضية عامة لكل المستخدمين',
    `name` VARCHAR(100) NOT NULL,
    `slug` VARCHAR(100) NOT NULL,
    `sort_order` INT(11) NOT NULL DEFAULT 0,
    `win_probability` TINYINT(3) UNSIGNED NOT NULL DEFAULT 0 COMMENT '0-100',
    `is_won` TINYINT(1) NOT NULL DEFAULT 0,
    `is_lost` TINYINT(1) NOT NULL DEFAULT 0,
    `color` VARCHAR(20) DEFAULT '#6366f1',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    FOREIGN KEY (`agency_id`) REFERENCES `agencies`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='مراحل مسار البيع (Pipeline)';

-- مراحل افتراضية عامة جاهزة للاستخدام فورًا (agency_id = NULL)
INSERT INTO `crm_pipeline_stages` (`agency_id`, `name`, `slug`, `sort_order`, `win_probability`, `is_won`, `is_lost`, `color`) VALUES
(NULL, 'جديد', 'new', 1, 10, 0, 0, '#6366f1'),
(NULL, 'تواصل أولي', 'contacted', 2, 25, 0, 0, '#0EA5E9'),
(NULL, 'مؤهَّل', 'qualified', 3, 50, 0, 0, '#F59E0B'),
(NULL, 'عرض سعر', 'proposal', 4, 75, 0, 0, '#8B5CF6'),
(NULL, 'مكسوبة', 'won', 5, 100, 1, 0, '#22C55E'),
(NULL, 'خسرانة', 'lost', 6, 0, 0, 1, '#EF4444');

CREATE TABLE IF NOT EXISTS `crm_deals` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `owner_user_id` INT(11) DEFAULT NULL,
    `lead_id` INT(11) DEFAULT NULL,
    `contact_id` INT(11) DEFAULT NULL,
    `stage_id` INT(11) NOT NULL,
    `title` VARCHAR(200) NOT NULL,
    `value` DECIMAL(14,2) DEFAULT 0,
    `currency` VARCHAR(10) DEFAULT 'USD',
    `probability` TINYINT(3) UNSIGNED DEFAULT 0,
    `expected_close_date` DATE DEFAULT NULL,
    `closed_at` TIMESTAMP NULL DEFAULT NULL,
    `status` ENUM('open','won','lost') NOT NULL DEFAULT 'open',
    `lost_reason` VARCHAR(255) DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    FOREIGN KEY (`owner_user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`lead_id`) REFERENCES `crm_leads`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`contact_id`) REFERENCES `crm_contacts`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`stage_id`) REFERENCES `crm_pipeline_stages`(`id`),
    INDEX `idx_stage` (`stage_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='صفقات (Deals/Opportunities)';

CREATE TABLE IF NOT EXISTS `crm_tasks` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `assigned_to_user_id` INT(11) DEFAULT NULL,
    `related_type` ENUM('lead','contact','deal') DEFAULT NULL,
    `related_id` INT(11) DEFAULT NULL,
    `title` VARCHAR(200) NOT NULL,
    `description` TEXT DEFAULT NULL,
    `due_date` TIMESTAMP NULL DEFAULT NULL,
    `priority` ENUM('low','medium','high','urgent') NOT NULL DEFAULT 'medium',
    `status` ENUM('pending','in_progress','completed','cancelled') NOT NULL DEFAULT 'pending',
    `completed_at` TIMESTAMP NULL DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    FOREIGN KEY (`assigned_to_user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    INDEX `idx_related` (`related_type`, `related_id`),
    INDEX `idx_due_date` (`due_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='مهام متابعة CRM';

CREATE TABLE IF NOT EXISTS `crm_meetings` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `organizer_user_id` INT(11) DEFAULT NULL,
    `related_type` ENUM('lead','contact','deal') DEFAULT NULL,
    `related_id` INT(11) DEFAULT NULL,
    `title` VARCHAR(200) NOT NULL,
    `meeting_link` VARCHAR(255) DEFAULT NULL,
    `starts_at` TIMESTAMP NOT NULL,
    `ends_at` TIMESTAMP NULL DEFAULT NULL,
    `status` ENUM('scheduled','completed','cancelled','no_show') NOT NULL DEFAULT 'scheduled',
    `summary` TEXT DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    FOREIGN KEY (`organizer_user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    INDEX `idx_related` (`related_type`, `related_id`),
    INDEX `idx_starts_at` (`starts_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='اجتماعات CRM';

CREATE TABLE IF NOT EXISTS `crm_notes` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `author_user_id` INT(11) DEFAULT NULL,
    `related_type` ENUM('lead','contact','deal') NOT NULL,
    `related_id` INT(11) NOT NULL,
    `body` TEXT NOT NULL,
    `pinned` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    FOREIGN KEY (`author_user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    INDEX `idx_related` (`related_type`, `related_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='ملاحظات CRM';
