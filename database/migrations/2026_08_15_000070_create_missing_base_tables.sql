-- ============================================================
-- Tourfecto - Migration: Missing Base Tables (platform_connections + competitors)
-- @version 1.0.0  @date 2026-08-15
--
-- الهدف: الجدولان `platform_connections` و `competitors` كانا معتمدين على
-- "وجودهما مسبقًا" في السيرفر الحقيقي (موثّق في تعليقات الملف المعلّق
-- _PENDING_TO_RUN_ON_SERVER.sql) لكن لا يوجد لهما CREATE TABLE في أي
-- migration مرقّم في الريبو. النتيجة: على قاعدة بيانات جديدة يعمل بها
-- الموديول الإعلاني (وكذلك موديولات السمعة/المنافسين) لن تعمل.
--
-- الأعمدة هنا مستخلصة بدقة من الاستخدام الفعلي في الكود:
--   - app/Models/PlatformConnection.php (fillable)
--   - app/Models/Competitor.php (fillable + الوصف التوثيقي)
--   - app/Controllers/AdsController.php (INSERT/UPDATE/SELECT على
--     platform_connections: website_id, user_id, platform, access_token,
--     refresh_token, token_expires_at, external_account_id,
--     external_location_id, external_location_name, status, last_error,
--     last_synced_at, connected_at)
--
-- ملاحظة تصميم: لا نضيف FOREIGN KEY حقيقية على user_id/website_id لأن
-- نوع المعرفات الفعلي على السيرفر غير مؤكد (users.id INT في migrations
-- المرقّمة مقابل BIGINT(20) UNSIGNED في الملف المعلّق) - الموديول لا
-- يعتمد على FK في منطق عمله، والجداول بفهارس كافية للاستعلامات.
-- CREATE TABLE IF NOT EXISTS يضمن عدم التكسير لو الجدول موجود فعلًا.
-- ============================================================

-- ------------------------------------------------------------
-- 1) platform_connections - اتصالات OAuth للعملاء بالمنصات
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `platform_connections` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `website_id` INT(11) DEFAULT NULL COMMENT 'الموقع المرتبط (اختياري)',
    `user_id` INT(11) NOT NULL COMMENT 'صاحب الاتصال',
    `platform` VARCHAR(50) NOT NULL COMMENT 'google_business, tripadvisor, facebook, instagram, linkedin, tiktok, twitter_x, google_ads, meta_ads',
    `access_token` TEXT DEFAULT NULL COMMENT 'Token الوصول الحالي',
    `refresh_token` TEXT DEFAULT NULL COMMENT 'Token تجديد الوصول',
    `token_expires_at` DATETIME DEFAULT NULL COMMENT 'موعد انتهاء صلاحية access_token',
    `external_account_id` VARCHAR(255) DEFAULT NULL COMMENT 'معرّف الحساب على المنصة (Google Ads customer_id / Meta ad account id)',
    `external_location_id` VARCHAR(255) DEFAULT NULL COMMENT 'معرّف الموقع على Google Business',
    `external_location_name` VARCHAR(500) DEFAULT NULL COMMENT 'اسم الموقع على Google Business',
    `status` VARCHAR(50) NOT NULL DEFAULT 'pending' COMMENT 'pending, connected, disconnected, error, token_expired',
    `last_error` VARCHAR(1000) DEFAULT NULL,
    `last_synced_at` DATETIME DEFAULT NULL,
    `connected_at` DATETIME DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_user_platform` (`user_id`, `platform`),
    INDEX `idx_website` (`website_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='اتصالات OAuth للمنصات (Google Business / Meta Ads / Google Ads / ...)';

-- ------------------------------------------------------------
-- 2) competitors - قائمة المنافسين
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `competitors` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `website_id` INT(11) DEFAULT NULL COMMENT 'الموقع الذي ينافسه هذا المنافس',
    `user_id` INT(11) NOT NULL COMMENT 'صاحب السجل',
    `competitor_domain` VARCHAR(500) DEFAULT NULL COMMENT 'دومين المنافس (بدون https://)',
    `competitor_name` VARCHAR(255) DEFAULT NULL COMMENT 'اسم المنافس',
    `competitor_tripadvisor_url` VARCHAR(1000) DEFAULT NULL COMMENT 'رابط المنافس على Tripadvisor',
    `notes` TEXT DEFAULT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `competitor_score` DECIMAL(10,2) DEFAULT NULL COMMENT 'درجة المنافس المحسوبة (Phase 7)',
    `my_score` DECIMAL(10,2) DEFAULT NULL COMMENT 'درجة الموقع صاحب السجل',
    `last_analyzed_at` DATETIME DEFAULT NULL COMMENT 'آخر مرة حُلل فيها المنافس',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_website` (`website_id`),
    INDEX `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='المنافسون (يستخدمه موديول الإعلانات + Competitive Intelligence)';
