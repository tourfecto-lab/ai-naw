-- ============================================================
-- Tourfecto - Migration: Competitor Intelligence - ترتيب الكلمات + أسعار
-- منتجات المنافسين + Battlecards  @date 2026-08-29
--
-- الملف ده كان بالغلط نسخة من migration الـ ad_campaigns (external_adset_id).
-- التعريفات هنا من database/TOURFECTO_SCHEMA_COMPLETE.sql (المطابق للإنتاج).
-- ============================================================

CREATE TABLE IF NOT EXISTS `ci_keyword_rankings` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `competitor_id` int(10) unsigned NOT NULL,
  `keyword` varchar(255) NOT NULL,
  `position` int(11) DEFAULT NULL,
  `url` varchar(500) DEFAULT NULL,
  `source` varchar(60) DEFAULT NULL,
  `checked_at` datetime NOT NULL DEFAULT current_timestamp(),
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_ckr_comp_kw` (`competitor_id`,`keyword`(100),`checked_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ci_product_prices` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `competitor_id` int(10) unsigned NOT NULL,
  `product_name` varchar(255) NOT NULL,
  `price` decimal(12,2) DEFAULT NULL,
  `currency` varchar(10) DEFAULT NULL,
  `source_url` varchar(500) DEFAULT NULL,
  `page_type` varchar(40) DEFAULT NULL,
  `detected_at` datetime NOT NULL DEFAULT current_timestamp(),
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_cpp_comp_prod` (`competitor_id`,`product_name`(100),`detected_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ci_battlecards` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `competitor_id` int(10) unsigned NOT NULL,
  `title` varchar(255) DEFAULT NULL,
  `positioning_summary` text DEFAULT NULL,
  `strengths` longtext DEFAULT NULL,
  `weaknesses` longtext DEFAULT NULL,
  `price_position` longtext DEFAULT NULL,
  `content_position` longtext DEFAULT NULL,
  `recommended_actions` longtext DEFAULT NULL,
  `evidence` longtext DEFAULT NULL,
  `generated_at` datetime NOT NULL DEFAULT current_timestamp(),
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_bc_user_comp` (`user_id`,`competitor_id`,`generated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
