-- ============================================
-- Tourfecto - Migration: GBP Module Upgrade
-- جداول جديدة فقط لدعم Setup Wizard/Sync Engine/Photos/Insights الخاصة
-- بموديول Google Business Profile الموسّع. لا تعديل ولا حذف على أي جدول
-- موجود (platform_connections، reviews، gbp_content، gbp_scheduled_posts
-- بيفضلوا زي ما هما تمامًا - آخر تعديل عليهم كان في
-- 2026_07_14_000008_create_gbp_content_scheduling_tables.sql).
-- @version 1.0.0
-- @date 2026-08-09
-- ============================================

-- ------------------------------------------------------------
-- 1) سجل عمليات المزامنة (Manual/Background Sync) - يدعم قسم "Sync
--    System" و"Events" في الواجهة (Last Sync / Sync Status / Sync Error).
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `gbp_sync_logs` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `website_id` INT(11) NOT NULL,
    `user_id` INT(11) NOT NULL,
    `connection_id` INT(11) NOT NULL COMMENT 'يشير لـ platform_connections.id (platform=google_business)',
    `sync_type` VARCHAR(30) NOT NULL DEFAULT 'manual_sync' COMMENT 'manual_sync, background_sync',
    `status` ENUM('running', 'success', 'failed') NOT NULL DEFAULT 'running',
    `message` TEXT DEFAULT NULL COMMENT 'رسالة الخطأ لو فشلت، أو null لو نجحت',
    `started_at` TIMESTAMP NULL DEFAULT NULL,
    `finished_at` TIMESTAMP NULL DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_gbp_sync_logs_website` (`website_id`),
    KEY `idx_gbp_sync_logs_connection` (`connection_id`),
    KEY `idx_gbp_sync_logs_status` (`status`),
    FOREIGN KEY (`connection_id`) REFERENCES `platform_connections`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='سجل عمليات مزامنة GBP';

-- ------------------------------------------------------------
-- 2) نسخة محلية من بيانات الصور اللي اترفعت فعليًا لجوجل (Media API) -
--    عشان نعرض المعرض بسرعة من غير ما نطلب Google في كل تحميل صفحة.
--    المصدر الحقيقي يفضل جوجل - الجدول ده مجرد Cache/مرجع، مش مصدر بديل.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `gbp_photos` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `website_id` INT(11) NOT NULL,
    `user_id` INT(11) NOT NULL,
    `connection_id` INT(11) NOT NULL,
    `google_media_name` VARCHAR(255) NOT NULL COMMENT 'accounts/{a}/locations/{l}/media/{m} - المرجع الرسمي عند جوجل',
    `category` VARCHAR(30) NOT NULL DEFAULT 'ADDITIONAL',
    `source_url` VARCHAR(500) DEFAULT NULL,
    `thumbnail_url` VARCHAR(500) DEFAULT NULL,
    `is_primary` TINYINT(1) NOT NULL DEFAULT 0,
    `uploaded_by_user_id` INT(11) DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_gbp_photo_media` (`google_media_name`),
    KEY `idx_gbp_photos_website` (`website_id`),
    KEY `idx_gbp_photos_user` (`user_id`),
    FOREIGN KEY (`connection_id`) REFERENCES `platform_connections`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='مرجع محلي لصور GBP المرفوعة فعليًا على Google';

-- ------------------------------------------------------------
-- 3) كاش مؤقت (ساعتين) لمقاييس Business Profile Performance API - تقليل
--    استهلاك الـ quota المحدود لـ Google وتسريع لوحة Analytics/Insights.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `gbp_insights_cache` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `cache_key` VARCHAR(150) NOT NULL COMMENT 'connection_id:start_date:end_date',
    `payload` LONGTEXT NOT NULL COMMENT 'JSON: نتيجة fetchMultiDailyMetricsTimeSeries الحقيقية كاملة',
    `fetched_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_gbp_insights_cache_key` (`cache_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='كاش مؤقت لمقاييس أداء GBP الحقيقية';
