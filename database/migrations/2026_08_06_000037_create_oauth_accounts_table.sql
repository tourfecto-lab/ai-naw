-- ============================================================
-- Tourfecto - Migration: جدول ربط حسابات تسجيل الدخول الاجتماعي
-- (Google / Apple / Facebook / Microsoft) بمستخدمي المنصة.
-- مستخدم واحد ممكن يربط أكتر من منصة على نفس الحساب.
-- @version 1.0.0  @date 2026-08-06
-- ============================================================

CREATE TABLE IF NOT EXISTS `oauth_accounts` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `user_id` INT(11) NOT NULL COMMENT 'المستخدم المرتبط في جدول users',
    `provider` ENUM('google', 'apple', 'facebook', 'microsoft') NOT NULL COMMENT 'منصة تسجيل الدخول',
    `provider_user_id` VARCHAR(255) NOT NULL COMMENT 'المعرّف الفريد للمستخدم عند المنصة (sub/id)',
    `email` VARCHAR(255) DEFAULT NULL COMMENT 'الإيميل اللي رجعته المنصة وقت الربط',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_provider_account` (`provider`, `provider_user_id`),
    INDEX `idx_user_id` (`user_id`),
    CONSTRAINT `fk_oauth_accounts_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='ربط حسابات تسجيل الدخول الاجتماعي بالمستخدمين';

-- إعدادات تسجيل الدخول الاجتماعي الجديدة (Google/Facebook بيعيدوا استخدام
-- google_client_id/secret و meta_app_id/secret الموجودين بالفعل - محتاجين
-- بس نضيف Microsoft و Apple)
INSERT INTO `system_settings` (`setting_key`, `setting_value`, `is_secret`, `category`) VALUES
    ('oauth_microsoft_client_id', '', 0, 'oauth_login'),
    ('oauth_microsoft_client_secret', '', 1, 'oauth_login'),
    ('oauth_microsoft_tenant', 'common', 0, 'oauth_login'),
    ('oauth_apple_client_id', '', 0, 'oauth_login'),
    ('oauth_apple_team_id', '', 0, 'oauth_login'),
    ('oauth_apple_key_id', '', 0, 'oauth_login'),
    ('oauth_apple_private_key', '', 1, 'oauth_login')
ON DUPLICATE KEY UPDATE `setting_key` = `setting_key`;
