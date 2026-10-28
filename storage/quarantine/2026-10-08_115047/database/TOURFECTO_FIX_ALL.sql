-- =====================================================================
-- Tourfecto - باتش الأعمدة/الجداول الناقصة في قاعدة البيانات
-- مبني على مقارنة: u452576177_AA (26).sql  مقابل  كود tourfecto_pro (24)
-- السيرفر: MariaDB (phpMyAdmin 5.2.2)
-- آمن: ADD COLUMN IF NOT EXISTS / CREATE TABLE IF NOT EXISTS
-- مفيش DROP ولا DELETE، وينفع يتشغّل أكتر من مرة.
-- خُد Backup قبل التشغيل.
-- =====================================================================

SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------
-- 1) billing_profiles  (الجدول موجود فاضي: id, user_id, created_at, updated_at بس)
--    بيستخدمه: app/Models/BillingProfile.php + TaxService (country)
-- ---------------------------------------------------------------------
ALTER TABLE `billing_profiles`
  ADD COLUMN IF NOT EXISTS `legal_name`    VARCHAR(255) NULL DEFAULT NULL AFTER `user_id`,
  ADD COLUMN IF NOT EXISTS `billing_email` VARCHAR(255) NULL DEFAULT NULL AFTER `legal_name`,
  ADD COLUMN IF NOT EXISTS `address_line1` VARCHAR(255) NULL DEFAULT NULL AFTER `billing_email`,
  ADD COLUMN IF NOT EXISTS `address_line2` VARCHAR(255) NULL DEFAULT NULL AFTER `address_line1`,
  ADD COLUMN IF NOT EXISTS `city`          VARCHAR(100) NULL DEFAULT NULL AFTER `address_line2`,
  ADD COLUMN IF NOT EXISTS `country`       VARCHAR(100) NULL DEFAULT NULL AFTER `city`,
  ADD COLUMN IF NOT EXISTS `tax_id`        VARCHAR(100) NULL DEFAULT NULL AFTER `country`;

ALTER TABLE `billing_profiles`
  ADD UNIQUE INDEX IF NOT EXISTS `uq_billing_profiles_user_id` (`user_id`);

-- ---------------------------------------------------------------------
-- 2) workspace_invites  (موجود فاضي: id, owner_user_id, status, created_at بس)
--    بيستخدمه: app/Models/WorkspaceInvite.php + CrmTeamInviteService
-- ---------------------------------------------------------------------
ALTER TABLE `workspace_invites`
  ADD COLUMN IF NOT EXISTS `invited_by`  INT(11) NULL DEFAULT NULL AFTER `owner_user_id`,
  ADD COLUMN IF NOT EXISTS `email`       VARCHAR(190) NULL DEFAULT NULL AFTER `invited_by`,
  ADD COLUMN IF NOT EXISTS `role`        ENUM('admin','manager','sales','support','viewer') NOT NULL DEFAULT 'viewer' AFTER `email`,
  ADD COLUMN IF NOT EXISTS `token`       VARCHAR(64) NULL DEFAULT NULL AFTER `role`,
  ADD COLUMN IF NOT EXISTS `accepted_at` TIMESTAMP NULL DEFAULT NULL AFTER `created_at`,
  ADD COLUMN IF NOT EXISTS `expires_at`  TIMESTAMP NULL DEFAULT NULL AFTER `accepted_at`;

ALTER TABLE `workspace_invites`
  ADD UNIQUE INDEX IF NOT EXISTS `idx_token` (`token`),
  ADD INDEX IF NOT EXISTS `idx_owner_user_id` (`owner_user_id`),
  ADD INDEX IF NOT EXISTS `idx_email` (`email`);

-- ---------------------------------------------------------------------
-- 3) crm_leads  - أعمدة التقييم الإحصائي (CrmLead + CrmLeadScoringStatController)
-- ---------------------------------------------------------------------
ALTER TABLE `crm_leads`
  ADD COLUMN IF NOT EXISTS `conv_probability`   DECIMAL(5,4) NULL DEFAULT NULL AFTER `score`,
  ADD COLUMN IF NOT EXISTS `score_confidence`   ENUM('low','moderate','high') NULL DEFAULT NULL AFTER `conv_probability`,
  ADD COLUMN IF NOT EXISTS `score_signals_json` JSON NULL DEFAULT NULL AFTER `score_confidence`;

-- ---------------------------------------------------------------------
-- 4) crm_contacts  (CrmContact + CrmApiController)
-- ---------------------------------------------------------------------
ALTER TABLE `crm_contacts`
  ADD COLUMN IF NOT EXISTS `company_id` INT(11) NULL DEFAULT NULL AFTER `agency_id`,
  ADD COLUMN IF NOT EXISTS `country`    VARCHAR(100) NULL DEFAULT NULL AFTER `phone`,
  ADD COLUMN IF NOT EXISTS `language`   VARCHAR(10) NULL DEFAULT NULL AFTER `country`,
  ADD COLUMN IF NOT EXISTS `status`     ENUM('active','inactive') NOT NULL DEFAULT 'active' AFTER `source`,
  ADD COLUMN IF NOT EXISTS `tags`       TEXT NULL DEFAULT NULL COMMENT 'JSON array' AFTER `status`;

ALTER TABLE `crm_contacts`
  ADD INDEX IF NOT EXISTS `idx_crm_contacts_company` (`company_id`);

-- ---------------------------------------------------------------------
-- 5) crm_deals  (CrmDeal + CrmDealService)
-- ---------------------------------------------------------------------
ALTER TABLE `crm_deals`
  ADD COLUMN IF NOT EXISTS `company_id`  INT(11) NULL DEFAULT NULL AFTER `contact_id`,
  ADD COLUMN IF NOT EXISTS `pipeline_id` INT(11) NULL DEFAULT NULL COMMENT 'NULL = المسار الافتراضي' AFTER `company_id`,
  ADD COLUMN IF NOT EXISTS `notes`       TEXT NULL DEFAULT NULL AFTER `lost_reason`;

ALTER TABLE `crm_deals`
  ADD INDEX IF NOT EXISTS `idx_crm_deals_company` (`company_id`),
  ADD INDEX IF NOT EXISTS `idx_crm_deals_pipeline` (`pipeline_id`);

-- ---------------------------------------------------------------------
-- 6) crm_meetings  (CrmMeeting)
-- ---------------------------------------------------------------------
ALTER TABLE `crm_meetings`
  ADD COLUMN IF NOT EXISTS `user_id`    INT(11) NULL DEFAULT NULL COMMENT 'صاحب الحساب (Tenant)' AFTER `id`,
  ADD COLUMN IF NOT EXISTS `purpose`    VARCHAR(255) NULL DEFAULT NULL AFTER `title`,
  ADD COLUMN IF NOT EXISTS `location`   VARCHAR(255) NULL DEFAULT NULL AFTER `meeting_link`,
  ADD COLUMN IF NOT EXISTS `timezone`   VARCHAR(60) NULL DEFAULT NULL AFTER `location`,
  ADD COLUMN IF NOT EXISTS `notes`      TEXT NULL DEFAULT NULL AFTER `summary`,
  ADD COLUMN IF NOT EXISTS `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`;

-- نملأ user_id للصفوف القديمة من organizer_user_id
UPDATE `crm_meetings` SET `user_id` = `organizer_user_id`
WHERE `user_id` IS NULL AND `organizer_user_id` IS NOT NULL;

ALTER TABLE `crm_meetings`
  ADD INDEX IF NOT EXISTS `idx_crm_meetings_user` (`user_id`);

-- ---------------------------------------------------------------------
-- 7) crm_pipeline_stages / crm_tasks / crm_notes
-- ---------------------------------------------------------------------
ALTER TABLE `crm_pipeline_stages`
  ADD COLUMN IF NOT EXISTS `description` VARCHAR(500) NULL DEFAULT NULL AFTER `slug`;

ALTER TABLE `crm_tasks`
  ADD COLUMN IF NOT EXISTS `created_by_user_id` INT(11) NULL DEFAULT NULL AFTER `user_id`;

ALTER TABLE `crm_notes`
  ADD COLUMN IF NOT EXISTS `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;

-- ---------------------------------------------------------------------
-- 8) websites  - بيانات الـ Onboarding Wizard (OnboardingController)
--    من غيرهم "العملاء المستهدفين" و"الخدمات الرئيسية" مش بيتحفظوا
-- ---------------------------------------------------------------------
ALTER TABLE `websites`
  ADD COLUMN IF NOT EXISTS `target_customers` TEXT NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `main_services`    TEXT NULL DEFAULT NULL AFTER `target_customers`;

-- ---------------------------------------------------------------------
-- 9) reviews  - ReputationManager بيعمل INSERT في webhook_raw_data
--    (الجدول فيه webhook_payload بس) => Webhooks TripAdvisor/Google بتفشل
-- ---------------------------------------------------------------------
ALTER TABLE `reviews`
  ADD COLUMN IF NOT EXISTS `webhook_raw_data` LONGTEXT NULL DEFAULT NULL AFTER `webhook_payload`;

-- ---------------------------------------------------------------------
-- 10) subscription_plans - sort_order (ترتيب عرض الباقات)
-- ---------------------------------------------------------------------
ALTER TABLE `subscription_plans`
  ADD COLUMN IF NOT EXISTS `sort_order` INT(11) NOT NULL DEFAULT 0 AFTER `is_active`,
  ADD COLUMN IF NOT EXISTS `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`;



-- ---------------------------------------------------------------------
-- 11) users - last_login (كل تسجيل دخول كان بيسجّل خطأ DB، و"آخر دخول" ما بيتحفظش)
-- ---------------------------------------------------------------------
ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `last_login`     TIMESTAMP NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `login_attempts` INT(11) NOT NULL DEFAULT 0 AFTER `last_login`,
  ADD COLUMN IF NOT EXISTS `blocked_until`  TIMESTAMP NULL DEFAULT NULL AFTER `login_attempts`;

-- ---------------------------------------------------------------------
-- 12) users.role - قبول دعوة الفريق بيعمل الحساب بـ role='user' اللي مش موجودة في الـ ENUM
--     => عضو الفريق ما يقدرش يقبل الدعوة خالص
-- ---------------------------------------------------------------------
ALTER TABLE `users`
  MODIFY `role` ENUM('super_admin','admin','agency_owner','manager','agent','client','user') NOT NULL DEFAULT 'client';

-- ---------------------------------------------------------------------
-- 13) crm_tasks.status - الكود بيستخدم open/done، والجدول pending/completed
--     => إضافة أي مهمة من صفحة CRM > المهام كانت بتفشل بـ 500
-- ---------------------------------------------------------------------
ALTER TABLE `crm_tasks`
  MODIFY `status` ENUM('open','pending','in_progress','done','completed','cancelled') NOT NULL DEFAULT 'open';
UPDATE `crm_tasks` SET `status` = 'open' WHERE `status` = 'pending';
UPDATE `crm_tasks` SET `status` = 'done' WHERE `status` = 'completed';
ALTER TABLE `crm_tasks`
  MODIFY `status` ENUM('open','in_progress','done','cancelled') NOT NULL DEFAULT 'open';

-- ---------------------------------------------------------------------
-- 14) crm_meetings.status - "confirmed" بيستخدمها تأكيد المواعيد
-- ---------------------------------------------------------------------
ALTER TABLE `crm_meetings`
  MODIFY `status` ENUM('scheduled','confirmed','completed','cancelled','no_show') NOT NULL DEFAULT 'scheduled';

-- ---------------------------------------------------------------------
-- 15) Ads - أنواع تنبيهات الإعلانات الإبداعية + إجراءات الـ Autopilot
--     (ad_alert_rules اتحدّث، لكن ad_alerts لأ => التنبيه نفسه بيفشل يتسجّل)
-- ---------------------------------------------------------------------
ALTER TABLE `ad_alerts`
  MODIFY `rule_type` ENUM('budget_exhausted','cpc_spike','ctr_drop','landing_page_down','budget_pacing',
                          'creative_underperforming','creative_stale','variant_wasted_spend','ab_test_inconclusive') NOT NULL;
ALTER TABLE `ad_optimization_logs`
  MODIFY `action_type` ENUM('increase_budget','decrease_budget','pause_campaign','add_negative_keywords','add_keywords',
                            'rotate_ad_copy','broaden_audience','narrow_audience','no_action_recommended',
                            'resume_campaign','rollback') NOT NULL;

-- ---------------------------------------------------------------------
-- 16) مراقبة المنافسين - نوع صفحة "careers"
-- ---------------------------------------------------------------------
ALTER TABLE `ci_snapshots`
  MODIFY `page_type` ENUM('homepage','pricing','offers','products','services','blog','landing','contact','sitemap','careers') NOT NULL;
ALTER TABLE `ci_changes`
  MODIFY `page_type` ENUM('homepage','pricing','offers','products','services','blog','landing','contact','sitemap','careers') NOT NULL;

SET FOREIGN_KEY_CHECKS = 1;

-- =====================================================================
-- (اختياري) جداول بيستخدمها كود قديم مش متحمّل فعليًا
-- app/Subscription/UsageTracker.php و app/Security/GDPRCompliance.php
-- النسخ الشغالة فعلاً في app/Services/... وبتستخدم daily_usage_logs (موجود).
-- شيل التعليق لو حابب تضيفهم احتياطيًا.
-- =====================================================================
/*
CREATE TABLE IF NOT EXISTS `usage_tracking` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT(11) NOT NULL,
  `feature` VARCHAR(100) NOT NULL,
  `amount` INT(11) NOT NULL DEFAULT 1,
  `metadata` LONGTEXT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_usage_user_feature_date` (`user_id`, `feature`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `monthly_usage` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT(11) NOT NULL,
  `month` CHAR(7) NOT NULL COMMENT 'YYYY-MM',
  `feature` VARCHAR(100) NOT NULL,
  `total_usage` INT(11) NOT NULL DEFAULT 0,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_monthly_usage` (`user_id`, `month`, `feature`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sessions` (
  `id` VARCHAR(128) NOT NULL,
  `user_id` INT(11) NULL DEFAULT NULL,
  `ip_address` VARCHAR(45) NULL DEFAULT NULL,
  `user_agent` TEXT NULL,
  `payload` LONGTEXT NULL,
  `last_activity` INT(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_sessions_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
*/
