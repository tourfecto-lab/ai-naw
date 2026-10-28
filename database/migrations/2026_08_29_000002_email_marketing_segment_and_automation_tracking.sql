-- ============================================
-- G3 (2026-08-29): تتبع فتح/كليك رسائل الأتمتة
-- سجل رسائل الأتمتة المرسلة مع توكنات تتبع فريدة — بنفس نمط
-- email_transactional_logs (نفس عمود open_token/click_token وعدّادات
-- الفتح/الكليك) ليستقبلها مسار التتبع العام /track/open و /track/click.
-- ============================================
CREATE TABLE IF NOT EXISTS `email_automation_logs` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `user_id` INT(11) NOT NULL,
    `automation_id` INT(11) NOT NULL,
    `entry_id` INT(11) NULL DEFAULT NULL,
    `step_id` INT(11) NULL DEFAULT NULL,
    `subscriber_id` INT(11) NULL DEFAULT NULL,
    `to_email` VARCHAR(191) NOT NULL,
    `to_name` VARCHAR(191) NULL DEFAULT NULL,
    `subject` VARCHAR(255) NOT NULL DEFAULT '',
    `status` VARCHAR(16) NOT NULL DEFAULT 'sent',
    `error` VARCHAR(1000) NULL DEFAULT NULL,
    `open_token` VARCHAR(64) NULL DEFAULT NULL,
    `click_token` VARCHAR(64) NULL DEFAULT NULL,
    `opened_at` DATETIME NULL DEFAULT NULL,
    `clicked_at` DATETIME NULL DEFAULT NULL,
    `open_count` INT(11) NOT NULL DEFAULT 0,
    `click_count` INT(11) NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_email_auto_logs_user` (`user_id`),
    KEY `idx_email_auto_logs_auto` (`automation_id`),
    KEY `idx_email_auto_logs_sub` (`subscriber_id`),
    KEY `idx_email_auto_logs_open` (`open_token`),
    KEY `idx_email_auto_logs_click` (`click_token`),
    CONSTRAINT `fk_email_auto_logs_user` FOREIGN KEY (`user_id`)
        REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_email_auto_logs_auto` FOREIGN KEY (`automation_id`)
        REFERENCES `email_automations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='سجل رسائل الأتمتة وتتبع التفاعل';

-- ============================================
-- G2 (2026-08-29): استهداف شريحة كجمهور حملة
-- عمود اختياري segment_id يغلب على list_id/audience_ids عند تعيينه:
-- الجمهور يُحسب من تقييم الشريحة الديناميكية بدل اتحاد القوائم.
-- ============================================
ALTER TABLE `email_campaigns`
    ADD COLUMN `segment_id` INT(11) NULL DEFAULT NULL COMMENT 'الجمهور: شريحة ديناميكية (تغلب على القوائم)' AFTER `audience_ids`,
    ADD KEY `idx_email_campaigns_segment` (`segment_id`),
    ADD CONSTRAINT `fk_email_campaigns_segment` FOREIGN KEY (`segment_id`)
        REFERENCES `email_segments` (`id`) ON DELETE SET NULL;
