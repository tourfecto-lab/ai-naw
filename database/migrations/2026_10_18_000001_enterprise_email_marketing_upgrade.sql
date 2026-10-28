-- =========================================================================
-- Tourfecto Enterprise Email Marketing Upgrade Migration
-- =========================================================================

-- 1. ترقية جدول إعدادات الـ SMTP لدعم التدوير (Rotation) والتسخين (Warm-up) وفحص الـ DNS
ALTER TABLE `email_smtp_settings`
ADD COLUMN IF NOT EXISTS `daily_limit` INT UNSIGNED NOT NULL DEFAULT 500,
ADD COLUMN IF NOT EXISTS `sent_today` INT UNSIGNED NOT NULL DEFAULT 0,
ADD COLUMN IF NOT EXISTS `warmup_enabled` TINYINT(1) NOT NULL DEFAULT 0,
ADD COLUMN IF NOT EXISTS `warmup_step_increment` INT UNSIGNED NOT NULL DEFAULT 50,
ADD COLUMN IF NOT EXISTS `spf_verified` TINYINT(1) NOT NULL DEFAULT 0,
ADD COLUMN IF NOT EXISTS `dkim_verified` TINYINT(1) NOT NULL DEFAULT 0,
ADD COLUMN IF NOT EXISTS `dmarc_verified` TINYINT(1) NOT NULL DEFAULT 0,
ADD COLUMN IF NOT EXISTS `dkim_selector` VARCHAR(64) DEFAULT 'default',
ADD COLUMN IF NOT EXISTS `health_score` TINYINT UNSIGNED NOT NULL DEFAULT 100,
ADD COLUMN IF NOT EXISTS `bounces_count` INT UNSIGNED NOT NULL DEFAULT 0,
ADD COLUMN IF NOT EXISTS `complaints_count` INT UNSIGNED NOT NULL DEFAULT 0,
ADD COLUMN IF NOT EXISTS `last_used_at` TIMESTAMP NULL DEFAULT NULL;

-- 2. ترقية جدول خطوات الأتمتة لدعم التفرع الشرطي (If/Else Conditional Branching)
ALTER TABLE `email_automation_steps`
ADD COLUMN IF NOT EXISTS `step_type` ENUM('send_email', 'delay', 'condition', 'action_tag', 'webhook') NOT NULL DEFAULT 'send_email',
ADD COLUMN IF NOT EXISTS `condition_rules` JSON NULL,
ADD COLUMN IF NOT EXISTS `true_next_step_id` INT UNSIGNED NULL,
ADD COLUMN IF NOT EXISTS `false_next_step_id` INT UNSIGNED NULL;

-- 3. ترقية جدول المستلمين لتتبع فتح الإنسان مقابل البوتات (Apple MPP / Bot Filtering)
ALTER TABLE `email_campaign_recipients`
ADD COLUMN IF NOT EXISTS `is_human_open` TINYINT(1) NOT NULL DEFAULT 0,
ADD COLUMN IF NOT EXISTS `open_type` VARCHAR(32) NULL DEFAULT NULL,
ADD COLUMN IF NOT EXISTS `open_count` INT UNSIGNED NOT NULL DEFAULT 0,
ADD COLUMN IF NOT EXISTS `click_count` INT UNSIGNED NOT NULL DEFAULT 0,
ADD COLUMN IF NOT EXISTS `user_agent` VARCHAR(512) NULL DEFAULT NULL,
ADD COLUMN IF NOT EXISTS `ip_address` VARCHAR(64) NULL DEFAULT NULL;

-- 4. ترقية جدول المشتركين لتحديد أفضل وقت للإرسال (Smart Send-Time Optimization)
ALTER TABLE `email_subscribers`
ADD COLUMN IF NOT EXISTS `optimal_send_hour` TINYINT UNSIGNED NULL DEFAULT NULL,
ADD COLUMN IF NOT EXISTS `engagement_score` INT UNSIGNED NOT NULL DEFAULT 50,
ADD COLUMN IF NOT EXISTS `last_engaged_at` TIMESTAMP NULL DEFAULT NULL;

-- 5. ترقية جدول الحملات لتتبع العائد المادي المباشر من الحجوزات (Revenue Attribution)
ALTER TABLE `email_campaigns`
ADD COLUMN IF NOT EXISTS `attributed_bookings_count` INT UNSIGNED NOT NULL DEFAULT 0,
ADD COLUMN IF NOT EXISTS `attributed_revenue` DECIMAL(12,2) NOT NULL DEFAULT 0.00;