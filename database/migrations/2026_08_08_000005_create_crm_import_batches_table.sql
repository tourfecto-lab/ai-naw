-- ============================================================
-- Tourfecto - Migration: CRM Import Batches (المرحلة 9 - بند 37)
-- @version 1.0.0  @date 2026-08-08
-- ============================================================

CREATE TABLE IF NOT EXISTS `crm_import_batches` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `user_id` INT(11) NOT NULL COMMENT 'صاحب الحساب (Tenant)',
    `status` ENUM('pending', 'processing', 'completed', 'failed') NOT NULL DEFAULT 'pending',
    `total_rows` INT(11) NOT NULL DEFAULT 0,
    `imported_count` INT(11) NOT NULL DEFAULT 0,
    `skipped_count` INT(11) NOT NULL DEFAULT 0,
    `error` VARCHAR(500) DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `completed_at` DATETIME DEFAULT NULL,
    PRIMARY KEY (`id`),
    INDEX `idx_crm_import_batches_user` (`user_id`),
    CONSTRAINT `fk_crm_import_batches_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='تتبع دفعات استيراد جهات الاتصال (Background Jobs - بند 37)';
