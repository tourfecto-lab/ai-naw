-- Additive Tourfecto OTA hub tables. MySQL 8 / InnoDB. Review and test before production.
-- Intentionally prefixed to avoid collisions with existing application tables.
CREATE TABLE IF NOT EXISTS tf_ota_connections (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  website_id BIGINT UNSIGNED NULL,
  platform_key VARCHAR(64) NOT NULL,
  account_id VARCHAR(191) NOT NULL,
  display_name VARCHAR(191) NULL,
  status ENUM('pending','connected','degraded','disconnected','error') NOT NULL DEFAULT 'pending',
  granted_capabilities JSON NULL,
  credential_ref VARCHAR(255) NULL COMMENT 'Reference to secret manager/env key; never store raw tokens here',
  last_sync_at DATETIME NULL,
  last_error_code VARCHAR(80) NULL,
  last_error_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id), UNIQUE KEY uq_tf_ota_account (user_id, platform_key, account_id),
  KEY idx_tf_ota_status (status, platform_key), KEY idx_tf_ota_website (website_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tf_ota_entity_mappings (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  connection_id BIGINT UNSIGNED NOT NULL,
  local_entity_type VARCHAR(40) NOT NULL,
  local_entity_id BIGINT UNSIGNED NOT NULL,
  external_entity_type VARCHAR(40) NOT NULL,
  external_entity_id VARCHAR(191) NOT NULL,
  mapping_status ENUM('unmapped','mapped','conflict','disabled') NOT NULL DEFAULT 'mapped',
  metadata JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id), UNIQUE KEY uq_tf_ota_external (connection_id, external_entity_type, external_entity_id),
  UNIQUE KEY uq_tf_ota_local (connection_id, local_entity_type, local_entity_id),
  CONSTRAINT fk_tf_ota_map_connection FOREIGN KEY (connection_id) REFERENCES tf_ota_connections(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tf_ota_sync_jobs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  connection_id BIGINT UNSIGNED NOT NULL,
  job_type VARCHAR(64) NOT NULL,
  direction ENUM('inbound','outbound','reconcile') NOT NULL,
  idempotency_key CHAR(64) NOT NULL,
  status ENUM('queued','running','succeeded','retry','failed','needs_review') NOT NULL DEFAULT 'queued',
  attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  available_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  started_at DATETIME NULL,
  finished_at DATETIME NULL,
  provider_http_status SMALLINT UNSIGNED NULL,
  error_code VARCHAR(100) NULL,
  safe_error_message VARCHAR(500) NULL,
  payload JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id), UNIQUE KEY uq_tf_ota_job_idempotency (connection_id, idempotency_key),
  KEY idx_tf_ota_job_queue (status, available_at), KEY idx_tf_ota_job_connection (connection_id, created_at),
  CONSTRAINT fk_tf_ota_job_connection FOREIGN KEY (connection_id) REFERENCES tf_ota_connections(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tf_ota_external_bookings (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  connection_id BIGINT UNSIGNED NOT NULL,
  external_reference VARCHAR(191) NOT NULL,
  local_booking_id BIGINT UNSIGNED NULL,
  external_status VARCHAR(40) NOT NULL DEFAULT 'unknown',
  currency CHAR(3) NULL,
  total_amount DECIMAL(12,2) NULL,
  check_in DATE NULL,
  check_out DATE NULL,
  guest_name VARCHAR(191) NULL,
  provider_updated_at DATETIME NULL,
  payload_hash CHAR(64) NULL,
  payload JSON NULL,
  reconciliation_status ENUM('new','matched','conflict','ignored') NOT NULL DEFAULT 'new',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id), UNIQUE KEY uq_tf_ota_booking (connection_id, external_reference),
  KEY idx_tf_ota_booking_local (local_booking_id), KEY idx_tf_ota_booking_status (connection_id, reconciliation_status),
  CONSTRAINT fk_tf_ota_booking_connection FOREIGN KEY (connection_id) REFERENCES tf_ota_connections(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tf_ota_inbox_items (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  connection_id BIGINT UNSIGNED NOT NULL,
  external_conversation_id VARCHAR(191) NOT NULL,
  external_message_id VARCHAR(191) NOT NULL,
  external_booking_reference VARCHAR(191) NULL,
  direction ENUM('inbound','outbound') NOT NULL,
  sender_type VARCHAR(32) NULL,
  body_text TEXT NULL,
  message_status ENUM('received','draft','pending_approval','sent','failed','unsupported') NOT NULL DEFAULT 'received',
  sent_at DATETIME NULL,
  payload JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id), UNIQUE KEY uq_tf_ota_message (connection_id, external_message_id),
  KEY idx_tf_ota_conversation (connection_id, external_conversation_id, created_at),
  CONSTRAINT fk_tf_ota_message_connection FOREIGN KEY (connection_id) REFERENCES tf_ota_connections(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tf_ota_review_items (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  connection_id BIGINT UNSIGNED NOT NULL,
  external_review_id VARCHAR(191) NOT NULL,
  external_booking_reference VARCHAR(191) NULL,
  rating DECIMAL(4,2) NULL,
  rating_scale DECIMAL(4,2) NULL,
  review_title VARCHAR(500) NULL,
  review_text TEXT NULL,
  review_language VARCHAR(16) NULL,
  response_text TEXT NULL,
  response_status ENUM('not_available','not_replied','draft','pending','published','rejected') NOT NULL DEFAULT 'not_available',
  can_reply TINYINT(1) NOT NULL DEFAULT 0,
  reviewed_at DATETIME NULL,
  provider_updated_at DATETIME NULL,
  payload JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id), UNIQUE KEY uq_tf_ota_review (connection_id, external_review_id),
  KEY idx_tf_ota_reviews_pending (connection_id, response_status, reviewed_at),
  CONSTRAINT fk_tf_ota_review_connection FOREIGN KEY (connection_id) REFERENCES tf_ota_connections(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tf_ota_listing_audits (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  connection_id BIGINT UNSIGNED NOT NULL,
  external_listing_id VARCHAR(191) NOT NULL,
  audit_type VARCHAR(64) NOT NULL,
  severity ENUM('info','warning','critical') NOT NULL DEFAULT 'info',
  finding_code VARCHAR(100) NOT NULL,
  finding_text VARCHAR(1000) NOT NULL,
  suggested_fix TEXT NULL,
  applied_status ENUM('suggested','approved','applied','rejected','unsupported') NOT NULL DEFAULT 'suggested',
  evidence JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id), KEY idx_tf_ota_audit_listing (connection_id, external_listing_id, created_at),
  CONSTRAINT fk_tf_ota_audit_connection FOREIGN KEY (connection_id) REFERENCES tf_ota_connections(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Webhook inbox: persist the event before processing and enforce provider-event idempotency.
CREATE TABLE IF NOT EXISTS tf_ota_webhook_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  connection_id BIGINT UNSIGNED NOT NULL,
  event_key CHAR(64) NOT NULL,
  provider_event_id VARCHAR(255) NOT NULL,
  event_type VARCHAR(120) NOT NULL,
  payload JSON NOT NULL,
  status ENUM('received','processing','processed','retry','failed') NOT NULL DEFAULT 'received',
  attempts INT UNSIGNED NOT NULL DEFAULT 0,
  received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  processed_at DATETIME NULL,
  last_error VARCHAR(1000) NULL,
  PRIMARY KEY (id), UNIQUE KEY uq_tf_ota_webhook_event_key (event_key),
  KEY idx_tf_ota_webhook_status_received (status, received_at),
  CONSTRAINT fk_tf_ota_webhook_connection FOREIGN KEY (connection_id) REFERENCES tf_ota_connections(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
