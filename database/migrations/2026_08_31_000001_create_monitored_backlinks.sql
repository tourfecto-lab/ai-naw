-- ============================================================
-- Tourfecto - Migration: Backlink Monitoring (Item 2a)
-- @version 1.0.0  @date 2026-08-31
--
-- جدول جديد بالكامل لمراقبة الباك لينكس بعد الحصول عليها فعليًا:
-- يسجّل كل رابط تم الحصول عليه (link_acquired) ويتبعه فحص دوري
-- (أسبوعي) للتأكد إنه لسه حي (live) ولا اتشال (lost). إضافة فقط -
-- لا يغيّر أي جدول أو سلوك قائم.
-- ============================================================

CREATE TABLE IF NOT EXISTS `monitored_backlinks` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT NOT NULL,
    `website_id` BIGINT UNSIGNED NOT NULL COMMENT 'موقع العميل اللي اتعمل ليه الرابط',
    `prospect_id` BIGINT UNSIGNED NULL COMMENT 'المرشّح اللي اتعمل منه الرابط (اختياري)',
    `link_url` VARCHAR(500) NOT NULL COMMENT 'رابط الصفحة اللي فيها الباك لينك',
    `domain` VARCHAR(255) NOT NULL COMMENT 'دومين الموقع المانح للرابط',
    `status` ENUM('pending','live','lost') NOT NULL DEFAULT 'pending' COMMENT 'pending = لسه متفحصش، live = الرابط حي، lost = اتشال',
    `last_checked_at` TIMESTAMP NULL DEFAULT NULL,
    `last_seen_live_at` TIMESTAMP NULL DEFAULT NULL,
    `check_count` INT UNSIGNED NOT NULL DEFAULT 0,
    `last_http_status` SMALLINT NULL,
    `last_error` VARCHAR(500) NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_mb_user` (`user_id`),
    INDEX `idx_mb_website` (`website_id`),
    INDEX `idx_mb_status` (`status`),
    INDEX `idx_mb_prospect` (`prospect_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='باك لينكس قيد المراقبة (حصيلة دورية للحالة)';
