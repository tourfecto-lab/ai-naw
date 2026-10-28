-- Phase 5 additive state. Existing impact/report/backlink migrations remain prerequisites.
CREATE TABLE IF NOT EXISTS seo_phase5_claims (claim_key VARCHAR(191) PRIMARY KEY, created_at DATETIME NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS seo_local_profiles (
 website_id INT PRIMARY KEY,user_id INT NOT NULL,profile_json MEDIUMTEXT NOT NULL,updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS seo_local_grid_runs (
 id INT AUTO_INCREMENT PRIMARY KEY,website_id INT NOT NULL,user_id INT NOT NULL,month_start DATE NOT NULL,
 request_hash CHAR(64) NOT NULL,config_json MEDIUMTEXT NOT NULL,status VARCHAR(30) NOT NULL DEFAULT 'queued',created_at DATETIME NOT NULL,
 UNIQUE KEY grid_request(website_id,month_start,request_hash),KEY owner_month(user_id,month_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS seo_local_grid_points (
 run_id INT NOT NULL,keyword_index INT NOT NULL,point_index INT NOT NULL,state VARCHAR(30) NOT NULL,
 rank_position INT NULL,evidence_json TEXT NULL,checked_at DATETIME NULL,PRIMARY KEY point_identity(run_id,keyword_index,point_index)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS seo_citation_tasks (
 id INT AUTO_INCREMENT PRIMARY KEY,website_id INT NOT NULL,user_id INT NOT NULL,source_url VARCHAR(1800) NOT NULL,
 evidence_hash CHAR(64) NOT NULL,evidence_json MEDIUMTEXT NOT NULL,status VARCHAR(30) NOT NULL DEFAULT 'pending',created_at DATETIME NOT NULL,
 UNIQUE KEY citation_evidence(website_id,evidence_hash),KEY owner_tasks(user_id,website_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS seo_link_domain_snapshots (
 website_id INT NOT NULL,user_id INT NOT NULL,target_domain VARCHAR(255) NOT NULL,result_json MEDIUMTEXT NOT NULL,fetched_at DATETIME NOT NULL,
 PRIMARY KEY site_target(website_id,target_domain)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS seo_backlink_evidence (
 backlink_id INT PRIMARY KEY,rel_json TEXT NOT NULL,anchor_text TEXT NULL,target_url VARCHAR(2000) NULL,state VARCHAR(30) NOT NULL,checked_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS seo_auto_impact_monitors (
 website_id INT NOT NULL,user_id INT NOT NULL,task_id INT NOT NULL,window_days SMALLINT NOT NULL,monitor_id INT NOT NULL,
 PRIMARY KEY task_window(website_id,task_id,window_days)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
