-- SEO launch prerequisites. Apply before the PHP update. Additive/idempotent.
-- Existing prices, inactive pricing switches and all customer records are preserved.
-- ============================================================
-- Tourfecto - Migration: تسعير "ادفع حسب الاستخدام" من المحفظة
-- العميل من غير اشتراك (أو اللي خلّص حد باقته) يقدر يستخدم أي ميزة
-- وتتخصم تلقائيًا من رصيد محفظته بدل ما يتمنع.
-- @version 1.0.0  @date 2026-07-22
-- ============================================================

CREATE TABLE IF NOT EXISTS `pay_per_use_pricing` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `feature_key` VARCHAR(50) NOT NULL COMMENT 'ai_analysis, chat_message, competitor_analysis, review_reply',
    `label` VARCHAR(150) NOT NULL,
    `price` DECIMAL(6,2) NOT NULL DEFAULT 0,
    `currency` VARCHAR(10) NOT NULL DEFAULT 'USD',
    `currency_symbol` VARCHAR(10) NOT NULL DEFAULT '$',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'لو 0، الميزة دي مش متاحة كـ "ادفع حسب الاستخدام" - محتاج اشتراك بس',
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_feature_key` (`feature_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='تسعير الاستخدام الفردي من المحفظة - قابل للتعديل من لوحة الأدمن';

INSERT INTO `pay_per_use_pricing` (`feature_key`, `label`, `price`, `currency`, `currency_symbol`, `is_active`) VALUES
    ('ai_analysis', 'تحليل SEO/AEO/GEO واحد', 2.00, 'USD', '$', 1),
    ('chat_message', 'رد شات واحد', 0.10, 'USD', '$', 1),
    ('competitor_analysis', 'تحليل منافس واحد', 3.00, 'USD', '$', 1),
    ('review_reply', 'رد على مراجعة واحد', 0.50, 'USD', '$', 1),
    ('article_generation', 'توليد مقال تسويقي واحد', 1.50, 'USD', '$', 1)
ON DUPLICATE KEY UPDATE `feature_key` = `feature_key`;

-- Run BEFORE uploading this patch. Idempotent, no existing records changed.
CREATE TABLE IF NOT EXISTS billing_usage_reservations (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 token VARCHAR(64) NOT NULL UNIQUE,
 user_id BIGINT UNSIGNED NOT NULL,
 subscription_id BIGINT UNSIGNED NULL,
 feature VARCHAR(40) NOT NULL,
 quantity INT UNSIGNED NOT NULL,
 source VARCHAR(16) NOT NULL,
 amount DECIMAL(14,2) NOT NULL DEFAULT 0,
 quota_anchor DATETIME NULL,
 state VARCHAR(16) NOT NULL DEFAULT 'reserved',
 created_at DATETIME NOT NULL,
 updated_at DATETIME NOT NULL,
 INDEX billing_reservation_owner (user_id,state),
 INDEX billing_reservation_subscription (subscription_id,state)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS external_execution_attempts (
 operation_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 user_id BIGINT UNSIGNED NOT NULL, operation_label VARCHAR(190) NOT NULL,
 state ENUM('started','completed','failed','uncertain') NOT NULL,
 result_json LONGTEXT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL,
 INDEX(user_id,state,updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS service_usage_reservations (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, token CHAR(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
 user_id BIGINT UNSIGNED NOT NULL, owner_user_id BIGINT UNSIGNED NOT NULL, feature_key VARCHAR(100) NOT NULL,
 source ENUM('plan','wallet') NOT NULL, amount DECIMAL(12,2) NOT NULL DEFAULT 0, usage_event_id BIGINT UNSIGNED NULL,
 state ENUM('reserved','completed','released','uncertain') NOT NULL,
 created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, INDEX(owner_user_id,state)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO pay_per_use_pricing(feature_key,label,price,currency,currency_symbol,is_active) VALUES('seo_data','قياس كلمة SEO',0.05,'USD','$',1) ON DUPLICATE KEY UPDATE feature_key=feature_key;

CREATE TABLE IF NOT EXISTS `seo_report_schedules` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `website_id` INT(11) NOT NULL,
    `user_id` INT(11) NOT NULL,
    `frequency` ENUM('daily','weekly','monthly') NOT NULL DEFAULT 'weekly',
    `weekday` TINYINT(1) DEFAULT NULL COMMENT '0=Sunday ... 6=Saturday (للتقارير الأسبوعية)',
    `hour` TINYINT(2) UNSIGNED NOT NULL DEFAULT 8 COMMENT '0-23',
    `recipient_email` VARCHAR(255) NOT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `last_sent_at` DATETIME DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_seo_rs_website` (`website_id`, `is_active`),
    KEY `idx_seo_rs_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='جدولة تقارير SEO البريدية (G6)';
