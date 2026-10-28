-- ============================================================
-- Tourfecto - Migration: نسيت كلمة المرور (forgot/reset password)
-- @version 1.0.0  @date 2026-07-19
-- ============================================================

CREATE TABLE IF NOT EXISTS `password_reset_tokens` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `user_id` INT(11) NOT NULL,
    `token_hash` VARCHAR(255) NOT NULL COMMENT 'hash بتاع التوكن، مش التوكن الخام (زي الباسورد بالظبط)',
    `expires_at` TIMESTAMP NOT NULL,
    `used_at` TIMESTAMP NULL DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    INDEX `idx_token_hash` (`token_hash`),
    INDEX `idx_user_id` (`user_id`),
    INDEX `idx_expires_at` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='توكنات إعادة تعيين كلمة المرور';
