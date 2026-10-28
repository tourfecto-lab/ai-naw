-- Tourfecto - Action Center Execution (طبقة التنفيذ الموحّدة) v1.0.0
-- تاريخ: 2026-08-20
--
-- جدول سجلّ تنفيذ إجراءات مركز الإجراءات الموحّد (Competitor Intelligence +
-- CEO Advisor + Marketing Assistant): بيمنع تكرار نفس الإجراء لنفس الفكرة
-- (dedup) وبيوفّر سجل تدقيق/تاريخ لكل تنفيذ.
--   action_key = source_type:source_category:affected_area[:period]
--   actions_taken = JSON ["crm_task","notification"]

CREATE TABLE IF NOT EXISTS `action_executions` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `action_key` VARCHAR(190) NOT NULL,
    `source_type` VARCHAR(20) NULL,
    `source_category` VARCHAR(60) NULL,
    `affected_area` VARCHAR(120) NULL,
    `severity` VARCHAR(10) NULL,
    `actions_taken` TEXT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_action_exec_user` (user_id, created_at),
    KEY `idx_action_exec_key` (action_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='سجلّ تنفيذ إجراءات مركز الإجراءات (dedup + تدقيق)';

-- توسعة حالة رؤى المنافسين لتدعم وسم "actioned" بعد تنفيذها.
ALTER TABLE `ci_insights`
    MODIFY `status` ENUM('new','reviewed','dismissed','actioned') NOT NULL DEFAULT 'new';
