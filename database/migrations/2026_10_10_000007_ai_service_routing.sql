-- Install before replacing PHP files; does not change existing routing.
CREATE TABLE IF NOT EXISTS ai_service_routing (
 id TINYINT NOT NULL PRIMARY KEY, revision INT NOT NULL DEFAULT 0,
 config_json LONGTEXT NOT NULL, updated_by INT NULL, updated_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT INTO ai_service_routing(id,revision,config_json) VALUES(1,0,'{}')
ON DUPLICATE KEY UPDATE id=id;
CREATE TABLE IF NOT EXISTS ai_service_routing_audit (
 id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY, revision INT NOT NULL,
 user_id INT NOT NULL, changes_json LONGTEXT NOT NULL, created_at DATETIME NOT NULL,
 UNIQUE KEY uq_ai_route_revision(revision)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
