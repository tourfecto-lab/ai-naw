-- Tourfecto - Email Marketing Automations (المرحلة 3)
-- سير عمل تلقائي: مشغلات (اشتراك/فتح/كليك/وسم) + خطوات (انتظار/إرسال بريد/وسوم/قوائم)
-- Idempotent: البناء بالـ CREATE TABLE IF NOT EXISTS.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `email_automations` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `user_id` int(11) NOT NULL,
    `name` varchar(191) NOT NULL,
    `description` varchar(255) DEFAULT NULL,
    `trigger_type` varchar(32) NOT NULL DEFAULT 'subscribed',
    `trigger_value` text DEFAULT NULL COMMENT 'JSON: list_id/tag/campaign_id',
    `entry_audience_ids` text DEFAULT NULL COMMENT 'JSON: قوائم مؤهلة للدخول',
    `exit_audience_ids` text DEFAULT NULL COMMENT 'JSON: قوائم تسبب الخروج',
    `status` varchar(16) NOT NULL DEFAULT 'active',
    `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
    `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
    PRIMARY KEY (`id`),
    KEY `idx_email_automations_user` (`user_id`),
    KEY `idx_email_automations_trigger` (`trigger_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='سير عمل أتمتة تسويق البريد';

CREATE TABLE IF NOT EXISTS `email_automation_steps` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `automation_id` int(11) NOT NULL,
    `position` int(11) NOT NULL DEFAULT 0,
    `step_type` varchar(32) NOT NULL DEFAULT 'wait',
    `step_value` text DEFAULT NULL COMMENT 'JSON: wait.days/email.subject/email.html/tag/list_id',
    `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (`id`),
    KEY `idx_email_automation_steps_auto` (`automation_id`),
    CONSTRAINT `fk_email_automation_steps_auto` FOREIGN KEY (`automation_id`)
        REFERENCES `email_automations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='خطوات سير العمل';

CREATE TABLE IF NOT EXISTS `email_automation_entries` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `automation_id` int(11) NOT NULL,
    `user_id` int(11) NOT NULL,
    `subscriber_id` int(11) NOT NULL,
    `step_position` int(11) NOT NULL DEFAULT 0,
    `status` varchar(16) NOT NULL DEFAULT 'active',
    `context` text DEFAULT NULL COMMENT 'JSON سياق الحدث',
    `entered_at` timestamp NOT NULL DEFAULT current_timestamp(),
    `next_run_at` datetime DEFAULT NULL,
    `last_processed_at` datetime DEFAULT NULL,
    `completed_at` datetime DEFAULT NULL,
    `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
    `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
    PRIMARY KEY (`id`),
    KEY `idx_email_automation_entries_auto_sub` (`automation_id`, `subscriber_id`),
    KEY `idx_email_automation_entries_due` (`status`, `next_run_at`),
    KEY `idx_email_automation_entries_user` (`user_id`),
    CONSTRAINT `fk_email_automation_entries_auto` FOREIGN KEY (`automation_id`)
        REFERENCES `email_automations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='مشاركون في سير العمل';
