-- ============================================================
-- Tourfecto optional data backfill: websites -> businesses
-- Run AFTER TOURFECTO_SCHEMA_REPAIR.sql.
-- Non-destructive: only fills websites.business_id when NULL.
-- ============================================================

INSERT INTO `businesses`
    (`owner_user_id`, `legal_name`, `trade_name`, `website_url`, `business_email`, `business_phone`, `country_code`, `city`)
SELECT
    w.`user_id`,
    NULLIF(TRIM(w.`company_name`), ''),
    NULLIF(TRIM(w.`company_name`), ''),
    NULLIF(TRIM(w.`main_url`), ''),
    NULL,
    NULL,
    NULL,
    NULL
FROM `websites` w
WHERE w.`business_id` IS NULL
  AND NOT EXISTS (
      SELECT 1
      FROM `businesses` b
      WHERE b.`owner_user_id` = w.`user_id`
        AND ((b.`website_url` IS NULL AND w.`main_url` IS NULL) OR b.`website_url` = w.`main_url`)
        AND ((b.`trade_name` IS NULL AND w.`company_name` IS NULL) OR b.`trade_name` = w.`company_name`)
  );

UPDATE `websites` w
JOIN `businesses` b
  ON b.`owner_user_id` = w.`user_id`
 AND ((b.`website_url` IS NULL AND w.`main_url` IS NULL) OR b.`website_url` = w.`main_url`)
 AND ((b.`trade_name` IS NULL AND w.`company_name` IS NULL) OR b.`trade_name` = w.`company_name`)
SET w.`business_id` = b.`id`
WHERE w.`business_id` IS NULL;

SELECT COUNT(*) AS `linked_websites_to_businesses`
FROM `websites`
WHERE `business_id` IS NOT NULL;
