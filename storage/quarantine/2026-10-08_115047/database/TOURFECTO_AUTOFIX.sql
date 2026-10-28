-- ============================================================================
-- Tourfecto - التنفيذ التلقائي للإصلاحات (Tourfecto Edge): جدول واحد لتتبّع اتصال موقع العميل.
-- additive + idempotent. من غيره الميزة شغّالة، بس حالة "متصل" مش هتظهر.
-- ============================================================================
CREATE TABLE IF NOT EXISTS `edge_connections` (
  `website_id` BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  `last_seen_at` TIMESTAMP NULL DEFAULT NULL,
  `hits` INT UNSIGNED NOT NULL DEFAULT 0,
  `rules_version` VARCHAR(20) NULL,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
