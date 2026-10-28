-- ============================================================
-- Tourfecto - Migration: Email Compliance (سجل الموافقات + طلبات البيانات)
--   - email_consent_log    إثبات الموافقة لكل مشترك (المصدر/IP/النص/الوقت)
--   - email_data_requests  تدقيق طلبات التصدير/المحو (GDPR) بدون بيانات شخصية (hash فقط)
-- @version 1.0.0  @date 2026-10-19
-- ============================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `email_consent_log` (
    `id` BIGINT(20) NOT NULL AUTO_INCREMENT,
    `user_id` INT(11) NOT NULL,
    `subscriber_id` INT(11) NOT NULL,
    `email` VARCHAR(191) NOT NULL,
    `event` ENUM('subscribed','pending_optin','confirmed','unsubscribed','imported') NOT NULL,
    `source` VARCHAR(50) NULL DEFAULT NULL,
    `list_id` INT(11) NULL DEFAULT NULL,
    `ip` VARCHAR(64) NULL DEFAULT NULL,
    `user_agent` VARCHAR(255) NULL DEFAULT NULL,
    `consent_text` VARCHAR(1000) NULL DEFAULT NULL COMMENT 'نص الموافقة اللي شافه المشترك',
    `created_at` DATETIME NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_ecl_user_sub` (`user_id`, `subscriber_id`),
    KEY `idx_ecl_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='سجل موافقات المشتركين';

CREATE TABLE IF NOT EXISTS `email_data_requests` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `user_id` INT(11) NOT NULL,
    `kind` ENUM('export','erase') NOT NULL,
    `email_hash` CHAR(64) NOT NULL COMMENT 'sha256 للإيميل — لا نخزّن الإيميل نفسه',
    `actor_id` INT(11) NULL DEFAULT NULL,
    `created_at` DATETIME NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_edr_user` (`user_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='تدقيق طلبات GDPR';
