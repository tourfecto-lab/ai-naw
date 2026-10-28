-- ============================================================
-- Tourfecto - Migration: دمج ملفات phpMyAdmin اليدوية في الـ migrations
-- @date 2026-09-01
--
-- الملفات دي اتشغلت يدويًا على الإنتاج بس، فأي سيرفر جديد كان ناقصه:
--   database/TOURFECTO_ADMIN_PRO.sql, TOURFECTO_ARTICLE_IMAGES.sql,
--   TOURFECTO_AUTOFIX.sql, TOURFECTO_FIX_ENUMS.sql, TOURFECTO_FIX_AI_USAGE_ENUM.sql
-- كل جملة لوحدها و additive (أخطاء "عمود/فهرس موجود" بيتجاهلها الـ runner)،
-- فآمن تتشغل على الإنتاج اللي اتطبق عليه الكلام ده قبل كده.
-- platform_connections.status اتساب VARCHAR عمدًا (أوسع من الـ ENUM).
-- ============================================================

CREATE TABLE IF NOT EXISTS `admin_staff` (
  `user_id` BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  `staff_role` VARCHAR(20) NOT NULL DEFAULT 'admin',
  `extra_permissions` TEXT NULL,
  `previous_role` VARCHAR(30) NULL,
  `created_by` BIGINT UNSIGNED NULL,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `admin_notes` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `author_id` BIGINT UNSIGNED NOT NULL,
  `note` TEXT NOT NULL,
  `is_pinned` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_notes_user` (`user_id`, `is_pinned`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `audit_logs` ADD INDEX `idx_audit_created` (`created_at`);
ALTER TABLE `audit_logs` ADD INDEX `idx_audit_user` (`user_id`, `created_at`);
ALTER TABLE `audit_logs` ADD INDEX `idx_audit_object` (`object_type`, `object_id`);
ALTER TABLE `login_history` ADD INDEX `idx_lh_status_ip` (`status`, `created_at`, `ip_address`);

-- صور المقالات (AIArticle::$fillable)
ALTER TABLE `ai_articles` ADD COLUMN `featured_image_path` VARCHAR(255) NULL;
ALTER TABLE `ai_articles` ADD COLUMN `featured_image_alt` VARCHAR(255) NULL;
ALTER TABLE `ai_articles` ADD COLUMN `featured_image_prompt` TEXT NULL;
ALTER TABLE `ai_articles` ADD COLUMN `wp_media_id` BIGINT UNSIGNED NULL;
ALTER TABLE `ai_articles` ADD COLUMN `image_error` VARCHAR(255) NULL;

-- Tourfecto Edge (التنفيذ التلقائي للإصلاحات)
CREATE TABLE IF NOT EXISTS `edge_connections` (
  `website_id` BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  `last_seen_at` TIMESTAMP NULL DEFAULT NULL,
  `hits` INT UNSIGNED NOT NULL DEFAULT 0,
  `rules_version` VARCHAR(20) NULL,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- قيم ENUM اللي الكود بيكتبها (اتأكدنا إن مفيش قيمة موجودة بتتشال)
ALTER TABLE `ad_alert_rules` MODIFY COLUMN `rule_type` ENUM('budget_exhausted','cpc_spike','ctr_drop','landing_page_down','budget_pacing','creative_underperforming','creative_stale','variant_wasted_spend','ab_test_inconclusive') NOT NULL;
ALTER TABLE `wallet_transactions` MODIFY COLUMN `type` ENUM('deposit','subscription_charge','refund','admin_adjustment','card_redemption','subscription_credit') NOT NULL DEFAULT 'deposit';
ALTER TABLE `invoices` MODIFY COLUMN `status` ENUM('pending','paid','failed','cancelled','draft','issued','partially_paid','overdue','refunded') NULL DEFAULT 'pending';
ALTER TABLE `email_subscribers` MODIFY COLUMN `status` ENUM('subscribed','unsubscribed','bounced','pending_optin') NOT NULL DEFAULT 'subscribed' COMMENT 'حالة الاشتراك';
ALTER TABLE `reviews` MODIFY COLUMN `source_platform` ENUM('tripadvisor','google_business','booking','trustpilot','custom','expedia','other') NOT NULL;
ALTER TABLE `agency_commissions` MODIFY COLUMN `status` ENUM('pending','paid','voided') NOT NULL DEFAULT 'pending';
ALTER TABLE `ai_reports` MODIFY COLUMN `report_type` ENUM('seo_aeo_geo_full','seo_only','aeo_only','geo_only','seo','aeo','geo','full') NULL DEFAULT 'seo_aeo_geo_full';
ALTER TABLE `ai_articles` MODIFY COLUMN `status` ENUM('generating','completed','failed','scheduled','schedule_failed','published','publish_failed') NOT NULL DEFAULT 'generating';
ALTER TABLE `ci_insights` MODIFY COLUMN `status` ENUM('new','reviewed','dismissed','actioned') NOT NULL DEFAULT 'new';
ALTER TABLE `api_usage_logs` MODIFY COLUMN `api_type` ENUM('gemini','openai','deepseek','kimi','whatsapp','tripadvisor','google','stripe','paypal','other','anthropic') NOT NULL;
