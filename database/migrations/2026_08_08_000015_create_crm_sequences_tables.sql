-- ============================================================
-- Tourfecto - Migration: CRM Sequences (متابعات متعددة الخطوات)
-- @date 2026-08-08
--
-- الملف ده كان بالغلط نسخة من 2026_08_10_000048 (review_request_templates)،
-- فجداول الـ Sequences ماكانتش بتتعمل. الأعمدة مطابقة لـ
-- app/Models/CrmSequence.php و CrmSequenceEnrollment.php و
-- app/Services/Crm/CrmSequenceService.php.
-- ============================================================

CREATE TABLE IF NOT EXISTS `crm_sequences` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `user_id` INT(11) NOT NULL COMMENT 'صاحب الحساب (Tenant)',
    `name` VARCHAR(255) NOT NULL,
    `description` TEXT DEFAULT NULL,
    `steps` TEXT DEFAULT NULL COMMENT 'JSON: [{"type":"email|whatsapp|task","delay_days":N,...}]',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_crm_sequences_user` (`user_id`),
    CONSTRAINT `fk_crm_sequences_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='تسلسلات متابعة CRM';

CREATE TABLE IF NOT EXISTS `crm_sequence_enrollments` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `user_id` INT(11) NOT NULL,
    `sequence_id` INT(11) NOT NULL,
    `related_type` VARCHAR(20) NOT NULL COMMENT 'contact / lead / deal',
    `related_id` INT(11) NOT NULL,
    `current_step` INT(11) NOT NULL DEFAULT 0,
    `next_run_at` DATETIME DEFAULT NULL,
    `status` ENUM('active', 'paused', 'completed', 'cancelled') NOT NULL DEFAULT 'active',
    `completed_at` DATETIME DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_crm_seq_enr_user` (`user_id`, `status`),
    INDEX `idx_crm_seq_enr_due` (`status`, `next_run_at`),
    INDEX `idx_crm_seq_enr_related` (`related_type`, `related_id`),
    CONSTRAINT `fk_crm_seq_enr_sequence` FOREIGN KEY (`sequence_id`) REFERENCES `crm_sequences` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_crm_seq_enr_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='اشتراك عميل/فرصة في تسلسل متابعة';
