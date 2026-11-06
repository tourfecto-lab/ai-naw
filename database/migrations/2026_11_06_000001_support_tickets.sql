-- Support tickets: customers open a ticket (problem, question, complaint...), the support team answers it.
-- Plain CREATE TABLE IF NOT EXISTS (works on production MySQL). SupportTicketService also creates these lazily.
CREATE TABLE IF NOT EXISTS `support_tickets` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `subject` VARCHAR(200) NOT NULL,
  `category` VARCHAR(30) NOT NULL DEFAULT 'question',
  `priority` VARCHAR(10) NOT NULL DEFAULT 'normal',
  `status` VARCHAR(20) NOT NULL DEFAULT 'open',
  `assigned_to` BIGINT UNSIGNED NULL,
  `last_reply_by` VARCHAR(10) NOT NULL DEFAULT 'customer',
  `rating` TINYINT NULL,
  `rating_comment` VARCHAR(500) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_activity_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `closed_at` DATETIME NULL,
  KEY `idx_st_user` (`user_id`, `last_activity_at`),
  KEY `idx_st_status` (`status`, `last_activity_at`),
  KEY `idx_st_assigned` (`assigned_to`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `support_ticket_messages` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `ticket_id` BIGINT UNSIGNED NOT NULL,
  `author_id` BIGINT UNSIGNED NOT NULL,
  `author_type` VARCHAR(10) NOT NULL DEFAULT 'customer',
  `body` TEXT NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_stm_ticket` (`ticket_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
