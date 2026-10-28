-- Tourfecto integration foundation (MySQL 8 / MariaDB compatible baseline)
-- Take a database backup before applying. This migration adds new tables only.

CREATE TABLE IF NOT EXISTS integration_sync_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    website_id BIGINT UNSIGNED NOT NULL,
    platform VARCHAR(50) NOT NULL,
    resource_type VARCHAR(40) NOT NULL COMMENT 'reviews, bookings, availability, replies',
    status ENUM('running','success','partial','failed') NOT NULL DEFAULT 'running',
    items_read INT UNSIGNED NOT NULL DEFAULT 0,
    items_created INT UNSIGNED NOT NULL DEFAULT 0,
    items_updated INT UNSIGNED NOT NULL DEFAULT 0,
    items_skipped INT UNSIGNED NOT NULL DEFAULT 0,
    error_code VARCHAR(100) DEFAULT NULL,
    error_message VARCHAR(1000) DEFAULT NULL,
    started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    finished_at DATETIME DEFAULT NULL,
    duration_ms INT UNSIGNED DEFAULT NULL,
    metadata_json JSON DEFAULT NULL,
    PRIMARY KEY (id),
    KEY idx_sync_user_platform (user_id, platform, started_at),
    KEY idx_sync_website_resource (website_id, resource_type, started_at),
    KEY idx_sync_status_started (status, started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS external_booking_bindings (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    website_id BIGINT UNSIGNED NOT NULL,
    connection_id BIGINT UNSIGNED DEFAULT NULL,
    platform VARCHAR(50) NOT NULL,
    external_account_id VARCHAR(255) NOT NULL DEFAULT '',
    external_booking_id VARCHAR(255) NOT NULL,
    booking_id BIGINT UNSIGNED DEFAULT NULL COMMENT 'Internal bookings.id; may be NULL until matched/created',
    external_status VARCHAR(50) DEFAULT NULL,
    payload_hash CHAR(64) DEFAULT NULL,
    last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_external_booking_identity (platform, website_id, external_account_id, external_booking_id),
    KEY idx_external_booking_internal (booking_id),
    KEY idx_external_booking_user_status (user_id, external_status),
    KEY idx_external_booking_connection (connection_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS integration_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    website_id BIGINT UNSIGNED NOT NULL,
    connection_id BIGINT UNSIGNED DEFAULT NULL,
    platform VARCHAR(50) NOT NULL,
    event_type VARCHAR(100) NOT NULL,
    external_event_id VARCHAR(255) NOT NULL,
    idempotency_key CHAR(64) NOT NULL,
    status ENUM('received','processing','processed','retry','failed','ignored') NOT NULL DEFAULT 'received',
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    available_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    processed_at DATETIME DEFAULT NULL,
    last_error VARCHAR(1000) DEFAULT NULL,
    payload_json JSON DEFAULT NULL COMMENT 'Store minimum required fields; remove tokens/payment data before persistence',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_integration_event_key (idempotency_key),
    KEY idx_integration_event_queue (status, available_at),
    KEY idx_integration_event_user (user_id, platform, created_at),
    KEY idx_integration_event_connection (connection_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
