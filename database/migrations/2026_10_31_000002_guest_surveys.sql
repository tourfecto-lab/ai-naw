-- Reputation: private guest feedback surveys (0-10 "would you recommend us" + comment), one stateless link per website.
-- Plain CREATE TABLE IF NOT EXISTS: valid on MySQL and MariaDB. Apply by hand (phpMyAdmin > SQL).
-- Until it is applied the survey page says it is unavailable and nothing else is affected.
CREATE TABLE IF NOT EXISTS `guest_surveys` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `user_id` INT NOT NULL,
    `website_id` INT NOT NULL,
    `score` TINYINT NOT NULL,
    `comment` TEXT NULL,
    `guest_name` VARCHAR(150) NULL,
    `guest_contact` VARCHAR(190) NULL,
    `status` ENUM('new','handled') NOT NULL DEFAULT 'new',
    `ip_hash` CHAR(16) NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `handled_at` DATETIME NULL,
    PRIMARY KEY (`id`),
    KEY `idx_survey_site` (`website_id`, `created_at`),
    KEY `idx_survey_user_status` (`user_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
