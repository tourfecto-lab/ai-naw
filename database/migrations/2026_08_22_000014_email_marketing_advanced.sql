-- Tourfecto - Email Marketing Advanced (المرحلة 4)
-- إعدادات SMTP لكل مستخدم + رسائل المعاملات (transactional) + اختبار أ/ب (A/B)
-- Idempotent: البناء بالـ CREATE TABLE IF NOT EXISTS.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `email_smtp_settings` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `user_id` int(11) NOT NULL,
    `host` varchar(191) NOT NULL DEFAULT '',
    `port` int(11) NOT NULL DEFAULT 587,
    `username` varchar(191) NOT NULL DEFAULT '',
    `password` varchar(255) NOT NULL DEFAULT '',
    `encryption` varchar(16) NOT NULL DEFAULT 'tls',
    `from_email` varchar(191) DEFAULT NULL,
    `from_name` varchar(191) DEFAULT NULL,
    `is_active` tinyint(1) NOT NULL DEFAULT 0,
    `last_test_at` datetime DEFAULT NULL,
    `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
    `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_email_smtp_settings_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='إعدادات SMTP لكل مستخدم';

CREATE TABLE IF NOT EXISTS `email_transactional_templates` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `user_id` int(11) NOT NULL,
    `name` varchar(191) NOT NULL,
    `slug` varchar(191) NOT NULL,
    `subject` varchar(255) NOT NULL DEFAULT '',
    `html_body` longtext DEFAULT NULL,
    `is_active` tinyint(1) NOT NULL DEFAULT 1,
    `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
    `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_email_transactional_templates_user_slug` (`user_id`, `slug`),
    KEY `idx_email_transactional_templates_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='قوالب رسائل المعاملات';

CREATE TABLE IF NOT EXISTS `email_transactional_logs` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `user_id` int(11) NOT NULL,
    `template_id` int(11) DEFAULT NULL,
    `to_email` varchar(191) NOT NULL,
    `to_name` varchar(191) DEFAULT NULL,
    `subject` varchar(255) NOT NULL DEFAULT '',
    `status` varchar(16) NOT NULL DEFAULT 'sent',
    `error` varchar(1000) DEFAULT NULL,
    `open_token` varchar(64) DEFAULT NULL,
    `click_token` varchar(64) DEFAULT NULL,
    `opened_at` datetime DEFAULT NULL,
    `clicked_at` datetime DEFAULT NULL,
    `open_count` int(11) NOT NULL DEFAULT 0,
    `click_count` int(11) NOT NULL DEFAULT 0,
    `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (`id`),
    KEY `idx_email_transactional_logs_user` (`user_id`),
    KEY `idx_email_transactional_logs_open` (`open_token`),
    KEY `idx_email_transactional_logs_click` (`click_token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='سجل رسائل المعاملات المرسلة';

CREATE TABLE IF NOT EXISTS `email_ab_tests` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `user_id` int(11) NOT NULL,
    `name` varchar(191) NOT NULL,
    `base_campaign_id` int(11) NOT NULL,
    `variant_a_id` int(11) NOT NULL,
    `variant_b_id` int(11) NOT NULL,
    `split_percent` int(11) NOT NULL DEFAULT 50,
    `metric` varchar(16) NOT NULL DEFAULT 'open',
    `status` varchar(16) NOT NULL DEFAULT 'draft',
    `winner` varchar(4) DEFAULT NULL,
    `winner_at` datetime DEFAULT NULL,
    `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
    `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
    PRIMARY KEY (`id`),
    KEY `idx_email_ab_tests_user` (`user_id`),
    KEY `idx_email_ab_tests_base` (`base_campaign_id`),
    KEY `idx_email_ab_tests_variant_a` (`variant_a_id`),
    KEY `idx_email_ab_tests_variant_b` (`variant_b_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='اختبار أ/ب لحملات البريد';
