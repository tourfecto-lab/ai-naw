-- Reputation: stand-alone plans paid from the wallet (one row per account). Plain CREATE TABLE: valid on MySQL and MariaDB.
-- Apply by hand. Until it is applied nothing can be sold: the admin page and the customer page say a database update is pending.
CREATE TABLE IF NOT EXISTS `reputation_subscriptions` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `user_id` INT NOT NULL,
    `plan_key` VARCHAR(30) NOT NULL,
    `cycle` VARCHAR(10) NOT NULL DEFAULT 'monthly',
    `price_paid` DECIMAL(10,2) NOT NULL DEFAULT 0,
    `status` VARCHAR(16) NOT NULL DEFAULT 'active',
    `auto_renew` TINYINT(1) NOT NULL DEFAULT 1,
    `pending_plan` VARCHAR(30) NULL,
    `pending_cycle` VARCHAR(10) NULL,
    `period_start` DATETIME NOT NULL,
    `period_end` DATETIME NOT NULL,
    `last_ref` VARCHAR(100) NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_rep_sub_user` (`user_id`),
    KEY `idx_rep_sub_due` (`status`, `auto_renew`, `period_end`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
