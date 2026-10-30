-- TOURFECTO AI VOICE & SALES AGENT (Phase 7): people a call can be handed to. Additive only.
CREATE TABLE IF NOT EXISTS voice_staff (
 id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
 tenant_user_id INT(11) NOT NULL,
 name VARCHAR(120) NOT NULL,
 phone VARCHAR(20) NOT NULL COMMENT 'E.164',
 topics VARCHAR(80) NOT NULL DEFAULT 'any' COMMENT 'any, or comma separated: sales, booking, support, billing',
 days VARCHAR(20) NOT NULL DEFAULT '1,2,3,4,5,6,7' COMMENT 'ISO weekdays 1=Mon..7=Sun',
 from_time CHAR(5) NOT NULL DEFAULT '00:00',
 to_time CHAR(5) NOT NULL DEFAULT '23:59',
 priority SMALLINT NOT NULL DEFAULT 100 COMMENT 'lower = tried first',
 active TINYINT(1) NOT NULL DEFAULT 1,
 created_at DATETIME NOT NULL,
 updated_at DATETIME NOT NULL,
 KEY idx_voice_staff_tenant (tenant_user_id, active, priority)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
