-- TOURFECTO AI CEO (المرحلة 7): مراقبة المنصة ومستشار التطوير. إضافة بس.
-- aiceo_ops_checks: كل جولة فحص للصفحات (من غير تسجيل دخول) ونتيجتها.
-- aiceo_incidents: الحوادث (مشكلة اتكررت أو فحص فشل)، بتتفتح وتتقفل لوحدها، وعدد مرات تكرارها محفوظ لتحليل الأعطال المتكررة.
-- aiceo_dev_backlog: قائمة التطوير، كل بند فيه الخدمة والمشكلة والدليل والخطورة والتطوير المطلوب والأولوية والتكلفة والفايدة وطريقة الاختبار وهل محتاج موافقة، وقياس قبل/بعد.
CREATE TABLE IF NOT EXISTS aiceo_ops_checks (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 base_url VARCHAR(255) NOT NULL,
 checked INT NOT NULL DEFAULT 0,
 failed INT NOT NULL DEFAULT 0,
 slow INT NOT NULL DEFAULT 0,
 results_json MEDIUMTEXT NOT NULL,
 run_at DATETIME NOT NULL,
 KEY ix_aiceo_ops_checks_time (run_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS aiceo_incidents (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 ikey VARCHAR(190) NOT NULL,
 service VARCHAR(80) NOT NULL,
 title VARCHAR(255) NOT NULL,
 severity ENUM('critical','high','medium','low') NOT NULL DEFAULT 'medium',
 status ENUM('open','resolved') NOT NULL DEFAULT 'open',
 occurrence INT NOT NULL DEFAULT 1,
 evidence_json TEXT NULL,
 root_cause TEXT NULL,
 first_seen DATETIME NOT NULL,
 last_seen DATETIME NOT NULL,
 resolved_at DATETIME NULL,
 KEY ix_aiceo_incidents_key (ikey, status),
 KEY ix_aiceo_incidents_status (status, severity)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS aiceo_dev_backlog (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 bkey VARCHAR(190) NULL,
 source VARCHAR(40) NOT NULL DEFAULT 'manual',
 service VARCHAR(80) NOT NULL,
 problem VARCHAR(500) NOT NULL,
 evidence TEXT NULL,
 severity ENUM('critical','high','medium','low') NOT NULL DEFAULT 'medium',
 improvement TEXT NULL,
 priority DECIMAL(5,2) NOT NULL DEFAULT 0,
 cost_estimate ENUM('S','M','L') NOT NULL DEFAULT 'M',
 benefit VARCHAR(500) NULL,
 test_method VARCHAR(500) NULL,
 needs_approval TINYINT(1) NOT NULL DEFAULT 1,
 status ENUM('open','approved','in_progress','done','rejected') NOT NULL DEFAULT 'open',
 metric_before VARCHAR(255) NULL,
 metric_after VARCHAR(255) NULL,
 issue_url VARCHAR(255) NULL,
 created_at DATETIME NOT NULL,
 updated_at DATETIME NOT NULL,
 UNIQUE KEY uq_aiceo_dev_backlog_key (bkey),
 KEY ix_aiceo_dev_backlog_status (status, priority)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
