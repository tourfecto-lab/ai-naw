-- TOURFECTO AI CEO (المرحلة 8): الإدارة المالية والاستراتيجية. إضافة بس.
-- aiceo_cost_items: المصاريف الثابتة الشهرية اللي صاحب المنصة بيدخلها (سيرفر، أدوات، مرتبات، تسويق...) عشان الأرباح تتحسب صح.
-- aiceo_goals: أهداف كل ربع سنة، مربوطة بمؤشر حقيقي من الصورة (MRR، العملاء، التسجيلات...) وبتتقاس لوحدها.
-- aiceo_goal_tasks: المهام الأسبوعية لكل هدف.
CREATE TABLE IF NOT EXISTS aiceo_cost_items (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 name VARCHAR(120) NOT NULL,
 category ENUM('infrastructure','tools','people','marketing','other') NOT NULL DEFAULT 'other',
 monthly_usd DECIMAL(12,2) NOT NULL DEFAULT 0,
 active TINYINT(1) NOT NULL DEFAULT 1,
 notes VARCHAR(255) NULL,
 created_at DATETIME NOT NULL,
 updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS aiceo_goals (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 quarter CHAR(7) NOT NULL,
 title VARCHAR(255) NOT NULL,
 metric VARCHAR(40) NOT NULL,
 baseline DECIMAL(14,2) NULL,
 target DECIMAL(14,2) NOT NULL,
 status ENUM('active','achieved','missed','dropped') NOT NULL DEFAULT 'active',
 created_by INT NULL,
 created_at DATETIME NOT NULL,
 KEY ix_aiceo_goals_quarter (quarter, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS aiceo_goal_tasks (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 goal_id BIGINT UNSIGNED NOT NULL,
 week CHAR(8) NOT NULL,
 title VARCHAR(255) NOT NULL,
 status ENUM('todo','doing','done','skipped') NOT NULL DEFAULT 'todo',
 created_at DATETIME NOT NULL,
 updated_at DATETIME NOT NULL,
 KEY ix_aiceo_goal_tasks_goal (goal_id, week)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
