-- Apply once before deploying the PHP files. Safe to re-run; no balances/prices are changed.
CREATE TABLE IF NOT EXISTS whatsapp_webhook_inbox (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  website_id INT NOT NULL,
  user_id INT NOT NULL,
  kind VARCHAR(20) NOT NULL,
  event_key CHAR(64) NOT NULL,
  payload_enc LONGTEXT NULL,
  state VARCHAR(20) NOT NULL DEFAULT 'queued',
  job_id INT NULL,
  attempts INT NOT NULL DEFAULT 0,
  last_error VARCHAR(500) NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  UNIQUE KEY uq_wa_event (website_id,kind,event_key),
  KEY idx_wa_state (state,updated_at),
  KEY idx_wa_owner (user_id,website_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS whatsapp_delivery_statuses (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  website_id INT NOT NULL,
  user_id INT NOT NULL,
  wamid VARCHAR(255) NOT NULL,
  status VARCHAR(20) NOT NULL,
  status_rank INT NOT NULL,
  error_message VARCHAR(500) NULL,
  event_timestamp BIGINT NOT NULL,
  updated_at DATETIME NOT NULL,
  UNIQUE KEY uq_wa_delivery (website_id,wamid),
  KEY idx_wa_delivery_owner (user_id,website_id,updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS billing_usage_reviews (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  reservation_token CHAR(48) NOT NULL,
  admin_id INT NOT NULL,
  previous_state VARCHAR(20) NOT NULL,
  outcome VARCHAR(20) NOT NULL,
  evidence TEXT NOT NULL,
  created_at DATETIME NOT NULL,
  UNIQUE KEY uq_usage_review (reservation_token),
  KEY idx_usage_review_admin (admin_id,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Backfill only absent website limits; retain explicitly configured values, including zero.
UPDATE subscription_plans SET features_json=JSON_SET(COALESCE(features_json,'{}'),'$.multiple_websites',websites_limit)
WHERE JSON_EXTRACT(COALESCE(features_json,'{}'),'$.multiple_websites') IS NULL;

-- All other allowances use the shared catalog defaults. No existing quota or wallet is reset.
