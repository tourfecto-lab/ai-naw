-- Tourfecto - Revenue Action Executor (طبقة التنفيذ) v1.0.0
-- تاريخ: 2026-08-20
--
-- جدول سجلّ تنفيذ إجراءات الإيرادات: بيمنع تكرار نفس الإجراء لنفس
-- الفكرة (dedup) وبيوفّر سجل تدقيق/تاريخ لكل تنفيذ.
--   action_key = source_type:source_category:affected_area
--                (مثال: risk:customer_inactivity:customer:12)
--   actions_taken = JSON ["crm_task","notification"]

CREATE TABLE IF NOT EXISTS `revai_action_executions` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `action_key` VARCHAR(190) NOT NULL,
    `source_type` VARCHAR(20) NULL,
    `source_category` VARCHAR(60) NULL,
    `affected_area` VARCHAR(120) NULL,
    `severity` VARCHAR(10) NULL,
    `actions_taken` TEXT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_revai_act_user` (user_id, created_at),
    KEY `idx_revai_act_key` (action_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
