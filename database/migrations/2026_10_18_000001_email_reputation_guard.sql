-- ============================================================
-- Tourfecto - Migration: Email Reputation Guard (حماية السمعة + حدود الباقة)
--
--   - email_account_limits        حالة/حدود الإرسال لكل عميل (override على الافتراضي)
--   - email_send_usage            عدّاد استهلاك بالساعة (منه اليومي والشهري)
--   - email_reputation_events     سجل تدقيق (إيقاف/استئناف/حدود/مراجعة)
--   - email_global_suppressions   حظر عام على مستوى المنصة (إيميل أو دومين)
--   - أعمدة على email_campaign_recipients: محاولات/إعادة محاولة/ارتداد/شكوى
--   - أعمدة على email_campaigns: سبب/وقت الإيقاف
--   - توسيع ENUM الحالة ليغطي القيم المستخدمة فعليًا في الكود
--     (sending/uncertain/partial/paused) — كانت ناقصة في الـ migrations الأصلية.
--
-- كل الأوقات في الجداول الجديدة UTC (يكتبها PHP بـ gmdate).
-- ALTER ... ADD COLUMN: كل عمود في جملة مستقلة عشان لو اتعادت الميجريشن
-- على قاعدة محدّثة يتجاهل المشغّل خطأ Duplicate column من غير ما يوقف الباقي.
-- @version 1.0.0  @date 2026-10-18
-- ============================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `email_account_limits` (
    `user_id` INT(11) NOT NULL,
    `status` ENUM('active','paused','suspended') NOT NULL DEFAULT 'active' COMMENT 'paused = إيقاف مؤقت (يُستأنف)، suspended = إيقاف إداري',
    `pause_reason` VARCHAR(500) NULL DEFAULT NULL,
    `paused_at` DATETIME NULL DEFAULT NULL,
    `paused_by` INT(11) NULL DEFAULT NULL COMMENT 'NULL = النظام (إيقاف تلقائي)',
    `hourly_limit` INT(11) NULL DEFAULT NULL COMMENT 'NULL = الافتراضي/حسب الباقة',
    `daily_limit` INT(11) NULL DEFAULT NULL,
    `monthly_limit` INT(11) NULL DEFAULT NULL,
    `per_minute_limit` INT(11) NULL DEFAULT NULL COMMENT 'سقف المعدل (Throttle) لكل دقيقة',
    `max_bounce_rate` DECIMAL(5,2) NULL DEFAULT NULL COMMENT 'نسبة % للإيقاف التلقائي (NULL = الافتراضي)',
    `max_complaint_rate` DECIMAL(5,3) NULL DEFAULT NULL,
    `warmup_enabled` TINYINT(1) NOT NULL DEFAULT 1,
    `warmup_started_at` DATETIME NULL DEFAULT NULL COMMENT 'يُضبط عند أول إرسال',
    `review_approved_at` DATETIME NULL DEFAULT NULL COMMENT 'موافقة الأدمن على أول حملة',
    `review_approved_by` INT(11) NULL DEFAULT NULL,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`user_id`),
    KEY `idx_eal_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='حدود وحالة إرسال التسويق بالبريد لكل عميل';

CREATE TABLE IF NOT EXISTS `email_send_usage` (
    `user_id` INT(11) NOT NULL,
    `bucket_start` DATETIME NOT NULL COMMENT 'بداية الساعة UTC',
    `sent_count` INT(11) NOT NULL DEFAULT 0,
    PRIMARY KEY (`user_id`, `bucket_start`),
    KEY `idx_esu_bucket` (`bucket_start`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='استهلاك الإرسال بالساعة';

CREATE TABLE IF NOT EXISTS `email_reputation_events` (
    `id` BIGINT(20) NOT NULL AUTO_INCREMENT,
    `user_id` INT(11) NOT NULL,
    `campaign_id` INT(11) NULL DEFAULT NULL,
    `event_type` VARCHAR(40) NOT NULL COMMENT 'account_paused/account_resumed/campaign_paused/limits_changed/...',
    `actor_id` INT(11) NULL DEFAULT NULL COMMENT 'NULL = النظام',
    `details` TEXT NULL DEFAULT NULL COMMENT 'JSON',
    `created_at` DATETIME NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_ere_user_created` (`user_id`, `created_at`),
    KEY `idx_ere_type` (`event_type`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='سجل تدقيق حماية السمعة';

CREATE TABLE IF NOT EXISTS `email_global_suppressions` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `kind` ENUM('email','domain') NOT NULL DEFAULT 'email',
    `value` VARCHAR(191) NOT NULL COMMENT 'lowercase',
    `reason` VARCHAR(255) NULL DEFAULT NULL,
    `created_by` INT(11) NULL DEFAULT NULL,
    `created_at` DATETIME NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_egs_kind_value` (`kind`, `value`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='حظر عام على مستوى المنصة';

ALTER TABLE `email_campaigns` MODIFY COLUMN `status`
    ENUM('draft','scheduled','sending','sent','cancelled','failed','partial','uncertain','paused') NOT NULL DEFAULT 'draft';

ALTER TABLE `email_campaign_recipients` MODIFY COLUMN `status`
    ENUM('pending','sending','sent','opened','clicked','unsubscribed','bounced','failed','uncertain') NOT NULL DEFAULT 'pending';

ALTER TABLE `email_campaigns` ADD COLUMN `paused_reason` VARCHAR(500) NULL DEFAULT NULL;
ALTER TABLE `email_campaigns` ADD COLUMN `paused_at` DATETIME NULL DEFAULT NULL;

ALTER TABLE `email_campaign_recipients` ADD COLUMN `attempts` TINYINT(3) UNSIGNED NOT NULL DEFAULT 0;
ALTER TABLE `email_campaign_recipients` ADD COLUMN `next_attempt_at` DATETIME NULL DEFAULT NULL COMMENT 'UTC - إعادة محاولة مؤجلة';
ALTER TABLE `email_campaign_recipients` ADD COLUMN `error_class` VARCHAR(20) NULL DEFAULT NULL COMMENT 'temporary/invalid_recipient/blocked/permanent/unknown';
ALTER TABLE `email_campaign_recipients` ADD COLUMN `bounced_at` DATETIME NULL DEFAULT NULL;
ALTER TABLE `email_campaign_recipients` ADD COLUMN `complained_at` DATETIME NULL DEFAULT NULL;

ALTER TABLE `email_campaign_recipients` ADD KEY `idx_ecr_campaign_status_next` (`campaign_id`, `status`, `next_attempt_at`);
ALTER TABLE `email_campaign_recipients` ADD KEY `idx_ecr_email` (`email`);
ALTER TABLE `email_subscribers` ADD KEY `idx_es_user_status_email` (`user_id`, `status`, `email`);
