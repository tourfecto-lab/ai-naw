-- ============================================================
-- BATCH6 - AI CEO Assistant (جزء منه بس) + Executive Command Center
-- ترقية حقيقية
-- بادئتين: ceo_ و cc_ (الأخيرة كانت مستخدمة بالفعل في الموديول
-- الأصلي بتاع executive-command-center، فخليناها زي ما هي).
-- اتجاهل عمدًا: ceo_executive_reports/ceo_recommendations/ceo_ai_queries/
-- ceo_business_metrics (تكرار مع ai_insights_reports ولوحة القيادة
-- التنفيذية الموجودة بالفعل)، وباقي جداول cc_ التانية (metric/score
-- snapshots, realtime_events, data_source_connections, event_cursors,
-- dashboard_preferences) لأنها محتاجة بنية تحتية realtime/webhooks
-- مش موجودة حاليًا - مؤجلة عمدًا.
-- ============================================================

CREATE TABLE IF NOT EXISTS `ceo_business_context_notes` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT(11) NOT NULL,
    `note` TEXT NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_ceo_notes_user` (`user_id`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='ملاحظات سياق بيزنس يكتبها صاحب الحساب عشان توجّه التوصيات';

CREATE TABLE IF NOT EXISTS `ceo_risk_alerts` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT(11) NOT NULL,
    `title` VARCHAR(255) NOT NULL,
    `description` TEXT NULL,
    `severity` VARCHAR(10) NOT NULL DEFAULT 'medium' COMMENT 'critical|high|medium|low',
    `source_module` VARCHAR(50) NULL COMMENT 'reputation/crm/ads/competitor',
    `is_resolved` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_ceo_risk_user` (`user_id`, `is_resolved`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ceo_growth_opportunities` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT(11) NOT NULL,
    `title` VARCHAR(255) NOT NULL,
    `description` TEXT NULL,
    `estimated_impact` VARCHAR(10) NOT NULL DEFAULT 'medium' COMMENT 'high|medium|low',
    `status` VARCHAR(20) NOT NULL DEFAULT 'new' COMMENT 'new|in_progress|done|dismissed',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_ceo_growth_user` (`user_id`, `status`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `cc_ai_alerts` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT(11) NOT NULL,
    `message` VARCHAR(500) NOT NULL,
    `severity` VARCHAR(10) NOT NULL DEFAULT 'medium',
    `is_read` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_cc_alert_user` (`user_id`, `is_read`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='تنبيهات ثابتة (persisted) بدل ما تتحسب كل مرة من غير حفظ حالة القراءة';

CREATE TABLE IF NOT EXISTS `cc_ai_tasks` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT(11) NOT NULL,
    `title` VARCHAR(255) NOT NULL,
    `status` VARCHAR(20) NOT NULL DEFAULT 'open' COMMENT 'open|done|dismissed',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_cc_task_user` (`user_id`, `status`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
