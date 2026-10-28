-- ============================================================================
-- Tourfecto - مخطط الداتابيز الكامل (additive + idempotent) - يضيف فقط الناقص
-- ناتج عن الفرق الحقيقي بين قاعدة الإنتاج (النسخة المرفوعة) والمخطط الكامل لكل migrations المشروع
-- + إصلاحات الخدمات. مفيش DROP/DELETE/TRUNCATE، ممكن يتشغّل أكتر من مرة.
-- يتشغّل مرة واحدة على قاعدة الإنتاج (بعد أخذ نسخة احتياطية).
-- ============================================================================
SET FOREIGN_KEY_CHECKS = 0;

-- جدول جديد: ci_battlecards
CREATE TABLE IF NOT EXISTS `ci_battlecards` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `competitor_id` int(10) unsigned NOT NULL,
  `title` varchar(255) DEFAULT NULL,
  `positioning_summary` text DEFAULT NULL,
  `strengths` longtext DEFAULT NULL,
  `weaknesses` longtext DEFAULT NULL,
  `price_position` longtext DEFAULT NULL,
  `content_position` longtext DEFAULT NULL,
  `recommended_actions` longtext DEFAULT NULL,
  `evidence` longtext DEFAULT NULL,
  `generated_at` datetime NOT NULL DEFAULT current_timestamp(),
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_bc_user_comp` (`user_id`,`competitor_id`,`generated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- جدول جديد: ci_keyword_rankings
CREATE TABLE IF NOT EXISTS `ci_keyword_rankings` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `competitor_id` int(10) unsigned NOT NULL,
  `keyword` varchar(255) NOT NULL,
  `position` int(11) DEFAULT NULL,
  `url` varchar(500) DEFAULT NULL,
  `source` varchar(60) DEFAULT NULL,
  `checked_at` datetime NOT NULL DEFAULT current_timestamp(),
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_ckr_comp_kw` (`competitor_id`,`keyword`(100),`checked_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- جدول جديد: ci_product_prices
CREATE TABLE IF NOT EXISTS `ci_product_prices` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `competitor_id` int(10) unsigned NOT NULL,
  `product_name` varchar(255) NOT NULL,
  `price` decimal(12,2) DEFAULT NULL,
  `currency` varchar(10) DEFAULT NULL,
  `source_url` varchar(500) DEFAULT NULL,
  `page_type` varchar(40) DEFAULT NULL,
  `detected_at` datetime NOT NULL DEFAULT current_timestamp(),
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_cpp_comp_prod` (`competitor_id`,`product_name`(100),`detected_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- جدول جديد: crm_sequence_enrollments
CREATE TABLE IF NOT EXISTS `crm_sequence_enrollments` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `sequence_id` int(10) unsigned NOT NULL,
  `related_type` varchar(40) NOT NULL,
  `related_id` int(10) unsigned NOT NULL,
  `current_step` int(11) NOT NULL DEFAULT 0,
  `next_run_at` datetime DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'active',
  `completed_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_enr_user_status` (`user_id`,`status`,`next_run_at`),
  KEY `idx_enr_entity` (`sequence_id`,`related_type`,`related_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- جدول جديد: crm_sequences
CREATE TABLE IF NOT EXISTS `crm_sequences` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `name` varchar(190) NOT NULL,
  `description` text DEFAULT NULL,
  `steps` longtext DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_seq_user` (`user_id`,`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- جدول جديد: payment_gateway_events
CREATE TABLE IF NOT EXISTS `payment_gateway_events` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `invoice_id` int(11) DEFAULT NULL,
  `transaction_id` varchar(191) DEFAULT NULL,
  `data` longtext DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_pge_invoice` (`invoice_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- جدول جديد: user_permissions
CREATE TABLE IF NOT EXISTS `user_permissions` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `permission` varchar(100) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_perm` (`user_id`,`permission`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- أعمدة ناقصة في ad_campaigns
ALTER TABLE `ad_campaigns`
  ADD COLUMN IF NOT EXISTS `deleted_at` timestamp NULL DEFAULT NULL COMMENT 'Soft delete - Ø§Ù„Ø­Ù…Ù„Ø© Ù…Ø®ÙÙŠØ© Ù…Ù† Ø§Ù„Ù‚ÙˆØ§Ø¦Ù… Ù„ÙƒÙ† Ø¨ÙŠØ§Ù†Ø§ØªÙ‡Ø§ Ø§Ù„ØªØ§Ø±ÙŠØ®ÙŠØ© Ù…Ø­ÙÙˆØ¸Ø© Ø¨Ø§Ù„ÙƒØ§Ù…Ù„';

-- أعمدة ناقصة في ad_copies
ALTER TABLE `ad_copies`
  ADD COLUMN IF NOT EXISTS `ad_group_id` int(11) DEFAULT NULL;

-- أعمدة ناقصة في ad_creative_variants
ALTER TABLE `ad_creative_variants`
  ADD COLUMN IF NOT EXISTS `recorded_on` date DEFAULT NULL COMMENT 'ØªØ§Ø±ÙŠØ® Ø§Ù„Ø£Ø¯Ø§Ø¡ - ÙØªØ±Ø© Ø§Ù„ØªÙ‚Ø§Ø±ÙŠØ± Ø¹Ù„Ù‰ Ù…Ø³ØªÙˆÙ‰ Ø§Ù„Ø¥Ø¹Ù„Ø§Ù†/Ø§Ù„Ù€ variant (Ø¨Ù†Ø¯ 3)';

-- أعمدة ناقصة في ad_keywords
ALTER TABLE `ad_keywords`
  ADD COLUMN IF NOT EXISTS `ad_group_id` int(11) DEFAULT NULL;

-- أعمدة ناقصة في biz_subscriptions
ALTER TABLE `biz_subscriptions`
  ADD COLUMN IF NOT EXISTS `stripe_subscription_id` varchar(100) DEFAULT NULL COMMENT 'Ù…Ø¹Ø±Ù‘Ù Ø§Ø´ØªØ±Ø§Ùƒ Stripe (Ø±Ø¨Ø· idempotent)',
  ADD COLUMN IF NOT EXISTS `customer_email` varchar(255) DEFAULT NULL COMMENT 'Ø¨Ø±ÙŠØ¯ Ø¹Ù…ÙŠÙ„ Stripe (Ø§Ø®ØªÙŠØ§Ø±ÙŠ Ù„Ù„Ø¹Ø±Ø¶)';

-- أعمدة ناقصة في bookings
ALTER TABLE `bookings`
  ADD COLUMN IF NOT EXISTS `attributed_utm_link_id` int(11) DEFAULT NULL COMMENT 'ad_utm_links.id Ù„Ø±Ø§Ø¨Ø· Ø§Ù„Ø¥Ø¹Ù„Ø§Ù† Ø§Ù„Ù„ÙŠ Ø§ØªØ¹Ù…Ù„Ù‡ ÙƒÙ„ÙŠÙƒ Ù‚Ø¨Ù„ Ø§Ù„Ø­Ø¬Ø² (Ù†Ø§ÙØ°Ø© 30 ÙŠÙˆÙ…)';

-- أعمدة ناقصة في ci_changes
ALTER TABLE `ci_changes`
  ADD COLUMN IF NOT EXISTS `price_before` decimal(14,2) DEFAULT NULL COMMENT 'Ø³Ø¹Ø± Ù…Ù‡ÙŠÙƒÙ„ Ù‚Ø¨Ù„ Ø§Ù„ØªØºÙŠÙŠØ± (Ù…Ø³ØªØ®Ø±Ø¬ Ù…Ù† Ù†Øµ Ø§Ù„ØµÙØ­Ø©)',
  ADD COLUMN IF NOT EXISTS `price_after` decimal(14,2) DEFAULT NULL COMMENT 'Ø³Ø¹Ø± Ù…Ù‡ÙŠÙƒÙ„ Ø¨Ø¹Ø¯ Ø§Ù„ØªØºÙŠÙŠØ± (Ù…Ø³ØªØ®Ø±Ø¬ Ù…Ù† Ù†Øµ Ø§Ù„ØµÙØ­Ø©)',
  ADD COLUMN IF NOT EXISTS `currency` varchar(8) DEFAULT NULL COMMENT 'Ø±Ù…Ø²/ÙƒÙˆØ¯ Ø§Ù„Ø¹Ù…Ù„Ø© Ø§Ù„Ù…Ø³ØªØ®Ø±Ø¬ (Ù…Ø«Ø§Ù„: USD / EGP / SAR / AED)';

-- أعمدة ناقصة في ci_snapshots
ALTER TABLE `ci_snapshots`
  ADD COLUMN IF NOT EXISTS `tech_signals` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Ø¥Ø´Ø§Ø±Ø§Øª ØªÙ‚Ù†ÙŠØ© Ø­Ù‚ÙŠÙ‚ÙŠØ© (Server header, X-Powered-By, meta generator) - Not Available Ù„Ùˆ Ù…Ø­ØµÙ„Ø´ Ø£ÙŠ Ø¥Ø´Ø§Ø±Ø©' CHECK (json_valid(`tech_signals`));

-- أعمدة ناقصة في ci_user_preferences
ALTER TABLE `ci_user_preferences`
  ADD COLUMN IF NOT EXISTS `webhook_url` varchar(1000) DEFAULT NULL COMMENT 'Ø±Ø§Ø¨Ø· Webhook Ø¹Ø§Ù… ÙŠØ³ØªÙ‚Ø¨Ù„ JSON Ø¹Ù†Ø¯ ÙƒÙ„ ØªÙ†Ø¨ÙŠÙ‡ (Ù„Ùˆ Ø§Ù„Ù…Ø³ØªØ®Ø¯Ù… Ø§Ø®ØªØ§Ø± Ù‚Ù†Ø§Ø© webhook)',
  ADD COLUMN IF NOT EXISTS `slack_webhook_url` varchar(1000) DEFAULT NULL COMMENT 'Ø±Ø§Ø¨Ø· Slack Incoming Webhook (Ù„Ùˆ Ø§Ù„Ù…Ø³ØªØ®Ø¯Ù… Ø§Ø®ØªØ§Ø± Ù‚Ù†Ø§Ø© slack)',
  ADD COLUMN IF NOT EXISTS `weekly_digest_enabled` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Ø¥Ø±Ø³Ø§Ù„ Ù…Ù„Ø®Øµ Ø£Ø³Ø¨ÙˆØ¹ÙŠ ØªÙ„Ù‚Ø§Ø¦ÙŠ Ø¨Ø§Ù„Ø¥ÙŠÙ…ÙŠÙ„ ÙƒÙ„ ÙŠÙˆÙ… Ø§Ø«Ù†ÙŠÙ†';

-- أعمدة ناقصة في ci_watchlist
ALTER TABLE `ci_watchlist`
  ADD COLUMN IF NOT EXISTS `keyword_filters` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Ù…Ø«Ø§Ù„: ["AI","free trial"] - Ø£ÙŠ ØªØºÙŠÙŠØ± ÙŠØ­ØªÙˆÙŠ Ø¥Ø­Ø¯Ù‰ Ø§Ù„ÙƒÙ„Ù…Ø§Øª Ø¯ÙŠ ÙŠÙˆÙ„Ù‘Ø¯ ØªÙ†Ø¨ÙŠÙ‡ ÙÙˆØ±Ù‹Ø§ Ø¨ØºØ¶ Ø§Ù„Ù†Ø¸Ø± Ø¹Ù† alert_min_severity' CHECK (json_valid(`keyword_filters`));

-- أعمدة ناقصة في crm_contacts
ALTER TABLE `crm_contacts`
  ADD COLUMN IF NOT EXISTS `lifecycle_stage` varchar(50) DEFAULT NULL;

-- أعمدة ناقصة في crm_deals
ALTER TABLE `crm_deals`
  ADD COLUMN IF NOT EXISTS `assigned_rep_id` int(11) DEFAULT NULL COMMENT 'Ù…Ù†Ø¯ÙˆØ¨ Ø§Ù„Ø¨ÙŠØ¹ Ø§Ù„Ù…Ø³Ø¤ÙˆÙ„ Ø¹Ù† Ø§Ù„ØµÙÙ‚Ø© (Sales Attribution)',
  ADD COLUMN IF NOT EXISTS `booking_id` int(11) DEFAULT NULL COMMENT 'ÙŠØ´ÙŠØ± Ø¥Ù„Ù‰ bookings.id - Ø±Ø¨Ø· Ø§Ù„ØµÙÙ‚Ø© Ø¨Ø§Ù„Ø­Ø¬Ø² Ø§Ù„ÙØ¹Ù„ÙŠ';

-- أعمدة ناقصة في crm_leads
ALTER TABLE `crm_leads`
  ADD COLUMN IF NOT EXISTS `interest` varchar(255) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `source` varchar(100) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `priority` varchar(20) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `score_reason` varchar(255) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `value` decimal(12,2) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `currency` char(3) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `next_follow_up_at` datetime DEFAULT NULL;

-- أعمدة ناقصة في crm_meetings
ALTER TABLE `crm_meetings`
  ADD COLUMN IF NOT EXISTS `contact_id` int(10) unsigned DEFAULT NULL;

-- أعمدة ناقصة في crm_notes
ALTER TABLE `crm_notes`
  ADD COLUMN IF NOT EXISTS `user_id` int(10) unsigned DEFAULT NULL;

-- أعمدة ناقصة في crm_pipeline_stages
ALTER TABLE `crm_pipeline_stages`
  ADD COLUMN IF NOT EXISTS `pipeline_id` int(10) unsigned DEFAULT NULL;

-- أعمدة ناقصة في crm_products
ALTER TABLE `crm_products`
  ADD COLUMN IF NOT EXISTS `website_id` int(11) DEFAULT NULL COMMENT 'Ù…Ø¹Ø±Ù‘Ù Ø§Ù„Ù…ÙˆÙ‚Ø¹ Ø§Ù„Ù…ÙˆÙ„Ù‘Ø¯ (generated_websites.id) Ø§Ù„Ù…Ø±ØªØ¨Ø· Ø¨Ø§Ù„Ø¹Ù†ØµØ±',
  ADD COLUMN IF NOT EXISTS `tour_slug` varchar(120) DEFAULT NULL COMMENT 'slug Ø§Ù„Ø±Ø­Ù„Ø©/Ø§Ù„ØºØ±ÙØ© Ø¯Ø§Ø®Ù„ Ø§Ù„Ù…ÙˆÙ‚Ø¹ Ø§Ù„Ù…ÙˆÙ„Ù‘Ø¯ (Website Builder)';

-- أعمدة ناقصة في crm_tasks
ALTER TABLE `crm_tasks`
  ADD COLUMN IF NOT EXISTS `user_id` int(10) unsigned DEFAULT NULL;

-- أعمدة ناقصة في email_campaign_recipients
ALTER TABLE `email_campaign_recipients`
  ADD COLUMN IF NOT EXISTS `sent_at` datetime DEFAULT NULL;

-- أعمدة ناقصة في email_campaigns
ALTER TABLE `email_campaigns`
  ADD COLUMN IF NOT EXISTS `segment_id` int(11) DEFAULT NULL COMMENT 'Ø§Ù„Ø¬Ù…Ù‡ÙˆØ±: Ø´Ø±ÙŠØ­Ø© Ø¯ÙŠÙ†Ø§Ù…ÙŠÙƒÙŠØ© (ØªØºÙ„Ø¨ Ø¹Ù„Ù‰ Ø§Ù„Ù‚ÙˆØ§Ø¦Ù…)';

-- أعمدة ناقصة في email_smtp_settings
ALTER TABLE `email_smtp_settings`
  ADD COLUMN IF NOT EXISTS `delivery_webhook_enabled` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'تفعيل webhook تتبع التسليم (ارتداد/شكوى)',
  ADD COLUMN IF NOT EXISTS `delivery_webhook_secret` varchar(64) DEFAULT NULL COMMENT 'مفتاح سري للتحقق من توقيع webhook التسليم';

-- أعمدة ناقصة في email_subscribers
ALTER TABLE `email_subscribers`
  ADD COLUMN IF NOT EXISTS `complaint_count` int(11) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `bounce_count` int(11) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `optin_token` varchar(64) DEFAULT NULL COMMENT 'توكن تأكيد الاشتراك (Double Opt-In)',
  ADD COLUMN IF NOT EXISTS `engagement_score` int(11) NOT NULL DEFAULT 0 COMMENT 'Ø¯Ø±Ø¬Ø© ØªÙØ§Ø¹Ù„ 0-100 ØªØ­Ø³Ø¨ Ù…Ù† ÙØªØ­/ÙƒÙ„ÙŠÙƒ',
  ADD COLUMN IF NOT EXISTS `optin_ip` varchar(64) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `optin_at` timestamp NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `language` varchar(10) DEFAULT NULL;

-- أعمدة ناقصة في email_templates
ALTER TABLE `email_templates`
  ADD COLUMN IF NOT EXISTS `category` varchar(64) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `share_token` varchar(64) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `is_system` tinyint(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `blocks` mediumtext DEFAULT NULL COMMENT 'JSON بلوكات المحرر المرئي';

-- أعمدة ناقصة في gbp_photos
ALTER TABLE `gbp_photos`
  ADD COLUMN IF NOT EXISTS `error_message` text DEFAULT NULL COMMENT 'Ø±Ø³Ø§Ù„Ø© Ø§Ù„Ø®Ø·Ø£ Ù„Ùˆ ÙØ´Ù„ Ø§Ù„Ø±ÙØ¹ Ø¹Ù„Ù‰ Google';

-- أعمدة ناقصة في gbp_scheduled_posts
ALTER TABLE `gbp_scheduled_posts`
  ADD COLUMN IF NOT EXISTS `queue_job_id` int(11) DEFAULT NULL COMMENT 'ÙŠØ´ÙŠØ± Ù„Ù€ jobs.id - Ù„Ø¥Ù„ØºØ§Ø¡ Ø§Ù„Ù…Ù‡Ù…Ø© Ø§Ù„Ù…Ø¬Ø¯ÙˆÙ„Ø© Ù‚Ø¨Ù„ ØªÙ†ÙÙŠØ°Ù‡Ø§';

-- أعمدة ناقصة في generated_websites
ALTER TABLE `generated_websites`
  ADD COLUMN IF NOT EXISTS `template_id` int(11) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `niche_key` varchar(40) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `logo_url` varchar(255) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `favicon_url` varchar(255) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `seo_title` varchar(160) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `seo_description` varchar(320) DEFAULT NULL;

-- أعمدة ناقصة في invoices
ALTER TABLE `invoices`
  ADD COLUMN IF NOT EXISTS `subtotal` decimal(10,2) DEFAULT NULL COMMENT 'Ø§Ù„Ù…Ø¨Ù„Øº Ù‚Ø¨Ù„ Ø§Ù„Ø¶Ø±ÙŠØ¨Ø©',
  ADD COLUMN IF NOT EXISTS `tax_country` char(2) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `tax_type` varchar(30) DEFAULT NULL COMMENT 'VAT / GST / ... - NULL ÙŠØ¹Ù†ÙŠ Not Configured',
  ADD COLUMN IF NOT EXISTS `tax_amount` decimal(10,2) DEFAULT NULL COMMENT 'Ù…Ø¹Ù„ÙˆÙ…Ø§ØªÙŠ Ø­Ø§Ù„ÙŠÙ‹Ø§ - ØºÙŠØ± Ù…Ø¶Ø§Ù Ù„Ù…Ø¨Ù„Øº amount Ø§Ù„Ù…Ø®ØµÙˆÙ… ÙØ¹Ù„ÙŠÙ‹Ø§';

-- أعمدة ناقصة في payment_transactions
ALTER TABLE `payment_transactions`
  ADD COLUMN IF NOT EXISTS `booking_id` int(11) DEFAULT NULL COMMENT 'Ø±Ø¨Ø· Ø§Ù„Ø¯ÙØ¹ Ø¨Ø§Ù„Ø­Ø¬Ø² (Booking Engine)';

-- أعمدة ناقصة في rev_revenue_records
ALTER TABLE `rev_revenue_records`
  ADD COLUMN IF NOT EXISTS `product_name` varchar(255) DEFAULT NULL COMMENT 'Ø§Ø³Ù… Ø§Ù„Ù…Ù†ØªØ¬/Ø§Ù„Ø®Ø¯Ù…Ø© (Ø§Ø®ØªÙŠØ§Ø±ÙŠ - G2)',
  ADD COLUMN IF NOT EXISTS `category` varchar(100) DEFAULT NULL COMMENT 'ØªØµÙ†ÙŠÙ Ø§Ù„Ù…Ù†ØªØ¬: rooms/tours/transfers/packages/other (Ø§Ø®ØªÙŠØ§Ø±ÙŠ - G2)';

-- أعمدة ناقصة في review_request_settings
ALTER TABLE `review_request_settings`
  ADD COLUMN IF NOT EXISTS `email_subject` varchar(190) DEFAULT NULL COMMENT 'Ø¹Ù†ÙˆØ§Ù† Ø±Ø³Ø§Ù„Ø© Ø§Ù„Ø¥ÙŠÙ…ÙŠÙ„ Ù„Ùˆ channel = email - ÙŠØ¯Ø¹Ù… {name}';

-- أعمدة ناقصة في review_requests
ALTER TABLE `review_requests`
  ADD COLUMN IF NOT EXISTS `attempts` int(11) NOT NULL DEFAULT 0 COMMENT 'Ø¹Ø¯Ø¯ Ù…Ø­Ø§ÙˆÙ„Ø§Øª Ø§Ù„Ø¥Ø±Ø³Ø§Ù„ Ø§Ù„ÙØ¹Ù„ÙŠØ© - ÙŠÙ…Ù†Ø¹ Infinite Retry (Ø§Ù„Ø­Ø¯ Ø§Ù„Ø£Ù‚ØµÙ‰ Ù…ÙØ·Ø¨ÙŽÙ‘Ù‚ ÙÙŠ ÙƒÙˆØ¯ Ø§Ù„Ø®Ø¯Ù…Ø©)',
  ADD COLUMN IF NOT EXISTS `matched_review_id` bigint(20) unsigned DEFAULT NULL COMMENT 'ÙŠØ´ÙŠØ± Ù„Ù€ reviews.id Ø§Ù„ÙØ¹Ù„ÙŠ Ø§Ù„Ù„ÙŠ Ø·Ø§Ø¨Ù‚ Ù‡Ø°Ø§ Ø§Ù„Ø·Ù„Ø¨ (Attribution Ø­Ù‚ÙŠÙ‚ÙŠ)',
  ADD COLUMN IF NOT EXISTS `reviewed_at` datetime DEFAULT NULL COMMENT 'ÙˆÙ‚Øª Ø§ÙƒØªØ´Ø§Ù ØªØ·Ø§Ø¨Ù‚ Ø§Ù„Ù…Ø±Ø§Ø¬Ø¹Ø© ÙØ¹Ù„ÙŠÙ‹Ø§ (Ù„Ø­Ø³Ø§Ø¨ Time-to-Review)';

-- أعمدة ناقصة في reviews
ALTER TABLE `reviews`
  ADD COLUMN IF NOT EXISTS `sentiment_confidence` decimal(5,4) DEFAULT NULL;

-- أعمدة ناقصة في seo_content_campaigns
ALTER TABLE `seo_content_campaigns`
  ADD COLUMN IF NOT EXISTS `target_language` varchar(10) NOT NULL DEFAULT 'ar';

-- أعمدة ناقصة في subscriptions
ALTER TABLE `subscriptions`
  ADD COLUMN IF NOT EXISTS `subscription_id_external` varchar(255) DEFAULT NULL COMMENT 'Ù…Ø¹Ø±Ù Ø§Ù„Ø§Ø´ØªØ±Ø§Ùƒ Ø§Ù„Ø®Ø§Ø±Ø¬ÙŠ',
  ADD COLUMN IF NOT EXISTS `plan_name` varchar(50) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `plan_type` varchar(20) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `price` decimal(10,2) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `currency` char(3) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `last_billed_at` timestamp NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `next_billing_at` timestamp NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `cancelled_at` timestamp NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `cancellation_reason` varchar(255) DEFAULT NULL;

-- أعمدة ناقصة في user_api_keys
ALTER TABLE `user_api_keys`
  ADD COLUMN IF NOT EXISTS `expires_at` timestamp NULL DEFAULT NULL COMMENT 'Ø§Ù†ØªÙ‡Ø§Ø¡ ØµÙ„Ø§Ø­ÙŠØ© Ø§Ø®ØªÙŠØ§Ø±ÙŠ - NULL = Ù„Ø§ ÙŠÙ†ØªÙ‡ÙŠ Ø£Ø¨Ø¯Ù‹Ø§',
  ADD COLUMN IF NOT EXISTS `scopes` text DEFAULT NULL COMMENT 'JSON: Ù‚Ø§Ø¦Ù…Ø© Ø§Ù„ØµÙ„Ø§Ø­ÙŠØ§Øª (NULL = ÙƒÙ„ Ø§Ù„ØµÙ„Ø§Ø­ÙŠØ§Øª)';

-- أعمدة ناقصة في users
ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `notification_preferences` text DEFAULT NULL COMMENT 'JSON: ØªÙØ¶ÙŠÙ„ ØªØ´ØºÙŠÙ„/Ø¥ÙŠÙ‚Ø§Ù Ù„ÙƒÙ„ ÙØ¦Ø© Ø¥Ø´Ø¹Ø§Ø± (reviews, content_publishing, leads, system)',
  ADD COLUMN IF NOT EXISTS `api_key` varchar(80) DEFAULT NULL;

-- أعمدة ناقصة في wallet_transactions
ALTER TABLE `wallet_transactions`
  ADD COLUMN IF NOT EXISTS `feature_key` varchar(50) DEFAULT NULL COMMENT 'feature_key Ù…Ù† pay_per_use_pricing (ai_analysis, chat_message...) - Ø¨ÙŠØ®ØµÙ… Ø¹Ù„ÙŠÙ‡ Ø®ØµÙ… Ø§Ù„Ø§Ø³ØªØ®Ø¯Ø§Ù… Ø§Ù„ÙØ±Ø¯ÙŠ';

-- أعمدة ناقصة في websites
ALTER TABLE `websites`
  ADD COLUMN IF NOT EXISTS `target_languages` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`target_languages`)),
  ADD COLUMN IF NOT EXISTS `auto_fix_enabled` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'ØªÙØ¹ÙŠÙ„ Ø§Ù„ØªÙ†ÙÙŠØ° Ø§Ù„ØªÙ„Ù‚Ø§Ø¦ÙŠ',
  ADD COLUMN IF NOT EXISTS `connected_at` timestamp NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `last_sync_at` timestamp NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `seo_audit_frequency` enum('daily','weekly','monthly') NOT NULL DEFAULT 'weekly',
  ADD COLUMN IF NOT EXISTS `last_seo_audit_at` datetime DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `last_indexnow_at` datetime DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `total_fixes_applied` int(11) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `total_rollbacks` int(11) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `connection_method` enum('script','api','wordpress','shopify') NOT NULL DEFAULT 'script' COMMENT 'Ø·Ø±ÙŠÙ‚Ø© Ø§Ù„Ø±Ø¨Ø·',
  ADD COLUMN IF NOT EXISTS `embed_token` varchar(100) DEFAULT NULL COMMENT 'ØªÙˆÙƒÙ† Ø§Ù„Ø³ÙƒØ±Ø¨Øª Ø§Ù„Ø¹Ø§Ù… - Ø¨ÙŠØªØ­Ø· ÙÙŠ src Ø¨ØªØ§Ø¹ embed.js',
  ADD COLUMN IF NOT EXISTS `embed_api_key` varchar(100) DEFAULT NULL COMMENT 'Ù…ÙØªØ§Ø­ API Ø³Ø±Ù‘ÙŠ Ù„Ù„ØªØ­ÙƒÙ… Ø§Ù„Ø¨Ø±Ù…Ø¬ÙŠ',
  ADD COLUMN IF NOT EXISTS `indexnow_enabled` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'ØªÙØ¹ÙŠÙ„ Ø§Ù„ÙÙ‡Ø±Ø³Ø© Ø§Ù„ÙÙˆØ±ÙŠØ© Ø§Ù„ØªÙ„Ù‚Ø§Ø¦ÙŠØ© Ø¨Ø¹Ø¯ ÙƒÙ„ Ø¥ØµÙ„Ø§Ø­',
  ADD COLUMN IF NOT EXISTS `google_indexing_enabled` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'ØªÙØ¹ÙŠÙ„ Ø§Ù„Ø¥Ø¨Ù„Ø§Øº Ù„Ù€ Google Indexing API',
  ADD COLUMN IF NOT EXISTS `last_google_indexed_at` datetime DEFAULT NULL COMMENT 'Ø¢Ø®Ø± ÙˆÙ‚Øª Ø¥Ø¨Ù„Ø§Øº Ù„Ù€ Google Indexing API',
  ADD COLUMN IF NOT EXISTS `last_rank_tracked_at` datetime DEFAULT NULL COMMENT 'Ø¢Ø®Ø± ÙˆÙ‚Øª ÙØ­Øµ ØªØ±ØªÙŠØ¨ Ø§Ù„ÙƒÙ„Ù…Ø§Øª Ø§Ù„Ù…ÙØªØ§Ø­ÙŠØ© (Rank Tracking)',
  ADD COLUMN IF NOT EXISTS `baidu_token` varchar(255) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `platform_user_id` varchar(100) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `target_country` varchar(100) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `competitor_1_url` varchar(500) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `competitor_2_url` varchar(500) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `competitor_3_url` varchar(500) DEFAULT NULL;

-- أعمدة ناقصة في wo_fixes
ALTER TABLE `wo_fixes`
  ADD COLUMN IF NOT EXISTS `can_auto_apply_external` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Ø§Ù„Ø¥ØµÙ„Ø§Ø­ Ø¯Ù‡ ÙŠÙ†ÙØ¹ ÙŠØªØ­Ù‚Ù† Ø¹Ù„Ù‰ Ù…ÙˆÙ‚Ø¹ Ø®Ø§Ø±Ø¬ÙŠ Ø¹Ø¨Ø± embed.js',
  ADD COLUMN IF NOT EXISTS `field_name` varchar(100) DEFAULT NULL COMMENT 'Ø§Ù„Ø­Ù‚Ù„ Ø§Ù„Ù…Ø³ØªÙ‡Ø¯Ù - seo_title/seo_description/canonical_url/json_ld/llms_txt';

-- ترحيل بيانات صغير (آمن): ملء الأعمدة الجديدة من مصادرها الفعلية
UPDATE `crm_tasks` SET `user_id` = `assigned_to_user_id` WHERE `user_id` IS NULL AND `assigned_to_user_id` IS NOT NULL;
UPDATE `crm_notes` SET `user_id` = `author_user_id` WHERE `user_id` IS NULL AND `author_user_id` IS NOT NULL;


ALTER TABLE `ad_alert_rules` MODIFY COLUMN `rule_type` ENUM('budget_exhausted','cpc_spike','ctr_drop','landing_page_down','budget_pacing','creative_underperforming','creative_stale','variant_wasted_spend','ab_test_inconclusive') NOT NULL;
ALTER TABLE `wallet_transactions` MODIFY COLUMN `type` ENUM('deposit','subscription_charge','refund','admin_adjustment','card_redemption','subscription_credit') NOT NULL DEFAULT 'deposit';
ALTER TABLE `invoices` MODIFY COLUMN `status` ENUM('pending','paid','failed','cancelled','draft','issued','partially_paid','overdue','refunded') NULL DEFAULT 'pending';
ALTER TABLE `email_subscribers` MODIFY COLUMN `status` ENUM('subscribed','unsubscribed','bounced','pending_optin') NOT NULL DEFAULT 'subscribed' COMMENT 'حالة الاشتراك';
ALTER TABLE `reviews` MODIFY COLUMN `source_platform` ENUM('tripadvisor','google_business','booking','trustpilot','custom','expedia','other') NOT NULL;
ALTER TABLE `agency_commissions` MODIFY COLUMN `status` ENUM('pending','paid','voided') NOT NULL DEFAULT 'pending';
ALTER TABLE `ai_reports` MODIFY COLUMN `report_type` ENUM('seo_aeo_geo_full','seo_only','aeo_only','geo_only','seo','aeo','geo','full') NULL DEFAULT 'seo_aeo_geo_full';
ALTER TABLE `ai_articles` MODIFY COLUMN `status` ENUM('generating','completed','failed','scheduled','schedule_failed','published','publish_failed') NOT NULL DEFAULT 'generating';
ALTER TABLE `platform_connections` MODIFY COLUMN `status` ENUM('connected','disconnected','error','token_expired') NOT NULL DEFAULT 'connected';
ALTER TABLE `ci_insights` MODIFY COLUMN `status` ENUM('new','reviewed','dismissed','actioned') NOT NULL DEFAULT 'new';
-- ---- Admin Pro
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

ALTER TABLE `audit_logs`
  ADD INDEX IF NOT EXISTS `idx_audit_created` (`created_at`),
  ADD INDEX IF NOT EXISTS `idx_audit_user` (`user_id`, `created_at`),
  ADD INDEX IF NOT EXISTS `idx_audit_object` (`object_type`, `object_id`);
ALTER TABLE `login_history` ADD INDEX IF NOT EXISTS `idx_lh_status_ip` (`status`, `created_at`, `ip_address`);

SET FOREIGN_KEY_CHECKS = 1;
