-- ============================================================
-- Tourfecto - Migration: جدول رسائل نموذج التواصل (/help/contact)
-- قبل كده كانت بتتسجل في app.log بس، يعني سهل جدًا حد ينساها.
-- @version 1.0.0  @date 2026-07-19
-- ============================================================

CREATE TABLE IF NOT EXISTS `contact_submissions` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(150) NOT NULL,
    `email` VARCHAR(150) NOT NULL,
    `message` TEXT NOT NULL,
    `user_id` INT(11) DEFAULT NULL COMMENT 'لو كان مسجّل دخول وقت الإرسال',
    `status` ENUM('new', 'read', 'replied') NOT NULL DEFAULT 'new',
    `ip_address` VARCHAR(45) DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_status` (`status`),
    INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='رسائل نموذج التواصل من صفحة /help/contact';
