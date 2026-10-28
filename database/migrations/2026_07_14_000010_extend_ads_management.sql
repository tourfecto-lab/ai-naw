-- ============================================================
-- Tourfecto - Migration: توسعة إدارة الإعلانات من ai-ads-management-hub
-- @version 1.0.0  @date 2026-07-14
--
-- ملاحظة: جدول `ad_accounts` الأصلي (اتصال OAuth بمنصات الإعلانات)
-- اتجوهل تمامًا - `platform_connections` الموجود عندك فعلاً (ومُوسَّع
-- مسبقًا ليشمل google_ads/meta_ads) بيغطي نفس الغرض بالظبط.
-- ============================================================

SET @__fx_col_1_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ad_campaigns' AND COLUMN_NAME = 'product_or_service'
);
SET @__fx_col_1_sql := IF(@__fx_col_1_exists = 0,
    'ALTER TABLE `ad_campaigns` ADD COLUMN `product_or_service` TEXT DEFAULT NULL COMMENT ''نص حر يُستخدم كأساس توليد الذكاء الاصطناعي'' AFTER `objective`',
    'SELECT 1');
PREPARE __fx_col_1_stmt FROM @__fx_col_1_sql;
EXECUTE __fx_col_1_stmt;
DEALLOCATE PREPARE __fx_col_1_stmt;

SET @__fx_col_2_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ad_campaigns' AND COLUMN_NAME = 'target_audience_brief'
);
SET @__fx_col_2_sql := IF(@__fx_col_2_exists = 0,
    'ALTER TABLE `ad_campaigns` ADD COLUMN `target_audience_brief` TEXT DEFAULT NULL AFTER `product_or_service`',
    'SELECT 1');
PREPARE __fx_col_2_stmt FROM @__fx_col_2_sql;
EXECUTE __fx_col_2_stmt;
DEALLOCATE PREPARE __fx_col_2_stmt;

SET @__fx_col_3_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ad_campaigns' AND COLUMN_NAME = 'budget_total'
);
SET @__fx_col_3_sql := IF(@__fx_col_3_exists = 0,
    'ALTER TABLE `ad_campaigns` ADD COLUMN `budget_total` DECIMAL(12,2) DEFAULT NULL AFTER `daily_budget`',
    'SELECT 1');
PREPARE __fx_col_3_stmt FROM @__fx_col_3_sql;
EXECUTE __fx_col_3_stmt;
DEALLOCATE PREPARE __fx_col_3_stmt;

SET @__fx_col_4_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ad_campaigns' AND COLUMN_NAME = 'start_date'
);
SET @__fx_col_4_sql := IF(@__fx_col_4_exists = 0,
    'ALTER TABLE `ad_campaigns` ADD COLUMN `start_date` DATE DEFAULT NULL AFTER `budget_total`',
    'SELECT 1');
PREPARE __fx_col_4_stmt FROM @__fx_col_4_sql;
EXECUTE __fx_col_4_stmt;
DEALLOCATE PREPARE __fx_col_4_stmt;

SET @__fx_col_5_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ad_campaigns' AND COLUMN_NAME = 'end_date'
);
SET @__fx_col_5_sql := IF(@__fx_col_5_exists = 0,
    'ALTER TABLE `ad_campaigns` ADD COLUMN `end_date` DATE DEFAULT NULL AFTER `start_date`',
    'SELECT 1');
PREPARE __fx_col_5_stmt FROM @__fx_col_5_sql;
EXECUTE __fx_col_5_stmt;
DEALLOCATE PREPARE __fx_col_5_stmt;

SET @__fx_col_6_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ad_campaigns' AND COLUMN_NAME = 'ai_generated'
);
SET @__fx_col_6_sql := IF(@__fx_col_6_exists = 0,
    'ALTER TABLE `ad_campaigns` ADD COLUMN `ai_generated` TINYINT(1) NOT NULL DEFAULT 0 AFTER `end_date`',
    'SELECT 1');
PREPARE __fx_col_6_stmt FROM @__fx_col_6_sql;
EXECUTE __fx_col_6_stmt;
DEALLOCATE PREPARE __fx_col_6_stmt;

SET @__fx_col_7_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ad_campaigns' AND COLUMN_NAME = 'auto_optimize'
);
SET @__fx_col_7_sql := IF(@__fx_col_7_exists = 0,
    'ALTER TABLE `ad_campaigns` ADD COLUMN `auto_optimize` TINYINT(1) NOT NULL DEFAULT 0 AFTER `ai_generated`',
    'SELECT 1');
PREPARE __fx_col_7_stmt FROM @__fx_col_7_sql;
EXECUTE __fx_col_7_stmt;
DEALLOCATE PREPARE __fx_col_7_stmt;

CREATE TABLE IF NOT EXISTS `ad_copies` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `campaign_id` INT(11) NOT NULL,
    `headline` VARCHAR(255) DEFAULT NULL,
    `description` VARCHAR(500) DEFAULT NULL,
    `primary_text` TEXT DEFAULT NULL COMMENT 'نص أطول لمنصات Meta/LinkedIn/TikTok',
    `call_to_action` VARCHAR(100) DEFAULT NULL,
    `variant_label` VARCHAR(50) DEFAULT NULL COMMENT 'A/B/C لاختبار التنويعات',
    `ai_generated` TINYINT(1) NOT NULL DEFAULT 1,
    `status` ENUM('pending_review','approved','rejected','live') NOT NULL DEFAULT 'pending_review',
    `performance_score` DECIMAL(5,2) DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    FOREIGN KEY (`campaign_id`) REFERENCES `ad_campaigns`(`id`) ON DELETE CASCADE,
    INDEX `idx_campaign_id` (`campaign_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='نصوص إعلانية مولّدة بالذكاء الاصطناعي';

CREATE TABLE IF NOT EXISTS `ad_keywords` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `campaign_id` INT(11) NOT NULL,
    `keyword` VARCHAR(255) NOT NULL,
    `match_type` ENUM('exact','phrase','broad','negative') NOT NULL DEFAULT 'broad',
    `ai_relevance_score` DECIMAL(5,2) DEFAULT NULL,
    `estimated_search_volume` INT(11) DEFAULT NULL,
    `estimated_cpc` DECIMAL(10,2) DEFAULT NULL,
    `ai_generated` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    FOREIGN KEY (`campaign_id`) REFERENCES `ad_campaigns`(`id`) ON DELETE CASCADE,
    INDEX `idx_campaign_id` (`campaign_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='كلمات مفتاحية مستهدفة لحملة إعلانية (بحث مدفوع، تختلف عن tracked_keywords العضوية)';

CREATE TABLE IF NOT EXISTS `ad_audiences` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `campaign_id` INT(11) NOT NULL,
    `name` VARCHAR(255) NOT NULL,
    `age_min` TINYINT(3) UNSIGNED DEFAULT NULL,
    `age_max` TINYINT(3) UNSIGNED DEFAULT NULL,
    `genders` VARCHAR(50) DEFAULT NULL,
    `locations_json` JSON DEFAULT NULL,
    `interests_json` JSON DEFAULT NULL,
    `estimated_reach` BIGINT UNSIGNED DEFAULT NULL,
    `ai_generated` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    FOREIGN KEY (`campaign_id`) REFERENCES `ad_campaigns`(`id`) ON DELETE CASCADE,
    INDEX `idx_campaign_id` (`campaign_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جماهير مستهدفة مقترحة لحملة إعلانية';

CREATE TABLE IF NOT EXISTS `ad_budget_recommendations` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `campaign_id` INT(11) NOT NULL,
    `recommended_daily_budget` DECIMAL(12,2) NOT NULL,
    `bid_strategy` VARCHAR(100) DEFAULT NULL,
    `reasoning` TEXT DEFAULT NULL,
    `confidence_score` DECIMAL(5,2) DEFAULT NULL COMMENT '0-100',
    `applied` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    FOREIGN KEY (`campaign_id`) REFERENCES `ad_campaigns`(`id`) ON DELETE CASCADE,
    INDEX `idx_campaign_id` (`campaign_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='توصيات ميزانية مولّدة بالذكاء الاصطناعي';

CREATE TABLE IF NOT EXISTS `ad_performance_reports` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `campaign_id` INT(11) NOT NULL,
    `date_start` DATE NOT NULL,
    `date_end` DATE NOT NULL,
    `impressions` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `clicks` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `conversions` DECIMAL(12,2) NOT NULL DEFAULT 0,
    `spend` DECIMAL(12,2) NOT NULL DEFAULT 0,
    `revenue` DECIMAL(12,2) DEFAULT NULL,
    `ctr` DECIMAL(6,4) DEFAULT NULL,
    `cpc` DECIMAL(10,4) DEFAULT NULL,
    `roas` DECIMAL(10,4) DEFAULT NULL,
    `synced_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    FOREIGN KEY (`campaign_id`) REFERENCES `ad_campaigns`(`id`) ON DELETE CASCADE,
    INDEX `idx_campaign_dates` (`campaign_id`, `date_start`, `date_end`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='تقارير أداء الحملات - تحتاج مزامنة حقيقية عبر platform_connections لاحقًا';

CREATE TABLE IF NOT EXISTS `ad_optimization_logs` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `campaign_id` INT(11) NOT NULL,
    `action_type` ENUM(
        'increase_budget','decrease_budget','pause_campaign',
        'rotate_ad_copy','add_keywords','add_negative_keywords',
        'narrow_audience','broaden_audience','no_action_recommended'
    ) NOT NULL,
    `description` TEXT NOT NULL,
    `ai_confidence` DECIMAL(5,2) DEFAULT NULL,
    `applied_automatically` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    FOREIGN KEY (`campaign_id`) REFERENCES `ad_campaigns`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='سجل قرارات تحسين الحملات';
