CREATE TABLE IF NOT EXISTS registration_admin_alerts (
 user_id BIGINT UNSIGNED NOT NULL,recipient VARCHAR(254) NOT NULL,signup_method VARCHAR(30) NOT NULL,
 status VARCHAR(20) NOT NULL DEFAULT 'queued',error_message VARCHAR(300) NULL,
 created_at DATETIME NOT NULL,updated_at DATETIME NOT NULL,sent_at DATETIME NULL,
 PRIMARY KEY(user_id),KEY ix_signup_alert_status(status,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS login_admin_alerts (
 event_id CHAR(32) NOT NULL,user_id BIGINT UNSIGNED NOT NULL,recipient VARCHAR(254) NOT NULL,
 login_method VARCHAR(30) NOT NULL,occurred_at DATETIME NOT NULL,status VARCHAR(20) NOT NULL DEFAULT 'queued',
 error_message VARCHAR(300) NULL,updated_at DATETIME NOT NULL,sent_at DATETIME NULL,
 PRIMARY KEY(event_id),KEY ix_login_alert_owner(user_id,occurred_at),KEY ix_login_alert_status(status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
