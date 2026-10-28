-- ============================================================
-- Tourfecto - Migration: ترقية خدمة الإعلانات (Ads Pro)
-- @version 1.0.0  @date 2026-10-21
--
-- 1) ad_campaigns.search_assets_json: أصول Google المكتوبة مخصوص لحملات
--    البحث (15 عنوان + 4 أوصاف + Sitelinks + Callouts + Snippet) - بتتولد
--    مرة وتتحفظ عشان العميل يراجعها وتتنشر زي ما هي.
-- 2) ad_search_term_suggestions: اقتراحات الكلمات السلبية من تقرير مصطلحات
--    البحث الحقيقي (cron/run_ads_search_terms.php). UNIQUE على (campaign_id,
--    search_term) عشان الاقتراح اللي العميل رفضه ميرجعش تاني.
-- آمنة للتشغيل أكتر من مرة.
-- ============================================================

SET @__ads_pro_col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ad_campaigns' AND COLUMN_NAME = 'search_assets_json'
);
SET @__ads_pro_col_sql := IF(@__ads_pro_col_exists = 0,
    'ALTER TABLE `ad_campaigns` ADD COLUMN `search_assets_json` MEDIUMTEXT DEFAULT NULL COMMENT ''أصول حملة بحث Google (JSON)''',
    'SELECT 1');
PREPARE __ads_pro_col_stmt FROM @__ads_pro_col_sql;
EXECUTE __ads_pro_col_stmt;
DEALLOCATE PREPARE __ads_pro_col_stmt;

CREATE TABLE IF NOT EXISTS `ad_search_term_suggestions` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `user_id` INT(11) NOT NULL,
    `campaign_id` INT(11) NOT NULL,
    `search_term` VARCHAR(255) NOT NULL,
    `clicks` INT UNSIGNED NOT NULL DEFAULT 0,
    `impressions` INT UNSIGNED NOT NULL DEFAULT 0,
    `cost` DECIMAL(12,2) NOT NULL DEFAULT 0,
    `conversions` DECIMAL(10,2) NOT NULL DEFAULT 0,
    `reason` VARCHAR(20) NOT NULL COMMENT 'wasteful | irrelevant | both',
    `explanation` VARCHAR(500) DEFAULT NULL,
    `status` ENUM('pending','applied','dismissed','failed') NOT NULL DEFAULT 'pending',
    `auto_applied` TINYINT(1) NOT NULL DEFAULT 0,
    `error` VARCHAR(500) DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `decided_at` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_campaign_term` (`campaign_id`, `search_term`),
    INDEX `idx_user_status` (`user_id`, `status`),
    CONSTRAINT `fk_ast_campaign` FOREIGN KEY (`campaign_id`) REFERENCES `ad_campaigns` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_ast_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='اقتراحات الكلمات السلبية من مصطلحات البحث الحقيقية';
