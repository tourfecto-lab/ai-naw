-- Additive shared source of confirmed business facts. Existing campaigns retained.
CREATE TABLE IF NOT EXISTS business_hub_profiles (
 website_id BIGINT UNSIGNED NOT NULL,
 user_id BIGINT UNSIGNED NOT NULL,
 version INT UNSIGNED NOT NULL DEFAULT 1,
 facts_json LONGTEXT NOT NULL,
 confirmed_at DATETIME NOT NULL,
 created_at DATETIME NOT NULL,
 updated_at DATETIME NOT NULL,
 PRIMARY KEY(website_id),
 KEY ix_business_hub_owner(user_id,updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS social_monthly_autopilot (
 website_id BIGINT UNSIGNED NOT NULL,user_id BIGINT UNSIGNED NOT NULL,source_plan_id BIGINT UNSIGNED NOT NULL,
 enabled TINYINT NOT NULL DEFAULT 0,version INT UNSIGNED NOT NULL DEFAULT 1,max_cycle_price DECIMAL(8,2) NOT NULL DEFAULT 0,
 next_month CHAR(7) NULL,next_due_at DATETIME NULL,last_plan_id BIGINT UNSIGNED NULL,last_error VARCHAR(500) NULL,
 updated_at DATETIME NOT NULL,PRIMARY KEY(website_id),KEY ix_social_auto_owner(user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
