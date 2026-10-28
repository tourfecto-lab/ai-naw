-- ============================================================
-- Tourfecto Production Schema Reconciliation Patch
-- Generated from the supplied project migrations vs supplied SQL dump.
-- Non-destructive: CREATE TABLE IF NOT EXISTS + conditional ADD COLUMN only.
-- Does NOT DROP/TRUNCATE/DELETE existing customer data.
-- ============================================================

SET @TOURFECTO_SCHEMA_PATCH_STARTED = UTC_TIMESTAMP();
SET FOREIGN_KEY_CHECKS=0;

-- Missing table: `action_executions`
CREATE TABLE IF NOT EXISTS `action_executions` (
`id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
`user_id` INT NOT NULL,
`action_key` VARCHAR(190) NOT NULL,
`source_type` VARCHAR(20) NULL,
`source_category` VARCHAR(60) NULL,
`affected_area` VARCHAR(120) NULL,
`severity` VARCHAR(10) NULL,
`actions_taken` TEXT NULL,
`created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
KEY `idx_action_exec_user` (user_id, created_at),
KEY `idx_action_exec_key` (action_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='سجلّ تنفيذ إجراءات مركز الإجراءات (dedup + تدقيق)';

-- Missing table: `ad_ad_groups`
CREATE TABLE IF NOT EXISTS `ad_ad_groups` (
`id` INT(11) NOT NULL AUTO_INCREMENT,
`campaign_id` int(11) NOT NULL,
`name` VARCHAR(255) NOT NULL,
`status` ENUM('active','paused') NOT NULL DEFAULT 'active',
`budget_allocation_pct` DECIMAL(5,2) DEFAULT NULL COMMENT 'نسبة تقديرية من ميزانية الحملة، لغرض التنظيم المحلي بس',
`created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
`updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
PRIMARY KEY (`id`),
FOREIGN KEY (`campaign_id`) REFERENCES `ad_campaigns`(`id`) ON DELETE CASCADE,
INDEX `idx_campaign_id` (`campaign_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='مجموعات إعلانية تنظيمية محلية داخل حملة - راجع الملاحظة فوق عن النطاق';

-- Missing table: `ad_alert_rules`
CREATE TABLE IF NOT EXISTS `ad_alert_rules` (
`id` INT(11) NOT NULL AUTO_INCREMENT,
`user_id` bigint(20) UNSIGNED NOT NULL,
`rule_type` ENUM('budget_exhausted','cpc_spike','ctr_drop','landing_page_down','budget_pacing') NOT NULL,
`is_enabled` TINYINT(1) NOT NULL DEFAULT 1,
`threshold_value` DECIMAL(12,2) DEFAULT NULL COMMENT 'نسبة/حد القاعدة (نسبة مئوية) - التفسير خاص بكل قاعدة',
`created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
`updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
PRIMARY KEY (`id`),
UNIQUE KEY `idx_user_rule` (`user_id`, `rule_type`),
FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='قواعد التنبيهات الاستباقية لكل مستخدم';

-- Missing table: `ad_alerts`
CREATE TABLE IF NOT EXISTS `ad_alerts` (
`id` INT(11) NOT NULL AUTO_INCREMENT,
`user_id` bigint(20) UNSIGNED NOT NULL,
`campaign_id` int(11) NOT NULL,
`rule_type` ENUM('budget_exhausted','cpc_spike','ctr_drop','landing_page_down','budget_pacing') NOT NULL,
`severity` ENUM('info','warning','critical') NOT NULL DEFAULT 'warning',
`title` VARCHAR(255) NOT NULL,
`body` TEXT DEFAULT NULL,
`is_read` TINYINT(1) NOT NULL DEFAULT 0,
`is_dismissed` TINYINT(1) NOT NULL DEFAULT 0,
`alert_date` DATE NOT NULL COMMENT 'يوم توليد التنبيه - لمنع التكرار لنفس القاعدة/الحملة',
`created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
PRIMARY KEY (`id`),
UNIQUE KEY `idx_user_campaign_rule_date` (`user_id`, `campaign_id`, `rule_type`, `alert_date`),
FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
FOREIGN KEY (`campaign_id`) REFERENCES `ad_campaigns`(`id`) ON DELETE CASCADE,
INDEX `idx_user_unread` (`user_id`, `is_read`, `is_dismissed`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='تنبيهات استباقية مولّدة من قواعد AdAlertService';

-- Missing table: `ad_autopilot_daily_counters`
CREATE TABLE IF NOT EXISTS `ad_autopilot_daily_counters` (
`user_id` bigint(20) UNSIGNED NOT NULL,
`counter_date` DATE NOT NULL,
`changes_executed` INT(11) NOT NULL DEFAULT 0,
PRIMARY KEY (`user_id`, `counter_date`),
FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='عداد التغييرات التلقائية اليومية لكل مستخدم';

-- Missing table: `ad_autopilot_settings`
CREATE TABLE IF NOT EXISTS `ad_autopilot_settings` (
`id` INT(11) NOT NULL AUTO_INCREMENT,
`user_id` bigint(20) UNSIGNED NOT NULL,
`optimization_mode` ENUM('manual','approval','autopilot') NOT NULL DEFAULT 'manual',
`is_active` TINYINT(1) NOT NULL DEFAULT 1,
`max_daily_budget` DECIMAL(12,2) DEFAULT NULL COMMENT 'سقف الميزانية اليومية لأي حملة - التلقائي مش بيكسره أبدًا',
`max_budget_increase_pct` DECIMAL(5,2) NOT NULL DEFAULT 20.00,
`max_budget_decrease_pct` DECIMAL(5,2) NOT NULL DEFAULT 50.00,
`max_allowed_cpa` DECIMAL(12,2) DEFAULT NULL COMMENT 'سقف تكلفة الاكتساب - تجاوزه = تقليص تلقائي',
`min_required_roas` DECIMAL(10,2) DEFAULT NULL COMMENT 'حد أدنى للعائد على الإنفاق الإعلاني',
`max_changes_per_day` INT(11) NOT NULL DEFAULT 3,
`allowed_campaign_ids_json` JSON DEFAULT NULL,
`allowed_platforms_json` JSON DEFAULT NULL,
`allowed_countries_json` JSON DEFAULT NULL,
`created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
`updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
PRIMARY KEY (`id`),
UNIQUE KEY `idx_user_id` (`user_id`),
FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='إعدادات/حدود الـAutopilot لكل مستخدم';

-- Missing table: `ad_competitor_insights`
CREATE TABLE IF NOT EXISTS `ad_competitor_insights` (
`id` INT(11) NOT NULL AUTO_INCREMENT,
`website_id` bigint(20) UNSIGNED DEFAULT NULL,
`competitor_id` bigint(20) UNSIGNED NOT NULL,
`offer_description` VARCHAR(2000) NOT NULL,
`insights_json` LONGTEXT NOT NULL COMMENT 'الرؤى الكاملة (JSON) - استراتيجيات/نصوص/زوايا مقترحة',
`created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
PRIMARY KEY (`id`),
FOREIGN KEY (`website_id`) REFERENCES `websites`(`id`) ON DELETE SET NULL,
FOREIGN KEY (`competitor_id`) REFERENCES `competitors`(`id`) ON DELETE CASCADE,
INDEX `idx_website` (`website_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='رؤى إعلانية محللة عن منافس معين (أحدث تحليل لكل موقع)';

-- Missing table: `ad_creatives`
CREATE TABLE IF NOT EXISTS `ad_creatives` (
`id` INT(11) NOT NULL AUTO_INCREMENT,
`user_id` bigint(20) UNSIGNED NOT NULL COMMENT 'المالك/التينانت - أساس عزل البيانات',
`campaign_id` int(11) NOT NULL,
`name` VARCHAR(255) NOT NULL COMMENT 'اسم الأصول الإعلانية',
`creative_type` ENUM('text','image','video') NOT NULL DEFAULT 'text',
`headline` VARCHAR(255) DEFAULT NULL COMMENT 'العنوان (يُستخدم لنوع text)',
`primary_text` TEXT DEFAULT NULL COMMENT 'النص الأساسي الطويل (text)',
`media_url` VARCHAR(500) DEFAULT NULL COMMENT 'رابط الصورة/الفيديو (image/video)',
`status` ENUM('active','paused','archived') NOT NULL DEFAULT 'active',
`created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
`updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
PRIMARY KEY (`id`),
FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
FOREIGN KEY (`campaign_id`) REFERENCES `ad_campaigns`(`id`) ON DELETE CASCADE,
INDEX `idx_user_campaign` (`user_id`, `campaign_id`),
INDEX `idx_campaign_type` (`campaign_id`, `creative_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='أصول إعلانية (نص/صورة/فيديو) لكل حملة - بند 1';

-- Missing table: `ad_market_research`
CREATE TABLE IF NOT EXISTS `ad_market_research` (
`id` INT(11) NOT NULL AUTO_INCREMENT,
`user_id` bigint(20) UNSIGNED NOT NULL,
`campaign_id` int(11) DEFAULT NULL,
`goal_description` VARCHAR(2000) NOT NULL,
`result_json` LONGTEXT NOT NULL COMMENT 'نتيجة البحث الكاملة (JSON) - شامل/منافسين/أسعار/اقتراحات',
`created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
PRIMARY KEY (`id`),
FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
FOREIGN KEY (`campaign_id`) REFERENCES `ad_campaigns`(`id`) ON DELETE SET NULL,
INDEX `idx_user_created` (`user_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='تاريخ أبحاث السوق لكل عميل';

-- Missing table: `ad_pending_actions`
CREATE TABLE IF NOT EXISTS `ad_pending_actions` (
`id` INT(11) NOT NULL AUTO_INCREMENT,
`user_id` bigint(20) UNSIGNED NOT NULL,
`campaign_id` int(11) NOT NULL,
`action_type` ENUM('increase_budget','decrease_budget','pause_campaign','resume_campaign') NOT NULL,
`before_value` VARCHAR(100) DEFAULT NULL,
`after_value` VARCHAR(100) DEFAULT NULL,
`reasoning` TEXT DEFAULT NULL,
`confidence_level` VARCHAR(20) DEFAULT NULL,
`blocked_reason` VARCHAR(500) DEFAULT NULL COMMENT 'لو القرار اتجاوز Guardrails - سبب التحويل للموافقة',
`status` ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
`decided_by_user_id` INT(11) DEFAULT NULL,
`decided_at` TIMESTAMP NULL DEFAULT NULL,
`executed_log_id` INT(11) DEFAULT NULL COMMENT 'رقم سجل ad_optimization_logs للتنفيذ الفعلي بعد الموافقة',
`created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
PRIMARY KEY (`id`),
FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
FOREIGN KEY (`campaign_id`) REFERENCES `ad_campaigns`(`id`) ON DELETE CASCADE,
INDEX `idx_user_status` (`user_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='قرارات Autopilot المعلّقة بانتظار موافقة العميل';

-- Missing table: `ad_recommendations`
CREATE TABLE IF NOT EXISTS `ad_recommendations` (
`id` INT(11) NOT NULL AUTO_INCREMENT,
`user_id` bigint(20) UNSIGNED NOT NULL,
`campaign_id` int(11) NOT NULL,
`action` ENUM('increase_budget','decrease_budget','pause_campaign','rotate_creative','start_ab_test','review_targeting','wait') NOT NULL,
`basis` VARCHAR(20) NOT NULL DEFAULT 'statistical' COMMENT 'statistical|rule - تصنيف صريح لنوع الأساس',
`confidence` ENUM('low','moderate','high') DEFAULT NULL COMMENT 'بناءً على كفاية أيام البيانات',
`reason` TEXT NOT NULL COMMENT 'الأسباب بأرقام حقيقية من المزامنة',
`signals` JSON DEFAULT NULL COMMENT 'الإشارات الإحصائية المحسوبة (trends/KPIs)',
`status` ENUM('pending','applied','dismissed') NOT NULL DEFAULT 'pending',
`recommendation_date` DATE NOT NULL,
`created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
PRIMARY KEY (`id`),
UNIQUE KEY `idx_user_campaign_date` (`user_id`, `campaign_id`, `recommendation_date`),
FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
FOREIGN KEY (`campaign_id`) REFERENCES `ad_campaigns`(`id`) ON DELETE CASCADE,
INDEX `idx_user_status` (`user_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='توصيات الخطوة التالية للحملات - بند 5 (اقتراحات فقط، لا تنفيذ تلقائي)';

-- Missing table: `ad_team_members`
CREATE TABLE IF NOT EXISTS `ad_team_members` (
`id` INT(11) NOT NULL AUTO_INCREMENT,
`owner_user_id` bigint(20) UNSIGNED NOT NULL COMMENT 'صاحب حساب الإعلانات الأصلي',
`member_user_id` bigint(20) UNSIGNED NOT NULL COMMENT 'العضو المُضاف - لازم يكون له حساب Tourfecto بالفعل',
`role` ENUM('viewer','manager','admin') NOT NULL DEFAULT 'viewer' COMMENT 'viewer=عرض فقط، manager=إدارة الحملات، admin=إدارة كاملة شاملة الربط/الإعدادات',
`invited_by_user_id` INT(11) NOT NULL,
`status` ENUM('active','removed') NOT NULL DEFAULT 'active',
`created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
`updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
PRIMARY KEY (`id`),
UNIQUE KEY `idx_owner_member` (`owner_user_id`, `member_user_id`),
FOREIGN KEY (`owner_user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
FOREIGN KEY (`member_user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
INDEX `idx_member_user_id` (`member_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='أعضاء فريق موديول الإعلانات - Viewer/Manager/Admin على حساب مالك واحد';

-- Missing table: `ad_utm_links`
CREATE TABLE IF NOT EXISTS `ad_utm_links` (
`id` INT(11) NOT NULL AUTO_INCREMENT,
`campaign_id` int(11) NOT NULL,
`code` VARCHAR(20) NOT NULL COMMENT 'كود قصير فريد يستخدم في /r/{code}',
`destination_url` VARCHAR(1000) NOT NULL,
`utm_source` VARCHAR(100) DEFAULT NULL,
`utm_medium` VARCHAR(100) DEFAULT NULL,
`utm_campaign` VARCHAR(100) DEFAULT NULL,
`utm_content` VARCHAR(255) DEFAULT NULL,
`utm_term` VARCHAR(255) DEFAULT NULL,
`clicks` INT(11) NOT NULL DEFAULT 0 COMMENT 'عدد النقرات الحقيقية المسجلة',
`created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
`updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
PRIMARY KEY (`id`),
UNIQUE KEY `idx_code` (`code`),
FOREIGN KEY (`campaign_id`) REFERENCES `ad_campaigns`(`id`) ON DELETE CASCADE,
INDEX `idx_campaign_id` (`campaign_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='روابط UTM قصيرة للحملات الإعلانية مع تتبع النقرات';

-- Missing table: `agency_invitations`
CREATE TABLE IF NOT EXISTS `agency_invitations` (
`id` INT(11) NOT NULL AUTO_INCREMENT,
`agency_id` int(11) NOT NULL COMMENT 'يشير إلى agencies.id',
`email` VARCHAR(255) NOT NULL COMMENT 'بريد العميل المدعو (لازم يكون مستخدمًا حقيقيًا في users)',
`token` VARCHAR(64) NOT NULL COMMENT 'رمز فريد لرابط القبول',
`commission_rate` DECIMAL(5,2) NOT NULL DEFAULT 10.00 COMMENT 'نسبة عمولة الوكالة من حجوزات هذا العميل (%) تُطبَّق عند القبول',
`invited_by` bigint(20) UNSIGNED NULL COMMENT 'يشير إلى users.id - الوكيل اللي أنشأ الدعوة',
`status` ENUM('pending','accepted','revoked') NOT NULL DEFAULT 'pending',
`expires_at` DATETIME NULL COMMENT 'موعد انتهاء صلاحية الدعوة',
`accepted_at` DATETIME NULL DEFAULT NULL,
`created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
`updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
PRIMARY KEY (`id`),
UNIQUE KEY `uniq_agency_invitations_token` (`token`),
KEY `idx_agency_invitations_agency` (`agency_id`),
KEY `idx_agency_invitations_email` (`email`),
KEY `idx_agency_invitations_status` (`status`),
CONSTRAINT `fk_agency_invitations_agency` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
CONSTRAINT `fk_agency_invitations_invited_by` FOREIGN KEY (`invited_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='دعوات عملاء الوكالات White-Label';

-- Missing table: `ai_knowledge_gaps`
CREATE TABLE IF NOT EXISTS `ai_knowledge_gaps` (
`id` INT(11) NOT NULL AUTO_INCREMENT,
`website_id` bigint(20) UNSIGNED NOT NULL,
`conversation_id` INT(11) DEFAULT NULL COMMENT 'لضمان عدم تكرار نفس المحادثة',
`question` TEXT NOT NULL COMMENT 'آخر رسالة للعميل قبل التحويل (ما لم يستطع الـAI الإجابة عنه)',
`normalized_question` VARCHAR(500) NOT NULL COMMENT 'السؤال بعد التسوية (حروف صغيرة + إزالة علامات الترقيم) للتجميع',
`language` VARCHAR(10) DEFAULT NULL,
`handoff_reason` VARCHAR(100) DEFAULT NULL,
`occurrence_count` INT(11) NOT NULL DEFAULT 1 COMMENT 'عدد المحادثات المختلفة التي طرحت نفس السؤال',
`status` ENUM('new', 'acknowledged', 'added_to_kb', 'dismissed') NOT NULL DEFAULT 'new',
`last_seen_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
`created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
`updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
PRIMARY KEY (`id`),
FOREIGN KEY (`website_id`) REFERENCES `websites`(`id`) ON DELETE CASCADE,
UNIQUE KEY `uniq_website_conversation` (`website_id`, `conversation_id`),
INDEX `idx_website_status` (`website_id`, `status`),
INDEX `idx_website_occurrences` (`website_id`, `occurrence_count`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Learning Loop - أسئلة لم يستطع الـAI الإجابة عنها (فجوات معرفة)';

-- Missing table: `ai_quotes`
CREATE TABLE IF NOT EXISTS `ai_quotes` (
`id` INT(11) NOT NULL AUTO_INCREMENT,
`website_id` INT(11) NOT NULL,
`conversation_id` INT(11) DEFAULT NULL,
`lead_id` INT(11) DEFAULT NULL,
`quote_number` VARCHAR(30) DEFAULT NULL COMMENT 'رقم مرجعي بشري للعرض',
`customer_name` VARCHAR(255) DEFAULT NULL,
`customer_phone` VARCHAR(50) DEFAULT NULL,
`customer_email` VARCHAR(191) DEFAULT NULL,
`channel` VARCHAR(30) DEFAULT NULL COMMENT 'القناة اللي اتُبعتها عليها',
`items` JSON DEFAULT NULL COMMENT '[{name, qty, unit_price, line_total, notes}]',
`subtotal` DECIMAL(12,2) DEFAULT 0,
`discount` DECIMAL(12,2) DEFAULT 0,
`total` DECIMAL(12,2) DEFAULT 0,
`currency` VARCHAR(10) DEFAULT 'USD',
`status` ENUM('draft','sent','accepted','declined','expired','cancelled') DEFAULT 'draft',
`notes` TEXT DEFAULT NULL COMMENT 'ملاحظات داخلية للموظف',
`customer_message` TEXT DEFAULT NULL COMMENT 'رسالة العرض اللي اتُبعتت للعميل',
`sent_at` DATETIME DEFAULT NULL,
`responded_at` DATETIME DEFAULT NULL,
`created_by_user_id` INT(11) DEFAULT NULL,
`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
`updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
PRIMARY KEY (`id`),
KEY `idx_ai_quotes_website` (`website_id`),
KEY `idx_ai_quotes_conversation` (`conversation_id`),
KEY `idx_ai_quotes_lead` (`lead_id`),
KEY `idx_ai_quotes_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Missing table: `ai_resolution_events`
CREATE TABLE IF NOT EXISTS `ai_resolution_events` (
`id` INT(11) NOT NULL AUTO_INCREMENT,
`website_id` bigint(20) UNSIGNED NOT NULL,
`conversation_id` INT(11) DEFAULT NULL,
`channel` VARCHAR(30) DEFAULT NULL COMMENT 'website_chat/whatsapp/messenger/instagram/email',
`language` VARCHAR(10) DEFAULT NULL,
`outcome` ENUM('ai_resolved', 'human_resolved', 'abandoned', 'reopened') NOT NULL,
`handoff_reason` VARCHAR(100) DEFAULT NULL COMMENT 'سبب التحويل لو outcome=human_resolved',
`ai_confidence_score` DECIMAL(3,2) DEFAULT NULL COMMENT 'آخر ثقة للرد الآلي في المحادثة',
`resolved_at` TIMESTAMP NULL DEFAULT NULL,
`created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
PRIMARY KEY (`id`),
FOREIGN KEY (`website_id`) REFERENCES `websites`(`id`) ON DELETE CASCADE,
INDEX `idx_website_created` (`website_id`, `created_at`),
INDEX `idx_outcome` (`outcome`),
INDEX `idx_conversation` (`conversation_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Learning Loop - نتيجة كل محادثة (هل حلها الـAI فعلاً؟)';

-- Missing table: `auto_seo_applied_fixes`
CREATE TABLE IF NOT EXISTS `auto_seo_applied_fixes` (
`id` INT(11) NOT NULL AUTO_INCREMENT,
`website_id` bigint(20) UNSIGNED NOT NULL,
`user_id` bigint(20) UNSIGNED NOT NULL,
`fix_id` INT(11) DEFAULT NULL COMMENT 'wo_fixes.id لو الإصلاح جاي من Website Optimizer',
`finding_id` INT(11) DEFAULT NULL COMMENT 'wo_audit_findings.id',
`category` VARCHAR(50) NOT NULL COMMENT 'seo|aeo|geo|speed|security|mobile|accessibility',
`check_key` VARCHAR(100) DEFAULT NULL COMMENT 'مفتاح الفحص - title_tag/meta_description_missing/llms_txt_missing...',
`field_name` VARCHAR(100) NOT NULL COMMENT 'الحقل المستهدف في الصفحة',
`injected_code` TEXT NOT NULL COMMENT 'الكود اللي هيتحقن فعليًا في <head>',
`is_active` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '0 = اتعمله rollback فمابيتحقنش',
`created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
`updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
PRIMARY KEY (`id`),
FOREIGN KEY (`website_id`) REFERENCES `websites`(`id`) ON DELETE CASCADE,
FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
INDEX `idx_website_active` (`website_id`, `is_active`),
INDEX `idx_check_key` (`check_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='الإصلاحات المحقونة على المواقع الخارجية عبر embed.js';

-- Missing table: `auto_seo_change_log`
CREATE TABLE IF NOT EXISTS `auto_seo_change_log` (
`id` INT(11) NOT NULL AUTO_INCREMENT,
`website_id` bigint(20) UNSIGNED NOT NULL,
`user_id` bigint(20) UNSIGNED NOT NULL,
`audit_id` INT(11) DEFAULT NULL,
`finding_id` INT(11) DEFAULT NULL,
`applied_fix_id` INT(11) DEFAULT NULL,
`field_name` VARCHAR(100) NOT NULL,
`old_value` TEXT DEFAULT NULL COMMENT 'القيمة القديمة قبل الحقن - أساس الـRollback',
`new_value` TEXT NOT NULL,
`trigger` ENUM('manual_click','audit_auto_pilot','scheduled_cron') NOT NULL DEFAULT 'manual_click',
`mode` ENUM('off','conservative','balanced','aggressive') NOT NULL DEFAULT 'conservative',
`rolled_back_at` TIMESTAMP NULL DEFAULT NULL,
`created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
PRIMARY KEY (`id`),
FOREIGN KEY (`website_id`) REFERENCES `websites`(`id`) ON DELETE CASCADE,
FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
INDEX `idx_website_created` (`website_id`, `created_at`),
INDEX `idx_rolled_back` (`rolled_back_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='سجل تغييرات Auto SEO الخارجي مع دعم Rollback';

-- Missing table: `biz_subscriptions`
CREATE TABLE IF NOT EXISTS `biz_subscriptions` (
`id` INT(11) NOT NULL AUTO_INCREMENT,
`user_id` bigint(20) UNSIGNED NOT NULL COMMENT 'صاحب الحساب (Tenant Isolation)',
`contact_id` INT(11) DEFAULT NULL COMMENT 'ربط اختياري بجهة اتصال CRM (لو متاحة)',
`customer_name` VARCHAR(255) NOT NULL COMMENT 'اسم العميل المشترك',
`plan_name` VARCHAR(255) NOT NULL COMMENT 'اسم الباقة (Basic/Pro/...)',
`status` ENUM('active', 'trialing', 'past_due', 'cancelled', 'expired') NOT NULL DEFAULT 'active',
`billing_cycle` ENUM('monthly', 'quarterly', 'yearly') NOT NULL DEFAULT 'monthly',
`mrr` DECIMAL(14,2) NOT NULL DEFAULT 0 COMMENT 'قيمة شهرية مكافئة للاشتراك (Monthly Recurring Revenue)',
`currency` VARCHAR(10) NOT NULL DEFAULT 'USD',
`started_at` DATE NOT NULL,
`current_period_end` DATE DEFAULT NULL,
`cancelled_at` DATE DEFAULT NULL,
`churn_reason` VARCHAR(255) DEFAULT NULL COMMENT 'سبب انسحاب العميل (لو مسجل)',
`created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
`updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
PRIMARY KEY (`id`),
INDEX `idx_biz_sub_user_status` (`user_id`, `status`),
INDEX `idx_biz_sub_contact` (`contact_id`),
INDEX `idx_biz_sub_started` (`user_id`, `started_at`),
CONSTRAINT `fk_biz_sub_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='اشتراكات عملاء العميل (أساس MRR/ARR/NRR/GRR الحقيقية)';

-- Missing table: `businesses`
CREATE TABLE IF NOT EXISTS `businesses` (
`id` INT(11) NOT NULL AUTO_INCREMENT,
`owner_user_id` bigint(20) UNSIGNED NOT NULL,
-- Identity
    `legal_name` VARCHAR(255) DEFAULT NULL COMMENT 'الاسم القانوني/التجاري المسجل',
`trade_name` VARCHAR(255) DEFAULT NULL COMMENT 'الاسم التجاري المستخدم فعليًا',
`logo_url` VARCHAR(500) DEFAULT NULL,
`description` TEXT DEFAULT NULL,
-- Contact
    `website_url` VARCHAR(500) DEFAULT NULL,
`business_email` VARCHAR(255) DEFAULT NULL,
`business_phone` VARCHAR(50) DEFAULT NULL,
`whatsapp_number` VARCHAR(50) DEFAULT NULL,
-- Address (Primary/HQ - locations إضافية في جدول business_locations منفصل، Phase 3)
    `country_code` VARCHAR(2) DEFAULT NULL COMMENT 'ISO 3166-1 alpha-2',
`city` VARCHAR(150) DEFAULT NULL,
`address` VARCHAR(500) DEFAULT NULL,
`postal_code` VARCHAR(20) DEFAULT NULL,
-- Legal/Tourism specific
    `tourism_license_number` VARCHAR(100) DEFAULT NULL,
`tax_number` VARCHAR(100) DEFAULT NULL,
-- Business type: VARCHAR مش DB ENUM عمدًا (تعليمات صريحة بعدم استخدام
    -- ENUM لو المشروع محتاج Extensibility) - القيم المسموحة تتحقق في
    -- الكود (Validator) مش على مستوى الداتابيز، عشان إضافة نوع جديد
    -- (زي "Adventure Tourism" مستقبلًا) متطلبش Migration جديدة لتعديل ENUM.
    `business_type` VARCHAR(50) DEFAULT NULL COMMENT 'travel_agency, tour_operator, dmc, hotel, cruise, transportation, dmo, travel_consultant, other - يُتحقق منها في الكود',
`year_established` SMALLINT DEFAULT NULL,
`primary_language` VARCHAR(10) DEFAULT NULL COMMENT 'ISO 639-1',
`supported_languages` JSON DEFAULT NULL COMMENT 'مصفوفة ISO 639-1 codes',
`default_currency` VARCHAR(3) DEFAULT NULL COMMENT 'ISO 4217',
`timezone` VARCHAR(64) DEFAULT NULL COMMENT 'IANA timezone',
`created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
`updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
PRIMARY KEY (`id`),
INDEX `idx_owner_user` (`owner_user_id`),
INDEX `idx_business_type` (`business_type`),
CONSTRAINT `fk_business_owner` FOREIGN KEY (`owner_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Business Profile - منفصل عن User Profile (Tourfecto Business Control Center)';

-- Missing table: `ci_discovery_candidates`
CREATE TABLE IF NOT EXISTS `ci_discovery_candidates` (
`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
`user_id` bigint(20) UNSIGNED NOT NULL,
`website_id` bigint(20) UNSIGNED NOT NULL,
`competitor_name` VARCHAR(255) NOT NULL,
`website` VARCHAR(500) DEFAULT NULL,
`industry` VARCHAR(150) DEFAULT NULL,
`country` VARCHAR(100) DEFAULT NULL,
`market_segment` VARCHAR(150) DEFAULT NULL,
`source` VARCHAR(100) NOT NULL COMMENT 'مصدر الاكتشاف: مثال website_metadata / manual_hint / integration:{name}',
`category` ENUM('direct','indirect','emerging','potential') NOT NULL DEFAULT 'potential',
`confidence` ENUM('high','medium','low') NOT NULL DEFAULT 'low',
`status` ENUM('pending','added','dismissed') NOT NULL DEFAULT 'pending',
`discovered_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
`created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
PRIMARY KEY (`id`),
KEY `idx_ci_disc_user` (`user_id`, `status`),
KEY `idx_ci_disc_website` (`website_id`),
FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
FOREIGN KEY (`website_id`) REFERENCES `websites`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='مرشحو اكتشاف منافسين قبل الإضافة الفعلية';

-- Missing table: `ci_insights`
CREATE TABLE IF NOT EXISTS `ci_insights` (
`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
`user_id` bigint(20) UNSIGNED NOT NULL,
`website_id` bigint(20) UNSIGNED NOT NULL,
`competitor_id` bigint(20) UNSIGNED DEFAULT NULL COMMENT 'NULL = رؤية على مستوى السوق ككل (multiple competitors)',
`type` ENUM('insight','threat','opportunity','recommendation') NOT NULL,
`title` VARCHAR(255) NOT NULL,
`description` TEXT NOT NULL,
`evidence` TEXT DEFAULT NULL COMMENT 'إشارة صريحة للبيانات/التغييرات اللي بُني عليها',
`confidence` ENUM('high','medium','low') NOT NULL DEFAULT 'medium',
`threat_level` ENUM('low','medium','high') DEFAULT NULL,
`recommended_action` TEXT DEFAULT NULL,
`status` ENUM('new','reviewed','dismissed') NOT NULL DEFAULT 'new',
`generated_by` ENUM('rules_engine','ai') NOT NULL DEFAULT 'rules_engine',
`created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
PRIMARY KEY (`id`),
KEY `idx_ci_insight_user_type` (`user_id`, `type`, `created_at`),
KEY `idx_ci_insight_competitor` (`competitor_id`),
FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
FOREIGN KEY (`website_id`) REFERENCES `websites`(`id`) ON DELETE CASCADE,
FOREIGN KEY (`competitor_id`) REFERENCES `competitors`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='رؤى/تهديدات/فرص/توصيات تحليلية - غير مؤكدة كحقائق';

-- Missing table: `ci_rate_limits`
CREATE TABLE IF NOT EXISTS `ci_rate_limits` (
`scope_key` VARCHAR(120) NOT NULL COMMENT 'scope:actor - مثال: ai_ask:user:12',
`window_start` INT UNSIGNED NOT NULL COMMENT 'بداية نافذة fixed-window بالثواني',
`hits` INT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'عدد المحاولات في النافذة دي',
PRIMARY KEY (`scope_key`, `window_start`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Missing table: `ci_reports`
CREATE TABLE IF NOT EXISTS `ci_reports` (
`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
`user_id` bigint(20) UNSIGNED NOT NULL,
`website_id` bigint(20) UNSIGNED NOT NULL,
`competitor_id` bigint(20) UNSIGNED DEFAULT NULL COMMENT 'محدد فقط لتقارير Profile الخاصة بمنافس واحد',
`type` ENUM('weekly','monthly','profile','threat','opportunity','change') NOT NULL,
`title` VARCHAR(255) NOT NULL,
`period_start` DATE DEFAULT NULL,
`period_end` DATE DEFAULT NULL,
`content_json` LONGTEXT NOT NULL COMMENT 'محتوى التقرير الكامل بصيغة JSON - مصدر الحقيقة الواحد لأي Export',
`generated_by` ENUM('rules_engine','ai') NOT NULL DEFAULT 'rules_engine',
`generated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
PRIMARY KEY (`id`),
KEY `idx_ci_report_user_type_date` (`user_id`, `type`, `generated_at`),
FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
FOREIGN KEY (`website_id`) REFERENCES `websites`(`id`) ON DELETE CASCADE,
FOREIGN KEY (`competitor_id`) REFERENCES `competitors`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='تقارير Competitor Intelligence المُولَّدة';

-- Missing table: `ci_scorecards`
CREATE TABLE IF NOT EXISTS `ci_scorecards` (
`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
`competitor_id` bigint(20) UNSIGNED NOT NULL,
`period_start` DATE NOT NULL,
`period_end` DATE NOT NULL,
`visibility_score` DECIMAL(5,2) DEFAULT NULL,
`content_activity_score` DECIMAL(5,2) DEFAULT NULL,
`offer_activity_score` DECIMAL(5,2) DEFAULT NULL,
`customer_signals_score` DECIMAL(5,2) DEFAULT NULL,
`product_coverage_score` DECIMAL(5,2) DEFAULT NULL,
`market_presence_score` DECIMAL(5,2) DEFAULT NULL,
`basis` ENUM('data_backed','estimated') NOT NULL DEFAULT 'estimated',
`raw_metrics` JSON DEFAULT NULL COMMENT 'المقاييس الخام اللي بُنيت عليها الدرجات (شفافية كاملة)',
`computed_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
PRIMARY KEY (`id`),
KEY `idx_ci_scorecard_competitor_period` (`competitor_id`, `period_end`),
FOREIGN KEY (`competitor_id`) REFERENCES `competitors`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='بطاقات تقييم دورية لكل منافس لأغراض Benchmarking';

-- Missing table: `ci_snapshots`
CREATE TABLE IF NOT EXISTS `ci_snapshots` (
`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
`competitor_id` bigint(20) UNSIGNED NOT NULL,
`page_type` ENUM('homepage','pricing','products','services','landing','blog','contact','offers') NOT NULL,
`url` VARCHAR(1000) NOT NULL,
`http_status` SMALLINT UNSIGNED DEFAULT NULL,
`content_hash` CHAR(64) DEFAULT NULL COMMENT 'SHA-256 للنص المستخرج المُطبَّع (بعد إزالة الضوضاء)',
`title` VARCHAR(500) DEFAULT NULL,
`meta_description` VARCHAR(1000) DEFAULT NULL,
`normalized_excerpt` MEDIUMTEXT DEFAULT NULL COMMENT 'نص مستخرج مُطبَّع محدود الطول لاستخدامه في المقارنة والعرض before/after',
`structured_data_hash` CHAR(64) DEFAULT NULL COMMENT 'هاش لأي JSON-LD / structured data موجودة بالصفحة',
`fetch_status` ENUM('ok','failed') NOT NULL DEFAULT 'ok',
`fetch_error` VARCHAR(500) DEFAULT NULL COMMENT 'مهم: فشل الجلب لازم يتسجل هنا صراحة ولا يُعتبر أبدًا Nothing Changed',
`captured_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
PRIMARY KEY (`id`),
KEY `idx_ci_snap_competitor_page_date` (`competitor_id`, `page_type`, `captured_at`),
FOREIGN KEY (`competitor_id`) REFERENCES `competitors`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='لقطات دورية للصفحات العامة لكل منافس';

-- Missing table: `ci_user_preferences`
CREATE TABLE IF NOT EXISTS `ci_user_preferences` (
`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
`user_id` bigint(20) UNSIGNED NOT NULL,
`default_monitoring_frequency` ENUM('daily','weekly','custom') NOT NULL DEFAULT 'weekly',
`default_alert_min_severity` ENUM('info','low','medium','high','critical') NOT NULL DEFAULT 'medium',
`default_alert_channels` JSON DEFAULT NULL COMMENT 'مثال: ["dashboard","email"]',
`updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
`created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
PRIMARY KEY (`id`),
UNIQUE KEY `uniq_ci_user_preferences_user` (`user_id`),
FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='تفضيلات افتراضية لكل مستخدم في موديول Competitor Intelligence - تُستخدم كقيم ابتدائية فقط، لا تفرض على المنافسين الموجودين';

-- Missing table: `ci_watchlist`
CREATE TABLE IF NOT EXISTS `ci_watchlist` (
`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
`user_id` bigint(20) UNSIGNED NOT NULL,
`competitor_id` bigint(20) UNSIGNED NOT NULL,
`priority` ENUM('low','medium','high') NOT NULL DEFAULT 'medium',
`alert_min_severity` ENUM('info','low','medium','high','critical') NOT NULL DEFAULT 'medium',
`alert_channels` JSON DEFAULT NULL COMMENT 'مثال: ["dashboard","email"]',
`is_paused` TINYINT(1) NOT NULL DEFAULT 0,
`created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
`updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
PRIMARY KEY (`id`),
UNIQUE KEY `uniq_ci_watchlist_user_competitor` (`user_id`, `competitor_id`),
FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
FOREIGN KEY (`competitor_id`) REFERENCES `competitors`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='قائمة المنافسين المهمين لكل مستخدم مع قواعد التنبيه';

-- Missing table: `competitor_keywords`
CREATE TABLE IF NOT EXISTS `competitor_keywords` (
`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
`competitor_id` bigint(20) UNSIGNED NOT NULL,
`keyword` VARCHAR(255) NOT NULL,
`search_volume` INT(11) DEFAULT NULL,
`difficulty` TINYINT(3) UNSIGNED DEFAULT NULL COMMENT '0-100',
`source` VARCHAR(100) NOT NULL DEFAULT 'manual' COMMENT 'مصدر البيانات: manual / import / integration:{name}',
`discovered_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
`created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
PRIMARY KEY (`id`),
KEY `idx_ck_competitor` (`competitor_id`),
KEY `idx_ck_keyword` (`keyword`),
FOREIGN KEY (`competitor_id`) REFERENCES `competitors`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='كلمات مفتاحية مرصودة للمنافسين (لاستخدامها في تحليل الفجوات)';

-- Missing table: `crm_activity_types`
CREATE TABLE IF NOT EXISTS `crm_activity_types` (
`id` INT(11) NOT NULL AUTO_INCREMENT,
`user_id` bigint(20) UNSIGNED DEFAULT NULL COMMENT 'NULL = نوع افتراضي عام لكل الحسابات',
`type_key` VARCHAR(50) NOT NULL,
`name` VARCHAR(150) NOT NULL COMMENT 'التسمية الظاهرة',
`icon` VARCHAR(50) DEFAULT NULL,
`color` VARCHAR(20) DEFAULT '#6366f1',
`is_system` TINYINT(1) NOT NULL DEFAULT 0,
`is_active` TINYINT(1) NOT NULL DEFAULT 1,
`created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
PRIMARY KEY (`id`),
INDEX `idx_crm_act_types_user` (`user_id`),
CONSTRAINT `fk_crm_act_types_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='أنواع الأنشطة القابلة للتخصيص - المرحلة 14 (G10)';

-- Missing table: `crm_automation_rules`
CREATE TABLE IF NOT EXISTS `crm_automation_rules` (
`id` INT(11) NOT NULL AUTO_INCREMENT,
`user_id` bigint(20) UNSIGNED NOT NULL COMMENT 'صاحب الحساب (Tenant)',
`name` VARCHAR(255) NOT NULL,
`trigger_event` VARCHAR(60) NOT NULL COMMENT 'lead.created, lead.status_changed, deal.created, deal.stage_changed, deal.won, deal.lost',
`conditions` TEXT DEFAULT NULL COMMENT 'JSON: [{"field":"status","operator":"=","value":"qualified"}] - فاضي = بدون شرط',
`actions` TEXT NOT NULL COMMENT 'JSON: [{"type":"assign_owner","owner_user_id":5}, {"type":"create_task","title":"..."}]',
`is_active` TINYINT(1) NOT NULL DEFAULT 1,
`created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
`updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
PRIMARY KEY (`id`),
INDEX `idx_crm_automation_user` (`user_id`),
INDEX `idx_crm_automation_trigger` (`trigger_event`),
CONSTRAINT `fk_crm_automation_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='قواعد Automation Workflow لموديول CRM';

-- Missing table: `crm_companies`
CREATE TABLE IF NOT EXISTS `crm_companies` (
`id` INT(11) NOT NULL AUTO_INCREMENT,
`user_id` bigint(20) UNSIGNED NOT NULL COMMENT 'صاحب الحساب (Tenant) - عزل كامل للبيانات',
`name` VARCHAR(255) NOT NULL,
`industry` VARCHAR(150) DEFAULT NULL,
`website` VARCHAR(500) DEFAULT NULL,
`phone` VARCHAR(50) DEFAULT NULL,
`email` VARCHAR(255) DEFAULT NULL,
`address` VARCHAR(500) DEFAULT NULL,
`city` VARCHAR(150) DEFAULT NULL,
`country` VARCHAR(100) DEFAULT NULL,
`company_size` VARCHAR(50) DEFAULT NULL,
`notes` TEXT DEFAULT NULL,
`tags` TEXT DEFAULT NULL COMMENT 'JSON array من الوسوم',
`created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
`updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
PRIMARY KEY (`id`),
INDEX `idx_crm_companies_user` (`user_id`),
INDEX `idx_crm_companies_name` (`user_id`, `name`),
CONSTRAINT `fk_crm_companies_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='شركات CRM (Customer 360)';

-- Missing table: `crm_conversations`
CREATE TABLE IF NOT EXISTS `crm_conversations` (
`id` INT(11) NOT NULL AUTO_INCREMENT,
`user_id` bigint(20) UNSIGNED NOT NULL COMMENT 'صاحب الحساب (Tenant)',
`contact_id` int(11) DEFAULT NULL,
`channel` ENUM('whatsapp', 'email', 'sms') NOT NULL,
`external_thread_id` VARCHAR(255) DEFAULT NULL COMMENT 'مثال: رقم واتساب الطرف الآخر بصيغة E.164',
`assigned_user_id` INT(11) DEFAULT NULL,
`last_message_at` DATETIME DEFAULT NULL,
`unread_count` INT(11) NOT NULL DEFAULT 0,
`created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
PRIMARY KEY (`id`),
INDEX `idx_crm_conv_user` (`user_id`),
INDEX `idx_crm_conv_contact` (`contact_id`),
INDEX `idx_crm_conv_channel_thread` (`channel`, `external_thread_id`),
CONSTRAINT `fk_crm_conv_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
CONSTRAINT `fk_crm_conv_contact` FOREIGN KEY (`contact_id`) REFERENCES `crm_contacts` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='محادثات CRM عبر واتساب/إيميل/SMS';

-- Missing table: `crm_custom_fields`
CREATE TABLE IF NOT EXISTS `crm_custom_fields` (
`id` INT(11) NOT NULL AUTO_INCREMENT,
`user_id` bigint(20) UNSIGNED NOT NULL COMMENT 'صاحب الحساب (Tenant)',
`entity_type` ENUM('contact', 'lead', 'deal', 'company') NOT NULL,
`field_key` VARCHAR(60) NOT NULL COMMENT 'مفتاح فريد داخل (user_id + entity_type)',
`label` VARCHAR(255) NOT NULL COMMENT 'التسمية الظاهرة للمستخدم',
`field_type` ENUM('text', 'number', 'date', 'select') NOT NULL DEFAULT 'text',
`options` TEXT DEFAULT NULL COMMENT 'JSON: قائمة الخيارات لحقول select فقط',
`created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
`updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
PRIMARY KEY (`id`),
UNIQUE KEY `uq_crm_cf_user_type_key` (`user_id`, `entity_type`, `field_key`),
CONSTRAINT `fk_crm_cf_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='تعريفات الحقول المخصصة لكل كيان CRM - المرحلة 12 (G2)';

-- Missing table: `crm_email_trackings`
CREATE TABLE IF NOT EXISTS `crm_email_trackings` (
`id` INT(11) NOT NULL AUTO_INCREMENT,
`user_id` bigint(20) UNSIGNED NOT NULL COMMENT 'صاحب الحساب (Tenant)',
`contact_id` INT(11) DEFAULT NULL COMMENT 'جهة الاتصال المرسَل إليها',
`message_id` INT(11) DEFAULT NULL COMMENT 'سجل crm_messages المرتبط',
`token` VARCHAR(64) NOT NULL COMMENT 'توكن فريد للبكسل',
`email_subject` VARCHAR(255) DEFAULT NULL,
`sent_at` TIMESTAMP NULL DEFAULT NULL,
`first_opened_at` TIMESTAMP NULL DEFAULT NULL,
`last_opened_at` TIMESTAMP NULL DEFAULT NULL,
`open_count` INT(11) NOT NULL DEFAULT 0,
`ip_address` VARCHAR(45) DEFAULT NULL,
`user_agent` VARCHAR(255) DEFAULT NULL,
`created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
PRIMARY KEY (`id`),
UNIQUE KEY `uq_crm_email_trackings_token` (`token`),
INDEX `idx_crm_email_trackings_user` (`user_id`),
INDEX `idx_crm_email_trackings_message` (`message_id`),
INDEX `idx_crm_email_trackings_contact` (`contact_id`),
CONSTRAINT `fk_crm_email_trackings_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='تتبع فتح البريد (بكسل)';

-- Missing table: `crm_import_batches`
CREATE TABLE IF NOT EXISTS `crm_import_batches` (
`id` INT(11) NOT NULL AUTO_INCREMENT,
`user_id` bigint(20) UNSIGNED NOT NULL COMMENT 'صاحب الحساب (Tenant)',
`status` ENUM('pending', 'processing', 'completed', 'failed') NOT NULL DEFAULT 'pending',
`total_rows` INT(11) NOT NULL DEFAULT 0,
`imported_count` INT(11) NOT NULL DEFAULT 0,
`skipped_count` INT(11) NOT NULL DEFAULT 0,
`error` VARCHAR(500) DEFAULT NULL,
`created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
`completed_at` DATETIME DEFAULT NULL,
PRIMARY KEY (`id`),
INDEX `idx_crm_import_batches_user` (`user_id`),
CONSTRAINT `fk_crm_import_batches_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='تتبع دفعات استيراد جهات الاتصال (Background Jobs - بند 37)';

-- Missing table: `crm_lead_routing_rules`
CREATE TABLE IF NOT EXISTS `crm_lead_routing_rules` (
`id` INT(11) NOT NULL AUTO_INCREMENT,
`user_id` bigint(20) UNSIGNED NOT NULL COMMENT 'صاحب الحساب (Tenant)',
`name` VARCHAR(255) NOT NULL COMMENT 'اسم القاعدة (ظاهر للمستخدم)',
`is_active` TINYINT(1) NOT NULL DEFAULT 1,
`match_source` VARCHAR(50) DEFAULT NULL COMMENT 'تطابق على مصدر الـLead (website/whatsapp...) - NULL = أي مصدر',
`match_country` VARCHAR(100) DEFAULT NULL COMMENT 'تطابق على دولة جهة الاتصال - NULL = أي دولة',
`match_min_value` DECIMAL(14,2) DEFAULT NULL COMMENT 'حد أدنى لقيمة الفرصة - NULL = بلا حد',
`match_max_value` DECIMAL(14,2) DEFAULT NULL COMMENT 'حد أقصى لقيمة الفرصة - NULL = بلا حد',
`assignment_mode` ENUM('fixed', 'round_robin') NOT NULL DEFAULT 'fixed',
`assignee_user_id` bigint(20) UNSIGNED DEFAULT NULL COMMENT 'المستخدم المستهدف في وضع fixed',
`rotation_index` INT(11) NOT NULL DEFAULT 0 COMMENT 'عداد التناوب في وضع round_robin (يديره الـService)',
`sort_order` INT(11) NOT NULL DEFAULT 0 COMMENT 'ترتيب التطبيق - أول قاعدة مطابقة تفوز',
`created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
`updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
PRIMARY KEY (`id`),
INDEX `idx_crm_lrr_user` (`user_id`),
INDEX `idx_crm_lrr_active` (`user_id`, `is_active`),
CONSTRAINT `fk_crm_lrr_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
CONSTRAINT `fk_crm_lrr_assignee` FOREIGN KEY (`assignee_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='قواعد التوجيه التلقائي للـLeads - المرحلة 13 (G5)';

-- Missing table: `crm_lead_sources`
CREATE TABLE IF NOT EXISTS `crm_lead_sources` (
`id` INT(11) NOT NULL AUTO_INCREMENT,
`user_id` bigint(20) UNSIGNED DEFAULT NULL COMMENT 'NULL = مصدر افتراضي عام لكل الحسابات',
`name` VARCHAR(150) NOT NULL,
`source_key` VARCHAR(100) NOT NULL,
`is_active` TINYINT(1) NOT NULL DEFAULT 1,
`created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
PRIMARY KEY (`id`),
INDEX `idx_crm_lead_sources_user` (`user_id`),
CONSTRAINT `fk_crm_lead_sources_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='مصادر الـLeads القابلة للتخصيص';

-- Missing table: `crm_lifecycle_stages`
CREATE TABLE IF NOT EXISTS `crm_lifecycle_stages` (
`id` INT(11) NOT NULL AUTO_INCREMENT,
`user_id` bigint(20) UNSIGNED DEFAULT NULL COMMENT 'NULL = مرحلة افتراضية عامة لكل الحسابات',
`stage_key` VARCHAR(50) NOT NULL,
`name` VARCHAR(150) NOT NULL COMMENT 'التسمية الظاهرة',
`color` VARCHAR(20) DEFAULT '#6366f1',
`sort_order` INT(11) NOT NULL DEFAULT 0,
`is_system` TINYINT(1) NOT NULL DEFAULT 0,
`created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
PRIMARY KEY (`id`),
INDEX `idx_crm_lcs_user` (`user_id`),
CONSTRAINT `fk_crm_lcs_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='مراحل دورة حياة العملاء (قابلة للتخصيص) - المرحلة 13 (G6)';

-- Missing table: `crm_message_templates`
CREATE TABLE IF NOT EXISTS `crm_message_templates` (
`id` INT(11) NOT NULL AUTO_INCREMENT,
`user_id` bigint(20) UNSIGNED NOT NULL COMMENT 'صاحب الحساب (Tenant)',
`channel` ENUM('email', 'whatsapp', 'sms') NOT NULL,
`name` VARCHAR(255) NOT NULL,
`subject` VARCHAR(255) DEFAULT NULL COMMENT 'للإيميل فقط',
`body` TEXT NOT NULL,
`variables` TEXT DEFAULT NULL COMMENT 'JSON: قائمة المتغيرات المسموحة [{"key":"name","label":"الاسم"}]',
`created_by_user_id` INT(11) DEFAULT NULL COMMENT 'الفاعل الحقيقي اللي أنشأ القالب (Actor)',
`created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
`updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
PRIMARY KEY (`id`),
INDEX `idx_crm_msg_tpl_user` (`user_id`),
INDEX `idx_crm_msg_tpl_channel` (`channel`),
CONSTRAINT `fk_crm_msg_tpl_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='قوالب رسائل CRM (Email/WhatsApp/SMS) - المرحلة 12 (G1)';

-- Missing table: `crm_pipelines`
CREATE TABLE IF NOT EXISTS `crm_pipelines` (
`id` INT(11) NOT NULL AUTO_INCREMENT,
`user_id` bigint(20) UNSIGNED DEFAULT NULL COMMENT 'NULL = Pipeline افتراضي عام لكل الحسابات (متوافق مع السلوك الحالي)',
`name` VARCHAR(150) NOT NULL,
`pipeline_key` VARCHAR(100) NOT NULL,
`is_default` TINYINT(1) NOT NULL DEFAULT 0,
`sort_order` INT(11) NOT NULL DEFAULT 0,
`created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
PRIMARY KEY (`id`),
INDEX `idx_crm_pipelines_user` (`user_id`),
CONSTRAINT `fk_crm_pipelines_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='قوالب مسارات البيع (Sales / Travel Bookings / Real Estate...)';

-- Missing table: `crm_products`
CREATE TABLE IF NOT EXISTS `crm_products` (
`id` INT(11) NOT NULL AUTO_INCREMENT,
`user_id` bigint(20) UNSIGNED NOT NULL COMMENT 'صاحب الحساب (Tenant) - عزل كامل',
`name` VARCHAR(255) NOT NULL,
`description` TEXT DEFAULT NULL,
`sku` VARCHAR(100) DEFAULT NULL,
`price` DECIMAL(14,2) NOT NULL DEFAULT 0,
`currency` VARCHAR(10) NOT NULL DEFAULT 'USD',
`is_active` TINYINT(1) NOT NULL DEFAULT 1,
`created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
`updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
PRIMARY KEY (`id`),
INDEX `idx_crm_products_user` (`user_id`),
INDEX `idx_crm_products_name` (`user_id`, `name`),
CONSTRAINT `fk_crm_products_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='كتالوج منتجات CRM - المرحلة 13 (G3)';

-- Missing table: `crm_sales_goals`
CREATE TABLE IF NOT EXISTS `crm_sales_goals` (
`id` INT(11) NOT NULL AUTO_INCREMENT,
`user_id` bigint(20) UNSIGNED NOT NULL COMMENT 'صاحب الحساب (Tenant)',
`period` VARCHAR(7) NOT NULL COMMENT 'الشهر بصيغة YYYY-MM (مثال: 2026-08)',
`target_value` DECIMAL(15,2) NOT NULL DEFAULT 0 COMMENT 'الإيراد المستهدف للشهر',
`created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
`updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
PRIMARY KEY (`id`),
UNIQUE KEY `uq_crm_sales_goal_user_period` (`user_id`, `period`),
CONSTRAINT `fk_crm_sales_goal_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='أهداف مبيعات شهرية لكل حساب - المرحلة 12 (G4)';

-- Missing table: `crm_saved_reports`
CREATE TABLE IF NOT EXISTS `crm_saved_reports` (
`id` INT(11) NOT NULL AUTO_INCREMENT,
`user_id` bigint(20) UNSIGNED NOT NULL COMMENT 'صاحب الحساب (Tenant)',
`name` VARCHAR(150) NOT NULL,
`entity` VARCHAR(30) NOT NULL COMMENT 'contacts / leads / deals / activities / products',
`config` TEXT NOT NULL COMMENT 'JSON: {"fields":[...],"filters":[...],"group_by":"...","order_by":"...","limit":100}',
`created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
`updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
PRIMARY KEY (`id`),
INDEX `idx_crm_saved_reports_user` (`user_id`),
CONSTRAINT `fk_crm_saved_reports_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='تقارير مخصصة محفوظة - المرحلة 15 (G13)';

-- Missing table: `crm_segments`
CREATE TABLE IF NOT EXISTS `crm_segments` (
`id` INT(11) NOT NULL AUTO_INCREMENT,
`user_id` bigint(20) UNSIGNED DEFAULT NULL COMMENT 'NULL = قطاع افتراضي عام (Seed) - يظهر لكل الحسابات، مش قابل للحذف من الواجهة',
`name` VARCHAR(150) NOT NULL,
`filters` TEXT NOT NULL COMMENT 'JSON - نفس شكل $filters في CrmContactService::search()',
`is_system` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = قطاع افتراضي مبني على بيانات حقيقية فقط (راجع Seed أسفل)',
`created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
PRIMARY KEY (`id`),
INDEX `idx_crm_segments_user` (`user_id`),
CONSTRAINT `fk_crm_segments_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='قطاعات عملاء محفوظة (Customer Segmentation - بند 19)';

-- Missing table: `crm_team_members`
CREATE TABLE IF NOT EXISTS `crm_team_members` (
`id` INT(11) NOT NULL AUTO_INCREMENT,
`tenant_user_id` bigint(20) UNSIGNED NOT NULL COMMENT 'صاحب الحساب الأصلي (Tenant) - نفس id المستخدم الحالي كـ"Tenant" في كل جداول CRM',
`member_user_id` bigint(20) UNSIGNED NOT NULL COMMENT 'المستخدم المُضاف كعضو فريق - لازم يكون له حساب Tourfecto فعلي بالفعل',
`role` ENUM('admin', 'manager', 'sales', 'support', 'viewer') NOT NULL DEFAULT 'viewer',
`added_by_user_id` INT(11) DEFAULT NULL,
`created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
`updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
PRIMARY KEY (`id`),
UNIQUE KEY `uniq_member_single_tenant` (`member_user_id`) COMMENT 'قيد مبسّط متعمّد: كل مستخدم عضو في فريق CRM واحد بس حاليًا - راجع CHANGELOG',
INDEX `idx_crm_team_tenant` (`tenant_user_id`),
CONSTRAINT `fk_crm_team_tenant` FOREIGN KEY (`tenant_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
CONSTRAINT `fk_crm_team_member` FOREIGN KEY (`member_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='فريق CRM لكل حساب (بند 30: Roles & Permissions)';

-- Missing table: `crm_web_forms`
CREATE TABLE IF NOT EXISTS `crm_web_forms` (
`id` INT(11) NOT NULL AUTO_INCREMENT,
`user_id` bigint(20) UNSIGNED NOT NULL COMMENT 'صاحب الحساب (Tenant)',
`name` VARCHAR(150) NOT NULL,
`slug` VARCHAR(80) NOT NULL COMMENT 'معرّف عام للرابط المباشر',
`description` VARCHAR(500) DEFAULT NULL,
`fields` TEXT DEFAULT NULL COMMENT 'JSON: [{"name":"email","label":"Email","type":"email","required":true}]',
`success_message` VARCHAR(255) DEFAULT NULL,
`redirect_url` VARCHAR(500) DEFAULT NULL,
`owner_user_id` INT(11) DEFAULT NULL COMMENT 'المستخدم المستهدف للـLead الوارد (Routing)',
`source` VARCHAR(50) DEFAULT 'web_form',
`is_active` TINYINT(1) NOT NULL DEFAULT 1,
`created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
`updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
PRIMARY KEY (`id`),
UNIQUE KEY `uq_crm_web_forms_slug` (`slug`),
INDEX `idx_crm_web_forms_user` (`user_id`),
CONSTRAINT `fk_crm_web_forms_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='نماذج ويب لالتقاط Leads - المرحلة 15 (G11)';

-- Missing table: `email_ab_tests`
CREATE TABLE IF NOT EXISTS `email_ab_tests` (
`id` int(11) NOT NULL AUTO_INCREMENT,
`user_id` int(11) NOT NULL,
`name` varchar(191) NOT NULL,
`base_campaign_id` int(11) NOT NULL,
`variant_a_id` int(11) NOT NULL,
`variant_b_id` int(11) NOT NULL,
`split_percent` int(11) NOT NULL DEFAULT 50,
`metric` varchar(16) NOT NULL DEFAULT 'open',
`status` varchar(16) NOT NULL DEFAULT 'draft',
`winner` varchar(4) DEFAULT NULL,
`winner_at` datetime DEFAULT NULL,
`created_at` timestamp NOT NULL DEFAULT current_timestamp(),
`updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
PRIMARY KEY (`id`),
KEY `idx_email_ab_tests_user` (`user_id`),
KEY `idx_email_ab_tests_base` (`base_campaign_id`),
KEY `idx_email_ab_tests_variant_a` (`variant_a_id`),
KEY `idx_email_ab_tests_variant_b` (`variant_b_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='اختبار أ/ب لحملات البريد';

-- Missing table: `email_automations`
CREATE TABLE IF NOT EXISTS `email_automations` (
`id` int(11) NOT NULL AUTO_INCREMENT,
`user_id` int(11) NOT NULL,
`name` varchar(191) NOT NULL,
`description` varchar(255) DEFAULT NULL,
`trigger_type` varchar(32) NOT NULL DEFAULT 'subscribed',
`trigger_value` text DEFAULT NULL COMMENT 'JSON: list_id/tag/campaign_id',
`entry_audience_ids` text DEFAULT NULL COMMENT 'JSON: قوائم مؤهلة للدخول',
`exit_audience_ids` text DEFAULT NULL COMMENT 'JSON: قوائم تسبب الخروج',
`status` varchar(16) NOT NULL DEFAULT 'active',
`created_at` timestamp NOT NULL DEFAULT current_timestamp(),
`updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
PRIMARY KEY (`id`),
KEY `idx_email_automations_user` (`user_id`),
KEY `idx_email_automations_trigger` (`trigger_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='سير عمل أتمتة تسويق البريد';

-- Missing table: `email_custom_fields`
CREATE TABLE IF NOT EXISTS `email_custom_fields` (
`id` INT(11) NOT NULL AUTO_INCREMENT,
`user_id` bigint(20) UNSIGNED NOT NULL COMMENT 'صاحب الحساب',
`name` VARCHAR(100) NOT NULL COMMENT 'المفتاح البرمجي (snake_case)',
`label` VARCHAR(191) NOT NULL COMMENT 'الاسم الظاهر للمستخدم',
`field_type` ENUM('text','number','date','boolean','select','multi_select') NOT NULL DEFAULT 'text',
`options` JSON NULL DEFAULT NULL COMMENT 'خيارات select/multi_select',
`is_required` TINYINT(1) NOT NULL DEFAULT 0,
`is_system` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'حقول مدمجة (name/company/city)',
`sort_order` INT(11) NOT NULL DEFAULT 0,
`created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
`updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
PRIMARY KEY (`id`),
UNIQUE KEY `uq_email_cf_user_name` (`user_id`, `name`),
KEY `idx_email_cf_user_id` (`user_id`),
CONSTRAINT `fk_email_cf_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='حقول مخصصة للمشتركين';

-- Missing table: `email_lists`
CREATE TABLE IF NOT EXISTS `email_lists` (
`id` INT(11) NOT NULL AUTO_INCREMENT,
`user_id` bigint(20) UNSIGNED NOT NULL COMMENT 'صاحب الحساب (tenant)',
`name` VARCHAR(191) NOT NULL,
`description` VARCHAR(500) NULL DEFAULT NULL,
`subscriber_count` INT(11) NOT NULL DEFAULT 0 COMMENT 'عدّاد مُحدّث عند الاشتراك/الإلغاء',
`created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
`updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
PRIMARY KEY (`id`),
KEY `idx_email_lists_user_id` (`user_id`),
CONSTRAINT `fk_email_lists_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='قوائم الجمهور لتسويق البريد';

-- Missing table: `email_segments`
CREATE TABLE IF NOT EXISTS `email_segments` (
`id` INT(11) NOT NULL AUTO_INCREMENT,
`user_id` bigint(20) UNSIGNED NOT NULL,
`name` VARCHAR(191) NOT NULL,
`description` VARCHAR(500) NULL DEFAULT NULL,
`conditions` JSON NOT NULL COMMENT 'شروط ديناميكية (group + field + operator + value)',
`match_all` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1 = كل الشروط (AND)، 0 = أي شرط (OR)',
`subscriber_count` INT(11) NOT NULL DEFAULT 0 COMMENT 'نتيجة آخر حساب',
`created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
`updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
PRIMARY KEY (`id`),
KEY `idx_email_segments_user_id` (`user_id`),
CONSTRAINT `fk_email_segments_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='شرائح الجمهور المحفوظة';

-- Missing table: `email_smtp_settings`
CREATE TABLE IF NOT EXISTS `email_smtp_settings` (
`id` int(11) NOT NULL AUTO_INCREMENT,
`user_id` int(11) NOT NULL,
`host` varchar(191) NOT NULL DEFAULT '',
`port` int(11) NOT NULL DEFAULT 587,
`username` varchar(191) NOT NULL DEFAULT '',
`password` varchar(255) NOT NULL DEFAULT '',
`encryption` varchar(16) NOT NULL DEFAULT 'tls',
`from_email` varchar(191) DEFAULT NULL,
`from_name` varchar(191) DEFAULT NULL,
`is_active` tinyint(1) NOT NULL DEFAULT 0,
`last_test_at` datetime DEFAULT NULL,
`created_at` timestamp NOT NULL DEFAULT current_timestamp(),
`updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
PRIMARY KEY (`id`),
UNIQUE KEY `uq_email_smtp_settings_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='إعدادات SMTP لكل مستخدم';

-- Missing table: `email_subscribers`
CREATE TABLE IF NOT EXISTS `email_subscribers` (
`id` INT(11) NOT NULL AUTO_INCREMENT,
`user_id` bigint(20) UNSIGNED NOT NULL,
`email` VARCHAR(191) NOT NULL,
`name` VARCHAR(191) NULL DEFAULT NULL,
`attributes` JSON NULL DEFAULT NULL COMMENT 'خصائص إضافية للتخصيص (company, city...)',
`status` ENUM('subscribed','unsubscribed','bounced') NOT NULL DEFAULT 'subscribed' COMMENT 'حالة الاشتراك',
`unsubscribe_token` VARCHAR(64) NOT NULL COMMENT 'توكن فريد لرابط إلغاء الاشتراك',
`source` VARCHAR(50) NOT NULL DEFAULT 'manual' COMMENT 'manual / import / form / campaign',
`unsubscribed_at` TIMESTAMP NULL DEFAULT NULL,
`created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
`updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
PRIMARY KEY (`id`),
UNIQUE KEY `uq_email_subscribers_user_email` (`user_id`, `email`),
UNIQUE KEY `uq_email_subscribers_token` (`unsubscribe_token`),
KEY `idx_email_subscribers_status` (`status`),
CONSTRAINT `fk_email_subscribers_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='مشتركو تسويق البريد';

-- Missing table: `email_suppressions`
CREATE TABLE IF NOT EXISTS `email_suppressions` (
`id` INT(11) NOT NULL AUTO_INCREMENT,
`user_id` bigint(20) UNSIGNED NOT NULL,
`email` VARCHAR(191) NOT NULL,
`type` ENUM('bounce','complaint','spam','manual') NOT NULL DEFAULT 'manual' COMMENT 'سبب المنع',
`reason` VARCHAR(500) NULL DEFAULT NULL,
`source` VARCHAR(50) NOT NULL DEFAULT 'manual' COMMENT 'من أين جاءت: smtp / webhook / manual',
`suppressed_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
PRIMARY KEY (`id`),
UNIQUE KEY `uq_email_suppressions_user_email` (`user_id`, `email`),
KEY `idx_email_suppressions_type` (`type`),
CONSTRAINT `fk_email_suppressions_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='قائمة ممنوعين/ارتدادات/شكاوى على مستوى الحساب';

-- Missing table: `email_tags`
CREATE TABLE IF NOT EXISTS `email_tags` (
`id` INT(11) NOT NULL AUTO_INCREMENT,
`user_id` bigint(20) UNSIGNED NOT NULL,
`name` VARCHAR(100) NOT NULL,
`color` VARCHAR(20) NULL DEFAULT NULL COMMENT 'لون الوسم (hex)',
`created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
`updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
PRIMARY KEY (`id`),
UNIQUE KEY `uq_email_tags_user_name` (`user_id`, `name`),
CONSTRAINT `fk_email_tags_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='وسوم تصنيف المشتركين';

-- Missing table: `email_templates`
CREATE TABLE IF NOT EXISTS `email_templates` (
`id` INT(11) NOT NULL AUTO_INCREMENT,
`user_id` bigint(20) UNSIGNED NOT NULL,
`name` VARCHAR(191) NOT NULL,
`subject` VARCHAR(255) NOT NULL DEFAULT '',
`html_body` MEDIUMTEXT NULL DEFAULT NULL COMMENT 'قالب HTML مع {{variables}}',
`created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
`updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
PRIMARY KEY (`id`),
KEY `idx_email_templates_user_id` (`user_id`),
CONSTRAINT `fk_email_templates_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='قوالب حملات تسويق البريد';

-- Missing table: `email_transactional_logs`
CREATE TABLE IF NOT EXISTS `email_transactional_logs` (
`id` int(11) NOT NULL AUTO_INCREMENT,
`user_id` int(11) NOT NULL,
`template_id` int(11) DEFAULT NULL,
`to_email` varchar(191) NOT NULL,
`to_name` varchar(191) DEFAULT NULL,
`subject` varchar(255) NOT NULL DEFAULT '',
`status` varchar(16) NOT NULL DEFAULT 'sent',
`error` varchar(1000) DEFAULT NULL,
`open_token` varchar(64) DEFAULT NULL,
`click_token` varchar(64) DEFAULT NULL,
`opened_at` datetime DEFAULT NULL,
`clicked_at` datetime DEFAULT NULL,
`open_count` int(11) NOT NULL DEFAULT 0,
`click_count` int(11) NOT NULL DEFAULT 0,
`created_at` timestamp NOT NULL DEFAULT current_timestamp(),
PRIMARY KEY (`id`),
KEY `idx_email_transactional_logs_user` (`user_id`),
KEY `idx_email_transactional_logs_open` (`open_token`),
KEY `idx_email_transactional_logs_click` (`click_token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='سجل رسائل المعاملات المرسلة';

-- Missing table: `email_transactional_templates`
CREATE TABLE IF NOT EXISTS `email_transactional_templates` (
`id` int(11) NOT NULL AUTO_INCREMENT,
`user_id` int(11) NOT NULL,
`name` varchar(191) NOT NULL,
`slug` varchar(191) NOT NULL,
`subject` varchar(255) NOT NULL DEFAULT '',
`html_body` longtext DEFAULT NULL,
`is_active` tinyint(1) NOT NULL DEFAULT 1,
`created_at` timestamp NOT NULL DEFAULT current_timestamp(),
`updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
PRIMARY KEY (`id`),
UNIQUE KEY `uq_email_transactional_templates_user_slug` (`user_id`, `slug`),
KEY `idx_email_transactional_templates_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='قوالب رسائل المعاملات';

-- Missing table: `gbp_audit_log`
CREATE TABLE IF NOT EXISTS `gbp_audit_log` (
`id` INT(11) NOT NULL AUTO_INCREMENT,
`website_id` INT(11) DEFAULT NULL,
`user_id` INT(11) DEFAULT NULL,
`action` VARCHAR(50) NOT NULL COMMENT 'connect, disconnect, sync, profile_update, hours_update, attributes_update, photo_upload, photo_delete, post_create, post_update, post_publish, post_delete, ai_analysis',
`status` ENUM('success', 'failed') NOT NULL DEFAULT 'success',
`details` TEXT DEFAULT NULL COMMENT 'JSON بمعلومات وصفية بس - ممنوع تسجيل access_token/refresh_token/client_secret هنا',
`ip_address` VARCHAR(45) DEFAULT NULL,
`created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
PRIMARY KEY (`id`),
KEY `idx_gbp_audit_website` (`website_id`),
KEY `idx_gbp_audit_user` (`user_id`),
KEY `idx_gbp_audit_action` (`action`),
KEY `idx_gbp_audit_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='سجل تدقيق عمليات موديول GBP - بدون أي Secrets';

-- Missing table: `monitored_backlinks`
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

-- Missing table: `payment_transactions`
CREATE TABLE IF NOT EXISTS `payment_transactions` (
`id` INT(11) NOT NULL AUTO_INCREMENT,
`internal_transaction_id` VARCHAR(64) NOT NULL COMMENT 'UUID داخلي - يتولّد قبل أي محاولة دفع فعلية',
`user_id` INT(11) NOT NULL,
`amount` DECIMAL(12,2) NOT NULL,
`currency` VARCHAR(10) NOT NULL DEFAULT 'USD',
`payment_method` VARCHAR(50) NOT NULL COMMENT 'wallet / card / bank_transfer ...',
`gateway` VARCHAR(50) NOT NULL COMMENT 'مفتاح الـ Gateway (wallet حاليًا - مطابق لـ PaymentGatewayInterface::key())',
`gateway_transaction_id` VARCHAR(191) NULL DEFAULT NULL COMMENT 'رقم العملية عند البوابة الحقيقية (NULL للمحفظة)',
`status` ENUM('pending','processing','succeeded','failed','cancelled','refunded','partially_refunded') NOT NULL DEFAULT 'pending',
`reference` VARCHAR(191) NULL DEFAULT NULL COMMENT 'مرجع منطقي - رقم فاتورة، معرّف اشتراك...',
`related_wallet_transaction_id` INT(11) NULL DEFAULT NULL COMMENT 'ربط بصف wallet_transactions المقابل لو الطريقة محفظة',
`metadata` JSON NULL DEFAULT NULL,
`idempotency_key` VARCHAR(80) NULL DEFAULT NULL,
`created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
`updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
PRIMARY KEY (`id`),
UNIQUE KEY `uq_payment_tx_internal_id` (`internal_transaction_id`),
UNIQUE KEY `uq_payment_tx_idempotency_key` (`idempotency_key`),
KEY `idx_payment_tx_user_id` (`user_id`),
KEY `idx_payment_tx_status` (`status`),
KEY `idx_payment_tx_gateway_tx_id` (`gateway_transaction_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='سجل موحّد لكل محاولات الدفع - أي طريقة دفع حالية أو مستقبلية';

-- Missing table: `revai_action_executions`
CREATE TABLE IF NOT EXISTS `revai_action_executions` (
`id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
`user_id` INT NOT NULL,
`action_key` VARCHAR(190) NOT NULL,
`source_type` VARCHAR(20) NULL,
`source_category` VARCHAR(60) NULL,
`affected_area` VARCHAR(120) NULL,
`severity` VARCHAR(10) NULL,
`actions_taken` TEXT NULL,
`created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
KEY `idx_revai_act_user` (user_id, created_at),
KEY `idx_revai_act_key` (action_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Missing table: `revai_benchmarks`
CREATE TABLE IF NOT EXISTS `revai_benchmarks` (
`id` INT(11) NOT NULL AUTO_INCREMENT,
`metric_key` VARCHAR(50) NOT NULL COMMENT 'e.g. growth_percent_monthly / cohort_retention_m1',
`metric_label` VARCHAR(255) NOT NULL,
`p25` DECIMAL(14,4) DEFAULT NULL COMMENT 'الربيع الأدنى',
`p50` DECIMAL(14,4) DEFAULT NULL COMMENT 'الوسيط',
`p75` DECIMAL(14,4) DEFAULT NULL COMMENT 'الربيع الأعلى',
`basis` VARCHAR(50) NOT NULL DEFAULT 'platform' COMMENT 'مصدر الرقم: platform = مجاميع حقيقية من بيانات المنصة',
`sample_size` INT(11) NOT NULL DEFAULT 0 COMMENT 'عدد الحسابات الداخلة في الحساب',
`as_of_date` DATE NOT NULL,
`created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
PRIMARY KEY (`id`),
UNIQUE KEY `uniq_metric_asof` (`metric_key`, `as_of_date`),
INDEX `idx_bench_asof` (`as_of_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Benchmarks مشتقة من بيانات المنصة الحقيقية (لا أرقام مخترعة)';

-- Missing table: `revai_dashboard_prefs`
CREATE TABLE IF NOT EXISTS `revai_dashboard_prefs` (
`id` INT(11) NOT NULL AUTO_INCREMENT,
`user_id` bigint(20) UNSIGNED NOT NULL COMMENT 'صاحب الحساب (Tenant Isolation)',
`layout` JSON NOT NULL COMMENT 'تخصيص أجزاء الداشبورد (widgets, order, visibility)',
`created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
`updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
PRIMARY KEY (`id`),
UNIQUE KEY `uq_revai_prefs_user` (`user_id`),
CONSTRAINT `fk_revai_prefs_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='تخصيص داشبورد ذكاء الإيرادات لكل مستخدم';

-- Missing table: `revai_stripe_events`
CREATE TABLE IF NOT EXISTS `revai_stripe_events` (
`id` INT(11) NOT NULL AUTO_INCREMENT,
`user_id` bigint(20) UNSIGNED NOT NULL COMMENT 'صاحب الحساب (Tenant Isolation)',
`stripe_event_id` VARCHAR(100) NOT NULL,
`event_type` VARCHAR(100) NOT NULL DEFAULT '',
`status` VARCHAR(20) NOT NULL DEFAULT 'processed' COMMENT 'processed/ignored_type/duplicate',
`received_at` DATETIME NOT NULL,
PRIMARY KEY (`id`),
UNIQUE KEY `uq_revai_stripe_evt_user_event` (`user_id`, `stripe_event_id`),
INDEX `idx_revai_stripe_evt_user_date` (`user_id`, `received_at`),
CONSTRAINT `fk_revai_stripe_evt_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='سجل أحداث Stripe المستلمة (منع التكرار + تتبع)';

-- Missing table: `revai_stripe_settings`
CREATE TABLE IF NOT EXISTS `revai_stripe_settings` (
`id` INT(11) NOT NULL AUTO_INCREMENT,
`user_id` bigint(20) UNSIGNED NOT NULL COMMENT 'صاحب الحساب (Tenant Isolation)',
`webhook_secret_enc` VARCHAR(500) DEFAULT NULL COMMENT 'سر webhook مشفر (Encryption::encrypt) - لا نص صريح',
`connected_account_id` VARCHAR(100) DEFAULT NULL COMMENT 'Stripe Account ID (اختياري، للعرض فقط)',
`mode` ENUM('test', 'live') NOT NULL DEFAULT 'test' COMMENT 'وضع الاتصال - test/live',
`is_enabled` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'هل الـ webhook مفعّل لهذا المستخدم',
`last_event_at` DATETIME DEFAULT NULL COMMENT 'آخر حدث webhook مستلم ومعالج',
`last_event_type` VARCHAR(100) DEFAULT NULL,
`created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
`updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
PRIMARY KEY (`id`),
UNIQUE KEY `uq_revai_stripe_user` (`user_id`),
CONSTRAINT `fk_revai_stripe_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='إعدادات اتصال Stripe للمستخدم (السر مشفر، Tenant Isolation)';

-- Missing table: `review_request_opt_outs`
CREATE TABLE IF NOT EXISTS `review_request_opt_outs` (
`id` INT(11) NOT NULL AUTO_INCREMENT,
`website_id` bigint(20) UNSIGNED NOT NULL,
`guest_phone` VARCHAR(30) DEFAULT NULL,
`guest_email` VARCHAR(190) DEFAULT NULL,
`reason` VARCHAR(255) DEFAULT NULL,
`source_request_id` INT(11) DEFAULT NULL COMMENT 'الطلب اللي أدى للـ Opt-Out (لو موجود)',
`created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
PRIMARY KEY (`id`),
KEY `idx_rroo_website_phone` (`website_id`, `guest_phone`),
KEY `idx_rroo_website_email` (`website_id`, `guest_email`),
FOREIGN KEY (`website_id`) REFERENCES `websites`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='قائمة عدم التواصل - Review Requests';

-- Missing table: `sales_teams`
CREATE TABLE IF NOT EXISTS `sales_teams` (
`id` INT(11) NOT NULL AUTO_INCREMENT,
`user_id` bigint(20) UNSIGNED NOT NULL COMMENT 'صاحب الحساب (Tenant Isolation)',
`name` VARCHAR(255) NOT NULL,
`created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
PRIMARY KEY (`id`),
INDEX `idx_sales_team_user` (`user_id`),
CONSTRAINT `fk_sales_team_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='فرق البيع';

-- Missing table: `seo_ab_servings`
CREATE TABLE IF NOT EXISTS `seo_ab_servings` (
`id` INT(11) NOT NULL AUTO_INCREMENT,
`test_id` INT(11) NOT NULL,
`variant_id` INT(11) NOT NULL,
`page_url` VARCHAR(500) NOT NULL,
`user_agent` VARCHAR(255) DEFAULT NULL,
`is_bot` TINYINT(1) NOT NULL DEFAULT 0,
`served_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
PRIMARY KEY (`id`),
KEY `idx_abs_test` (`test_id`),
KEY `idx_abs_page` (`page_url`(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='سجل عرض نسخ تجربة SEO A/B (للمطابقة مع GSC)';

-- Missing table: `seo_ab_tests`
CREATE TABLE IF NOT EXISTS `seo_ab_tests` (
`id` INT(11) NOT NULL AUTO_INCREMENT,
`website_id` INT(11) NOT NULL,
`user_id` INT(11) NOT NULL,
`name` VARCHAR(255) NOT NULL,
`target_field` VARCHAR(50) NOT NULL COMMENT 'seo_title/seo_description/canonical_url/json_ld...',
`target_path` VARCHAR(500) DEFAULT NULL COMMENT 'المسار المستهدف (NULL = كل الصفحات)',
`status` ENUM('draft','running','paused','completed') NOT NULL DEFAULT 'draft',
`winner_variant_id` INT(11) DEFAULT NULL,
`started_at` TIMESTAMP NULL DEFAULT NULL,
`ended_at` TIMESTAMP NULL DEFAULT NULL,
`created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
`updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
PRIMARY KEY (`id`),
KEY `idx_ab_website` (`website_id`),
KEY `idx_ab_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='تجارب SEO A/B Testing';

-- Missing table: `seo_ab_variants`
CREATE TABLE IF NOT EXISTS `seo_ab_variants` (
`id` INT(11) NOT NULL AUTO_INCREMENT,
`test_id` INT(11) NOT NULL,
`name` VARCHAR(100) NOT NULL COMMENT 'control / variant A / variant B...',
`value` TEXT NOT NULL COMMENT 'القيمة المقترحة للحقل المستهدف',
`is_control` TINYINT(1) NOT NULL DEFAULT 0,
`traffic_weight` INT(11) NOT NULL DEFAULT 50 COMMENT 'نسبة التوزيع 0-100',
`served_count` INT(11) NOT NULL DEFAULT 0,
`created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
PRIMARY KEY (`id`),
KEY `idx_abv_test` (`test_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='نسخ تجربة SEO A/B';

-- Missing table: `seo_content_campaigns`
CREATE TABLE IF NOT EXISTS `seo_content_campaigns` (
`id` INT(11) NOT NULL AUTO_INCREMENT,
`user_id` INT(11) NOT NULL,
`website_id` INT(11) NOT NULL,
`name` VARCHAR(255) NOT NULL,
`topic_source` ENUM('manual','gsc','keywords','competitors') NOT NULL DEFAULT 'manual',
`status` ENUM('draft','generating','ready','completed') NOT NULL DEFAULT 'draft',
`total_items` INT(11) NOT NULL DEFAULT 0,
`generated_items` INT(11) NOT NULL DEFAULT 0,
`created_at` DATETIME NOT NULL,
`updated_at` DATETIME NOT NULL,
PRIMARY KEY (`id`),
KEY `idx_scc_website` (`website_id`),
KEY `idx_scc_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='حملات محتوى SEO التلقائي - Phase 24';

-- Missing table: `seo_content_items`
CREATE TABLE IF NOT EXISTS `seo_content_items` (
`id` INT(11) NOT NULL AUTO_INCREMENT,
`campaign_id` INT(11) NOT NULL,
`article_id` INT(11) NULL DEFAULT NULL COMMENT 'معرف المقال المولّد في ai_articles',
`topic` VARCHAR(500) NOT NULL,
`keyword` VARCHAR(255) NULL DEFAULT NULL,
`status` ENUM('queued','generated','indexed','published','testing','failed') NOT NULL DEFAULT 'queued',
`title` VARCHAR(500) NULL DEFAULT NULL,
`slug` VARCHAR(255) NULL DEFAULT NULL,
`published_url` VARCHAR(500) NULL DEFAULT NULL,
`ab_test_id` INT(11) NULL DEFAULT NULL,
`indexnow_code` SMALLINT NULL DEFAULT NULL COMMENT 'كود استجابة IndexNow (202=نجاح)',
`error_message` TEXT NULL DEFAULT NULL,
`created_at` DATETIME NOT NULL,
`updated_at` DATETIME NOT NULL,
PRIMARY KEY (`id`),
KEY `idx_sci_campaign` (`campaign_id`),
KEY `idx_sci_status` (`status`),
KEY `idx_sci_article` (`article_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='عناصر حملة محتوى SEO (موضوع لكل عنصر) - Phase 24';

-- Missing table: `tax_rules`
CREATE TABLE IF NOT EXISTS `tax_rules` (
`id` INT(11) NOT NULL AUTO_INCREMENT,
`country_code` CHAR(2) NOT NULL COMMENT 'ISO 3166-1 alpha-2',
`tax_type` VARCHAR(30) NOT NULL COMMENT 'VAT / GST / Sales Tax / ...',
`tax_rate_percent` DECIMAL(5,2) NOT NULL COMMENT 'مثال: 14.00 يعني 14%',
`is_active` TINYINT(1) NOT NULL DEFAULT 1,
`created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
`updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
PRIMARY KEY (`id`),
UNIQUE KEY `uq_tax_rules_country_type` (`country_code`, `tax_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='قواعد الضريبة القابلة للتوسع حسب الدولة - فاضية افتراضيًا';

-- Missing table: `ad_ab_tests`
CREATE TABLE IF NOT EXISTS `ad_ab_tests` (
`id` INT(11) NOT NULL AUTO_INCREMENT,
`user_id` bigint(20) UNSIGNED NOT NULL COMMENT 'المالك/التينانت',
`campaign_id` int(11) NOT NULL,
`creative_id` INT(11) NOT NULL AUTO_INCREMENT NOT NULL COMMENT 'الأصل الإعلاني موضوع التجربة',
`name` VARCHAR(255) NOT NULL,
`status` ENUM('draft','running','completed','archived') NOT NULL DEFAULT 'draft',
`winning_variant_id` INT(11) DEFAULT NULL COMMENT 'تنويع الفائز بعد complete',
`started_at` TIMESTAMP NULL DEFAULT NULL,
`ended_at` TIMESTAMP NULL DEFAULT NULL,
`created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
`updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
PRIMARY KEY (`id`),
FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
FOREIGN KEY (`campaign_id`) REFERENCES `ad_campaigns`(`id`) ON DELETE CASCADE,
FOREIGN KEY (`creative_id`) REFERENCES `ad_creatives`(`id`) ON DELETE CASCADE,
INDEX `idx_user_campaign` (`user_id`, `campaign_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='تجارب A/B على تنويعات الأصول الإعلانية - بند 2';

-- Missing table: `ad_creative_variants`
CREATE TABLE IF NOT EXISTS `ad_creative_variants` (
`id` INT(11) NOT NULL AUTO_INCREMENT,
`user_id` bigint(20) UNSIGNED NOT NULL COMMENT 'المالك/التينانت - نفس قيمة creative.user_id',
`creative_id` INT(11) NOT NULL AUTO_INCREMENT NOT NULL,
`variant_label` VARCHAR(20) NOT NULL DEFAULT 'A' COMMENT 'A/B/C لاختبار التنويعات',
`headline` VARCHAR(255) DEFAULT NULL,
`primary_text` TEXT DEFAULT NULL,
`media_url` VARCHAR(500) DEFAULT NULL,
`impressions` BIGINT UNSIGNED NOT NULL DEFAULT 0,
`clicks` BIGINT UNSIGNED NOT NULL DEFAULT 0,
`spend` DECIMAL(12,2) NOT NULL DEFAULT 0,
`conversions` DECIMAL(12,2) NOT NULL DEFAULT 0,
`revenue` DECIMAL(12,2) NOT NULL DEFAULT 0,
`is_control` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'هل هو Variant التحكم (Control)؟',
`created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
`updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
PRIMARY KEY (`id`),
FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
FOREIGN KEY (`creative_id`) REFERENCES `ad_creatives`(`id`) ON DELETE CASCADE,
INDEX `idx_user_creative` (`user_id`, `creative_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='تنويعات الأصول الإعلانية وأداؤها الفعلي - بند 1';

-- Missing table: `biz_subscription_events`
CREATE TABLE IF NOT EXISTS `biz_subscription_events` (
`id` INT(11) NOT NULL AUTO_INCREMENT,
`user_id` INT(11) NOT NULL COMMENT 'صاحب الحساب (Tenant Isolation)',
`subscription_id` INT(11) NOT NULL AUTO_INCREMENT NOT NULL,
`event_type` ENUM('new', 'expansion', 'contraction', 'churn') NOT NULL,
`mrr_delta` DECIMAL(14,2) NOT NULL DEFAULT 0 COMMENT 'تغيّر MRR الناتج عن الحدث (موجب/سالب)',
`mrr_after` DECIMAL(14,2) NOT NULL DEFAULT 0 COMMENT 'إجمالي MRR الشهري للاشتراك بعد الحدث',
`occurred_at` DATE NOT NULL,
`notes` VARCHAR(500) DEFAULT NULL,
`created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
PRIMARY KEY (`id`),
INDEX `idx_biz_evt_user_date` (`user_id`, `occurred_at`),
INDEX `idx_biz_evt_sub` (`subscription_id`),
INDEX `idx_biz_evt_type` (`event_type`),
CONSTRAINT `fk_biz_evt_sub` FOREIGN KEY (`subscription_id`) REFERENCES `biz_subscriptions`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='أحداث تغيير اشتراكات عملاء العميل (MRR Breakdown + NRR/GRR)';

-- Missing table: `business_ai_context`
CREATE TABLE IF NOT EXISTS `business_ai_context` (
`id` INT(11) NOT NULL AUTO_INCREMENT,
`business_id` INT(11) NOT NULL AUTO_INCREMENT NOT NULL,
`business_summary` TEXT DEFAULT NULL COMMENT 'نبذة عامة قصيرة عن الشركة - يُستخدم كنقطة بداية لأي محتوى AI',
`brand_description` TEXT DEFAULT NULL,
`target_audience` TEXT DEFAULT NULL COMMENT 'وصف نصي حر (يكمّل business_target_markets المُهيكل، مش بديل له)',
`unique_selling_points` JSON DEFAULT NULL COMMENT 'مصفوفة نصوص - إيه اللي بيميز الشركة',
`brand_voice` VARCHAR(50) DEFAULT NULL COMMENT 'professional, friendly, luxury, adventure, family, corporate, custom - القائمة الكاملة في Phase 7 Brand Settings',
`preferred_tone` VARCHAR(100) DEFAULT NULL,
`forbidden_claims` JSON DEFAULT NULL COMMENT 'مصفوفة نصوص - ادعاءات ممنوع الـAI يكتبها (قانونية/تسويقية)',
`preferred_keywords` JSON DEFAULT NULL COMMENT 'مصفوفة كلمات مفتاحية مفضّلة لـSEO',
`business_goals` JSON DEFAULT NULL COMMENT 'مصفوفة نصوص',
`seo_goals` JSON DEFAULT NULL COMMENT 'مصفوفة نصوص',
`content_goals` JSON DEFAULT NULL COMMENT 'مصفوفة نصوص',
`competitors` JSON DEFAULT NULL COMMENT 'مصفوفة {name, url} objects',
`important_notes` TEXT DEFAULT NULL COMMENT 'أي حاجة تانية المستخدم عايز الـAI يعرفها ومفيهاش عمود مخصص',
`created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
`updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
PRIMARY KEY (`id`),
UNIQUE KEY `uq_ai_context_business` (`business_id`),
CONSTRAINT `fk_ai_context_business` FOREIGN KEY (`business_id`) REFERENCES `businesses` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='AI Business Context (Business Control Center - Phase 6) - المكوّن الاستراتيجي/الصوتي، يُجمّع مع باقي الجداول عبر BusinessContextService';

-- Missing table: `business_api_keys`
CREATE TABLE IF NOT EXISTS `business_api_keys` (
`id` INT(11) NOT NULL AUTO_INCREMENT,
`business_id` INT(11) NOT NULL AUTO_INCREMENT NOT NULL,
`created_by_user_id` INT(11) DEFAULT NULL COMMENT 'من أنشأ المفتاح - NULL لو اتُحذف الحساب (المفتاح بيتبع الـBusiness مش المنشئ)',
`name` VARCHAR(120) NOT NULL,
`key_prefix` VARCHAR(24) NOT NULL COMMENT 'بادئة معروضة للتعرف على المفتاح دون كشف hash',
`key_hash` VARCHAR(255) NOT NULL,
-- read: قراءة فقط. write: قراءة + كتابة (يُتحقق منها في الكود)
    `scope` VARCHAR(20) NOT NULL DEFAULT 'read' COMMENT 'read, write - يُتحقق منها في الكود',
`last_used_at` DATETIME DEFAULT NULL,
`revoked_at` DATETIME DEFAULT NULL,
`created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
`updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
PRIMARY KEY (`id`),
INDEX `idx_business_api_key_business` (`business_id`),
INDEX `idx_business_api_key_prefix` (`key_prefix`),
CONSTRAINT `fk_business_api_key_business` FOREIGN KEY (`business_id`) REFERENCES `businesses` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Business API Keys - مفاتيح برمجية مرتبطة بالـBusiness (Business Control Center)';

-- Missing table: `business_audit_logs`
CREATE TABLE IF NOT EXISTS `business_audit_logs` (
`id` INT(11) NOT NULL AUTO_INCREMENT,
`business_id` INT(11) NOT NULL AUTO_INCREMENT NOT NULL,
`actor_user_id` bigint(20) UNSIGNED DEFAULT NULL COMMENT 'من نفّذ الحدث (NULL للأنظمة/الجداول)',
`action` VARCHAR(80) NOT NULL COMMENT 'business_updated, location_created, member_invited, api_key_revoked...',
`object_type` VARCHAR(50) DEFAULT NULL COMMENT 'business, location, service, member, api_key...',
`object_id` VARCHAR(64) DEFAULT NULL,
`result` VARCHAR(20) NOT NULL DEFAULT 'success' COMMENT 'success, failed',
`meta` JSON DEFAULT NULL,
`ip_address` VARCHAR(45) DEFAULT NULL,
`user_agent` VARCHAR(255) DEFAULT NULL,
`created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
PRIMARY KEY (`id`),
INDEX `idx_business_audit_business_time` (`business_id`, `created_at`),
INDEX `idx_business_audit_action` (`action`),
INDEX `idx_business_audit_actor` (`actor_user_id`),
CONSTRAINT `fk_business_audit_business` FOREIGN KEY (`business_id`) REFERENCES `businesses` (`id`) ON DELETE CASCADE,
CONSTRAINT `fk_business_audit_actor` FOREIGN KEY (`actor_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Business Audit Log - سجل موحّد لأحداث الـBusiness (Business Control Center)';

-- Missing table: `business_brand_settings`
CREATE TABLE IF NOT EXISTS `business_brand_settings` (
`id` INT(11) NOT NULL AUTO_INCREMENT,
`business_id` INT(11) NOT NULL AUTO_INCREMENT NOT NULL,
`favicon_url` VARCHAR(500) DEFAULT NULL,
`brand_colors` JSON DEFAULT NULL COMMENT '{"primary": "#RRGGBB", "secondary": "#RRGGBB", "accent": "#RRGGBB"}',
`font_preference` VARCHAR(100) DEFAULT NULL,
`writing_style` TEXT DEFAULT NULL COMMENT 'وصف حر لأسلوب الكتابة - ده اللي بيُستخدم لو brand_voice = custom (راجع BusinessAiContext::allowedBrandVoicePresets)',
`preferred_terminology` JSON DEFAULT NULL COMMENT 'مصفوفة {term, use_instead} أو نصوص حرة - مصطلحات مفضّلة',
`prohibited_terminology` JSON DEFAULT NULL COMMENT 'مصطلحات/كلمات ممنوع استخدامها - مختلفة عن business_ai_context.forbidden_claims (دي كلمات/أسلوب، مش ادعاءات تسويقية)',
`created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
`updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
PRIMARY KEY (`id`),
UNIQUE KEY `uq_brand_settings_business` (`business_id`),
CONSTRAINT `fk_brand_settings_business` FOREIGN KEY (`business_id`) REFERENCES `businesses` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='إعدادات العلامة التجارية - حقول جديدة فقط، بدون تكرار logo_url/brand_voice الموجودين (Business Control Center - Phase 7)';

-- Missing table: `business_locations`
CREATE TABLE IF NOT EXISTS `business_locations` (
`id` INT(11) NOT NULL AUTO_INCREMENT,
`business_id` INT(11) NOT NULL AUTO_INCREMENT NOT NULL,
`name` VARCHAR(255) DEFAULT NULL COMMENT 'اسم الفرع/الموقع (مثلاً: Cairo HQ, Luxor Branch)',
`country_code` VARCHAR(2) DEFAULT NULL COMMENT 'ISO 3166-1 alpha-2',
`city` VARCHAR(150) DEFAULT NULL,
`address` VARCHAR(500) DEFAULT NULL,
`postal_code` VARCHAR(20) DEFAULT NULL,
`latitude` DECIMAL(10,7) DEFAULT NULL,
`longitude` DECIMAL(10,7) DEFAULT NULL,
`phone` VARCHAR(50) DEFAULT NULL,
`email` VARCHAR(255) DEFAULT NULL,
`is_primary` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'مقر رئيسي واحد بس لكل Business - يُفرض في الكود (Service layer) مش DB constraint',
`opening_hours` JSON DEFAULT NULL COMMENT 'مصفوفة أيام/ساعات، اختياري بالكامل',
`created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
`updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
PRIMARY KEY (`id`),
INDEX `idx_location_business` (`business_id`),
INDEX `idx_location_primary` (`business_id`, `is_primary`),
CONSTRAINT `fk_location_business` FOREIGN KEY (`business_id`) REFERENCES `businesses` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='مواقع الشركة الفعلية (Business Control Center - Phase 3)';

-- Missing table: `business_members`
CREATE TABLE IF NOT EXISTS `business_members` (
`id` INT(11) NOT NULL AUTO_INCREMENT,
`business_id` INT(11) NOT NULL AUTO_INCREMENT NOT NULL,
`user_id` INT(11) DEFAULT NULL COMMENT 'NULL للدعوات غير المقبولة بعد',
-- الأدوار: owner مش مخزّن هنا (بيتعرف من businesses.owner_user_id) -
    -- ده الجدول بيخزن admin/member/viewer بس، والأدوار بتتحقق في الكود
    -- (BusinessAccessService) مش على مستوى الداتابيز.
    `role` VARCHAR(20) NOT NULL DEFAULT 'viewer'
        COMMENT 'admin, member, viewer - يُتحقق منها في الكود',
-- active: عضو فعلي. invited: دعوة معلقة (لسه ما قُبلتش).
    `status` VARCHAR(20) NOT NULL DEFAULT 'invited'
        COMMENT 'active, invited',
-- معلومات الدعوة (بتفضل null للأعضاء المضافين مباشرة)
    `invited_by_user_id` INT(11) DEFAULT NULL,
`invited_email` VARCHAR(255) DEFAULT NULL
        COMMENT 'بريد المدعو - إلزامي للدعوات الغير مسجلة (user_id NULL)',
`invite_token` VARCHAR(64) DEFAULT NULL COMMENT 'توكن قبول الدعوة - فريد للدعوات المعلقة',
`invite_expires_at` DATETIME DEFAULT NULL COMMENT 'صلاحية الدعوة (افتراضيًا 7 أيام)',
`created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
`updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
PRIMARY KEY (`id`),
UNIQUE KEY `uq_business_user` (`business_id`, `user_id`)
        COMMENT 'منع التكرار: مستخدم واحد كعضو لنفس الـBusiness',
UNIQUE KEY `uq_invite_token` (`invite_token`)
        COMMENT 'منع تصادم توكنات الدعوات - null مسموح (صفوف متعددة) في MySQL',
INDEX `idx_member_user` (`user_id`),
INDEX `idx_member_status` (`status`),
CONSTRAINT `fk_member_business` FOREIGN KEY (`business_id`) REFERENCES `businesses` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Team Members + RBAC - أعضاء فريق الـBusiness وأدوارهم (Business Control Center)';

-- Missing table: `business_services`
CREATE TABLE IF NOT EXISTS `business_services` (
`id` INT(11) NOT NULL AUTO_INCREMENT,
`business_id` INT(11) NOT NULL AUTO_INCREMENT NOT NULL,
`name` VARCHAR(255) NOT NULL,
`slug` VARCHAR(255) NOT NULL,
`description` TEXT DEFAULT NULL,
`category` VARCHAR(100) DEFAULT NULL COMMENT 'قيمة حرة، قائمة مقترحة في الكود مش قيد DB',
`active` TINYINT(1) NOT NULL DEFAULT 1,
`target_markets` JSON DEFAULT NULL COMMENT 'مصفوفة ISO 3166-1 country codes',
`target_languages` JSON DEFAULT NULL COMMENT 'مصفوفة ISO 639-1 language codes',
`created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
`updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
PRIMARY KEY (`id`),
UNIQUE KEY `uq_business_service_slug` (`business_id`, `slug`),
INDEX `idx_service_business` (`business_id`),
INDEX `idx_service_active` (`business_id`, `active`),
INDEX `idx_service_category` (`category`),
CONSTRAINT `fk_service_business` FOREIGN KEY (`business_id`) REFERENCES `businesses` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='خدمات الشركة السياحية (Business Control Center - Phase 4)';

-- Missing table: `business_target_markets`
CREATE TABLE IF NOT EXISTS `business_target_markets` (
`id` INT(11) NOT NULL AUTO_INCREMENT,
`business_id` INT(11) NOT NULL AUTO_INCREMENT NOT NULL,
`target_countries` JSON DEFAULT NULL COMMENT 'مصفوفة ISO 3166-1 alpha-2 country codes',
`target_cities` JSON DEFAULT NULL COMMENT 'مصفوفة نصوص حرة (مدن كبرى مستهدفة)',
`target_languages` JSON DEFAULT NULL COMMENT 'مصفوفة ISO 639-1 language codes',
`customer_type` VARCHAR(10) DEFAULT NULL COMMENT 'b2b, b2c, both',
`customer_segments` JSON DEFAULT NULL COMMENT 'مصفوفة نصوص حرة (luxury travelers, backpackers...)',
`created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
`updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
PRIMARY KEY (`id`),
UNIQUE KEY `uq_target_markets_business` (`business_id`),
CONSTRAINT `fk_target_markets_business` FOREIGN KEY (`business_id`) REFERENCES `businesses` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='الأسواق المستهدفة للشركة - Single Source of Truth لـPhase 6 AI Context (Business Control Center - Phase 5)';

-- Missing table: `ci_changes`
CREATE TABLE IF NOT EXISTS `ci_changes` (
`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
`competitor_id` bigint(20) UNSIGNED NOT NULL,
`user_id` bigint(20) UNSIGNED NOT NULL,
`page_type` ENUM('homepage','pricing','products','services','landing','blog','contact','offers') NOT NULL,
`change_type` ENUM('new_page','removed_page','headline_change','offer_change','pricing_change','new_product','content_change','announcement','other') NOT NULL,
`severity` ENUM('low','medium','high','critical') NOT NULL DEFAULT 'low',
`previous_value` MEDIUMTEXT DEFAULT NULL,
`new_value` MEDIUMTEXT DEFAULT NULL,
`source_url` VARCHAR(1000) DEFAULT NULL,
`confidence` ENUM('high','medium','low') NOT NULL DEFAULT 'medium',
`snapshot_before_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT DEFAULT NULL,
`snapshot_after_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT DEFAULT NULL,
`detected_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
PRIMARY KEY (`id`),
KEY `idx_ci_change_competitor_date` (`competitor_id`, `detected_at`),
KEY `idx_ci_change_user_date` (`user_id`, `detected_at`),
KEY `idx_ci_change_severity` (`severity`),
FOREIGN KEY (`competitor_id`) REFERENCES `competitors`(`id`) ON DELETE CASCADE,
FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
FOREIGN KEY (`snapshot_before_id`) REFERENCES `ci_snapshots`(`id`) ON DELETE SET NULL,
FOREIGN KEY (`snapshot_after_id`) REFERENCES `ci_snapshots`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='سجل تاريخي دائم للتغييرات المكتشفة - لا يُحذف أو يُستبدل';

-- Missing table: `crm_activities`
CREATE TABLE IF NOT EXISTS `crm_activities` (
`id` INT(11) NOT NULL AUTO_INCREMENT,
`user_id` bigint(20) UNSIGNED NOT NULL COMMENT 'صاحب الحساب (Tenant)',
`activity_type_id` INT(11) NOT NULL AUTO_INCREMENT NOT NULL,
`related_type` VARCHAR(30) DEFAULT NULL COMMENT 'contact / lead / deal / company',
`related_id` INT(11) DEFAULT NULL,
`subject` VARCHAR(255) NOT NULL,
`notes` TEXT DEFAULT NULL,
`performed_at` TIMESTAMP NULL DEFAULT NULL COMMENT 'متى حدث النشاط فعليًا',
`created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
PRIMARY KEY (`id`),
INDEX `idx_crm_acts_user` (`user_id`),
INDEX `idx_crm_acts_type` (`activity_type_id`),
INDEX `idx_crm_acts_related` (`related_type`, `related_id`),
CONSTRAINT `fk_crm_acts_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
CONSTRAINT `fk_crm_acts_type` FOREIGN KEY (`activity_type_id`) REFERENCES `crm_activity_types` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='سجلات الأنشطة المخصصة - المرحلة 14 (G10)';

-- Missing table: `crm_messages`
CREATE TABLE IF NOT EXISTS `crm_messages` (
`id` INT(11) NOT NULL AUTO_INCREMENT,
`conversation_id` INT(11) NOT NULL AUTO_INCREMENT NOT NULL,
`direction` ENUM('inbound', 'outbound') NOT NULL,
`sender_user_id` INT(11) DEFAULT NULL COMMENT 'لو الرسالة outbound من عندنا',
`body` TEXT NOT NULL,
`subject` VARCHAR(255) DEFAULT NULL COMMENT 'للإيميل فقط',
`status` VARCHAR(30) NOT NULL DEFAULT 'sent' COMMENT 'sent, delivered, read, failed',
`external_message_id` VARCHAR(255) DEFAULT NULL,
`error` VARCHAR(500) DEFAULT NULL,
`sent_at` DATETIME NOT NULL,
`created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
PRIMARY KEY (`id`),
INDEX `idx_crm_msg_conversation` (`conversation_id`),
CONSTRAINT `fk_crm_msg_conversation` FOREIGN KEY (`conversation_id`) REFERENCES `crm_conversations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='رسائل محادثات CRM';

-- Missing table: `crm_custom_field_values`
CREATE TABLE IF NOT EXISTS `crm_custom_field_values` (
`id` INT(11) NOT NULL AUTO_INCREMENT,
`user_id` bigint(20) UNSIGNED NOT NULL COMMENT 'صاحب الحساب (Tenant) - للتحديد السريع في البحث',
`entity_type` ENUM('contact', 'lead', 'deal', 'company') NOT NULL,
`entity_id` INT(11) NOT NULL,
`field_id` INT(11) NOT NULL AUTO_INCREMENT NOT NULL,
`value` VARCHAR(500) DEFAULT NULL COMMENT 'القيمة المخزنة كنص (أي نوع)',
`updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
PRIMARY KEY (`id`),
UNIQUE KEY `uq_crm_cfv_entity_field` (`entity_type`, `entity_id`, `field_id`),
INDEX `idx_crm_cfv_user_type` (`user_id`, `entity_type`),
CONSTRAINT `fk_crm_cfv_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
CONSTRAINT `fk_crm_cfv_field` FOREIGN KEY (`field_id`) REFERENCES `crm_custom_fields` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='قيم الحقول المخصصة لكل سجل - المرحلة 12 (G2)';

-- Missing table: `bookings`
CREATE TABLE IF NOT EXISTS `bookings` (
`id` INT(11) NOT NULL AUTO_INCREMENT,
`booking_reference` VARCHAR(32) NOT NULL COMMENT 'مرجع فريد يظهر للعميل - مش الـ id الداخلي',
`user_id` INT(11) NOT NULL COMMENT 'صاحب الحساب (الشركة) - نفس اصطلاح crm_products.user_id',
`product_id` INT(11) NOT NULL AUTO_INCREMENT NOT NULL COMMENT 'يشير إلى crm_products.id',
`customer_id` INT(11) NULL DEFAULT NULL COMMENT 'يشير إلى crm_contacts.id لو موجود، NULL لحجز بدون CRM contact',
`customer_name` VARCHAR(191) NOT NULL,
`customer_phone` VARCHAR(32) NULL DEFAULT NULL,
`customer_email` VARCHAR(191) NULL DEFAULT NULL,
`start_date` DATE NOT NULL,
`start_time` TIME NULL DEFAULT NULL,
`adults_count` INT(11) NOT NULL DEFAULT 1,
`children_count` INT(11) NOT NULL DEFAULT 0,
`total_amount` DECIMAL(12,2) NOT NULL,
`currency` VARCHAR(10) NOT NULL DEFAULT 'USD',
`status` ENUM('pending','confirmed','cancelled','completed','no_show') NOT NULL DEFAULT 'pending',
`source` VARCHAR(30) NOT NULL DEFAULT 'direct' COMMENT 'direct / whatsapp / website / ota:<name>',
`notes` TEXT NULL DEFAULT NULL,
`created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
`updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
PRIMARY KEY (`id`),
UNIQUE KEY `uq_bookings_reference` (`booking_reference`),
KEY `idx_bookings_user_id` (`user_id`),
KEY `idx_bookings_product_id` (`product_id`),
KEY `idx_bookings_start_date` (`start_date`),
KEY `idx_bookings_status` (`status`),
CONSTRAINT `fk_bookings_product` FOREIGN KEY (`product_id`) REFERENCES `crm_products` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='حجوزات السياح النهائيين على خدمات/جولات الحساب';

-- Missing table: `crm_deal_items`
CREATE TABLE IF NOT EXISTS `crm_deal_items` (
`id` INT(11) NOT NULL AUTO_INCREMENT,
`user_id` bigint(20) UNSIGNED NOT NULL COMMENT 'صاحب الحساب (Tenant)',
`deal_id` int(11) NOT NULL,
`product_id` INT(11) NOT NULL AUTO_INCREMENT DEFAULT NULL COMMENT 'NULL لو بند حر (غير مرتبط بمنتج)',
`product_name` VARCHAR(255) NOT NULL COMMENT 'لقطة اسم المنتج وقت الإضافة',
`description` TEXT DEFAULT NULL,
`unit_price` DECIMAL(14,2) NOT NULL DEFAULT 0,
`quantity` DECIMAL(12,2) NOT NULL DEFAULT 1,
`discount` DECIMAL(14,2) NOT NULL DEFAULT 0 COMMENT 'خصم نقدي على السطر',
`line_total` DECIMAL(14,2) NOT NULL DEFAULT 0 COMMENT '(unit_price * quantity) - discount',
`created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
PRIMARY KEY (`id`),
INDEX `idx_crm_deal_items_deal` (`deal_id`),
INDEX `idx_crm_deal_items_user` (`user_id`),
CONSTRAINT `fk_crm_deal_items_deal` FOREIGN KEY (`deal_id`) REFERENCES `crm_deals` (`id`) ON DELETE CASCADE,
CONSTRAINT `fk_crm_deal_items_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
CONSTRAINT `fk_crm_deal_items_product` FOREIGN KEY (`product_id`) REFERENCES `crm_products` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='بنود الصفقات (Line Items) - المرحلة 13 (G3)';

-- Missing table: `inventory`
CREATE TABLE IF NOT EXISTS `inventory` (
`id` INT(11) NOT NULL AUTO_INCREMENT,
`user_id` INT(11) NOT NULL,
`product_id` INT(11) NOT NULL AUTO_INCREMENT NOT NULL COMMENT 'يشير إلى crm_products.id',
`date` DATE NOT NULL,
`capacity` INT(11) NOT NULL DEFAULT 0 COMMENT 'أقصى عدد حجوزات مسموح بيه في هذا التاريخ',
`booked_count` INT(11) NOT NULL DEFAULT 0 COMMENT 'بيتحدّث تلقائيًا مع كل حجز/إلغاء - لا يُعدَّل يدويًا',
`price_override` DECIMAL(12,2) NULL DEFAULT NULL COMMENT 'سعر مخصص لليوم ده لو مختلف عن crm_products.price (تسعير ديناميكي)',
`is_blocked` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'إغلاق اليوم يدويًا حتى لو فيه سعة',
`created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
`updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
PRIMARY KEY (`id`),
UNIQUE KEY `uq_inventory_product_date` (`product_id`, `date`),
KEY `idx_inventory_user_id` (`user_id`),
CONSTRAINT `fk_inventory_product` FOREIGN KEY (`product_id`) REFERENCES `crm_products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='التوفر اليومي لكل خدمة/جولة';

-- Missing table: `crm_web_form_submissions`
CREATE TABLE IF NOT EXISTS `crm_web_form_submissions` (
`id` INT(11) NOT NULL AUTO_INCREMENT,
`user_id` bigint(20) UNSIGNED NOT NULL COMMENT 'صاحب الحساب (Tenant)',
`web_form_id` INT(11) NOT NULL AUTO_INCREMENT NOT NULL,
`contact_id` INT(11) DEFAULT NULL COMMENT 'الـContact المُنشأ من الإرسال',
`lead_id` INT(11) DEFAULT NULL COMMENT 'الـLead المُنشأ من الإرسال',
`payload` TEXT DEFAULT NULL COMMENT 'JSON: البيانات المُرسلة',
`ip_address` VARCHAR(45) DEFAULT NULL,
`user_agent` VARCHAR(255) DEFAULT NULL,
`created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
PRIMARY KEY (`id`),
INDEX `idx_crm_wfs_user` (`user_id`),
INDEX `idx_crm_wfs_form` (`web_form_id`),
CONSTRAINT `fk_crm_wfs_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
CONSTRAINT `fk_crm_wfs_form` FOREIGN KEY (`web_form_id`) REFERENCES `crm_web_forms` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='إرسالات نماذج الويب - المرحلة 15 (G11)';

-- Missing table: `email_automation_entries`
CREATE TABLE IF NOT EXISTS `email_automation_entries` (
`id` int(11) NOT NULL AUTO_INCREMENT,
`automation_id` int(11) NOT NULL AUTO_INCREMENT NOT NULL,
`user_id` int(11) NOT NULL,
`subscriber_id` int(11) NOT NULL,
`step_position` int(11) NOT NULL DEFAULT 0,
`status` varchar(16) NOT NULL DEFAULT 'active',
`context` text DEFAULT NULL COMMENT 'JSON سياق الحدث',
`entered_at` timestamp NOT NULL DEFAULT current_timestamp(),
`next_run_at` datetime DEFAULT NULL,
`last_processed_at` datetime DEFAULT NULL,
`completed_at` datetime DEFAULT NULL,
`created_at` timestamp NOT NULL DEFAULT current_timestamp(),
`updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
PRIMARY KEY (`id`),
KEY `idx_email_automation_entries_auto_sub` (`automation_id`, `subscriber_id`),
KEY `idx_email_automation_entries_due` (`status`, `next_run_at`),
KEY `idx_email_automation_entries_user` (`user_id`),
CONSTRAINT `fk_email_automation_entries_auto` FOREIGN KEY (`automation_id`)
        REFERENCES `email_automations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='مشاركون في سير العمل';

-- Missing table: `email_automation_logs`
CREATE TABLE IF NOT EXISTS `email_automation_logs` (
`id` INT(11) NOT NULL AUTO_INCREMENT,
`user_id` bigint(20) UNSIGNED NOT NULL,
`automation_id` int(11) NOT NULL AUTO_INCREMENT NOT NULL,
`entry_id` INT(11) NULL DEFAULT NULL,
`step_id` INT(11) NULL DEFAULT NULL,
`subscriber_id` INT(11) NULL DEFAULT NULL,
`to_email` VARCHAR(191) NOT NULL,
`to_name` VARCHAR(191) NULL DEFAULT NULL,
`subject` VARCHAR(255) NOT NULL DEFAULT '',
`status` VARCHAR(16) NOT NULL DEFAULT 'sent',
`error` VARCHAR(1000) NULL DEFAULT NULL,
`open_token` VARCHAR(64) NULL DEFAULT NULL,
`click_token` VARCHAR(64) NULL DEFAULT NULL,
`opened_at` DATETIME NULL DEFAULT NULL,
`clicked_at` DATETIME NULL DEFAULT NULL,
`open_count` INT(11) NOT NULL DEFAULT 0,
`click_count` INT(11) NOT NULL DEFAULT 0,
`created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
PRIMARY KEY (`id`),
KEY `idx_email_auto_logs_user` (`user_id`),
KEY `idx_email_auto_logs_auto` (`automation_id`),
KEY `idx_email_auto_logs_sub` (`subscriber_id`),
KEY `idx_email_auto_logs_open` (`open_token`),
KEY `idx_email_auto_logs_click` (`click_token`),
CONSTRAINT `fk_email_auto_logs_user` FOREIGN KEY (`user_id`)
        REFERENCES `users` (`id`) ON DELETE CASCADE,
CONSTRAINT `fk_email_auto_logs_auto` FOREIGN KEY (`automation_id`)
        REFERENCES `email_automations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='سجل رسائل الأتمتة وتتبع التفاعل';

-- Missing table: `email_automation_steps`
CREATE TABLE IF NOT EXISTS `email_automation_steps` (
`id` int(11) NOT NULL AUTO_INCREMENT,
`automation_id` int(11) NOT NULL AUTO_INCREMENT NOT NULL,
`position` int(11) NOT NULL DEFAULT 0,
`step_type` varchar(32) NOT NULL DEFAULT 'wait',
`step_value` text DEFAULT NULL COMMENT 'JSON: wait.days/email.subject/email.html/tag/list_id',
`created_at` timestamp NOT NULL DEFAULT current_timestamp(),
PRIMARY KEY (`id`),
KEY `idx_email_automation_steps_auto` (`automation_id`),
CONSTRAINT `fk_email_automation_steps_auto` FOREIGN KEY (`automation_id`)
        REFERENCES `email_automations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='خطوات سير العمل';

-- Missing table: `email_campaigns`
CREATE TABLE IF NOT EXISTS `email_campaigns` (
`id` INT(11) NOT NULL AUTO_INCREMENT,
`user_id` bigint(20) UNSIGNED NOT NULL,
`name` VARCHAR(191) NOT NULL,
`subject` VARCHAR(255) NOT NULL,
`from_name` VARCHAR(191) NULL DEFAULT NULL,
`from_email` VARCHAR(191) NULL DEFAULT NULL COMMENT 'NULL = سيرفر الـ SMTP من .env',
`template_id` INT(11) NULL DEFAULT NULL COMMENT 'مصدر الـ HTML اختياري',
`list_id` INT(11) NOT NULL AUTO_INCREMENT NULL DEFAULT NULL COMMENT 'الجمهور: قائمة محددة',
`audience_ids` JSON NULL DEFAULT NULL COMMENT 'بديل: قائمة قوائم مستهدفة',
`html_body` MEDIUMTEXT NULL DEFAULT NULL,
`status` ENUM('draft','scheduled','sending','sent','cancelled','failed') NOT NULL DEFAULT 'draft',
`scheduled_at` DATETIME NULL DEFAULT NULL,
`sent_at` DATETIME NULL DEFAULT NULL,
`total_recipients` INT(11) NOT NULL DEFAULT 0,
`sent_count` INT(11) NOT NULL DEFAULT 0,
`opened_count` INT(11) NOT NULL DEFAULT 0,
`clicked_count` INT(11) NOT NULL DEFAULT 0,
`unsubscribed_count` INT(11) NOT NULL DEFAULT 0,
`bounced_count` INT(11) NOT NULL DEFAULT 0,
`error_message` TEXT NULL DEFAULT NULL,
`created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
`updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
PRIMARY KEY (`id`),
KEY `idx_email_campaigns_user_id` (`user_id`),
KEY `idx_email_campaigns_status` (`status`),
CONSTRAINT `fk_email_campaigns_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
CONSTRAINT `fk_email_campaigns_list` FOREIGN KEY (`list_id`) REFERENCES `email_lists` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='حملات تسويق البريد';

-- Missing table: `email_list_subscriber`
CREATE TABLE IF NOT EXISTS `email_list_subscriber` (
`list_id` INT(11) NOT NULL AUTO_INCREMENT NOT NULL,
`subscriber_id` INT(11) NOT NULL AUTO_INCREMENT NOT NULL,
`created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
PRIMARY KEY (`list_id`, `subscriber_id`),
KEY `idx_els_subscriber_id` (`subscriber_id`),
CONSTRAINT `fk_els_list` FOREIGN KEY (`list_id`) REFERENCES `email_lists` (`id`) ON DELETE CASCADE,
CONSTRAINT `fk_els_subscriber` FOREIGN KEY (`subscriber_id`) REFERENCES `email_subscribers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='ربط المشترك بالقوائم';

-- Missing table: `email_subscriber_custom_values`
CREATE TABLE IF NOT EXISTS `email_subscriber_custom_values` (
`id` INT(11) NOT NULL AUTO_INCREMENT,
`subscriber_id` INT(11) NOT NULL AUTO_INCREMENT NOT NULL,
`field_id` INT(11) NOT NULL AUTO_INCREMENT NOT NULL,
`value` TEXT NULL DEFAULT NULL,
`updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
PRIMARY KEY (`id`),
UNIQUE KEY `uq_email_scv_subscriber_field` (`subscriber_id`, `field_id`),
KEY `idx_email_scv_field_id` (`field_id`),
CONSTRAINT `fk_email_scv_subscriber` FOREIGN KEY (`subscriber_id`) REFERENCES `email_subscribers` (`id`) ON DELETE CASCADE,
CONSTRAINT `fk_email_scv_field` FOREIGN KEY (`field_id`) REFERENCES `email_custom_fields` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='قيم الحقول المخصصة للمشتركين';

-- Missing table: `email_subscriber_tag`
CREATE TABLE IF NOT EXISTS `email_subscriber_tag` (
`subscriber_id` INT(11) NOT NULL AUTO_INCREMENT NOT NULL,
`tag_id` INT(11) NOT NULL AUTO_INCREMENT NOT NULL,
`created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
PRIMARY KEY (`subscriber_id`, `tag_id`),
KEY `idx_email_st_tag_id` (`tag_id`),
CONSTRAINT `fk_email_st_subscriber` FOREIGN KEY (`subscriber_id`) REFERENCES `email_subscribers` (`id`) ON DELETE CASCADE,
CONSTRAINT `fk_email_st_tag` FOREIGN KEY (`tag_id`) REFERENCES `email_tags` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='ربط المشترك بالوسوم';

-- Missing table: `refunds`
CREATE TABLE IF NOT EXISTS `refunds` (
`id` INT(11) NOT NULL AUTO_INCREMENT,
`payment_transaction_id` INT(11) NOT NULL AUTO_INCREMENT NOT NULL,
`user_id` INT(11) NOT NULL,
`amount` DECIMAL(12,2) NOT NULL,
`currency` VARCHAR(10) NOT NULL DEFAULT 'USD',
`reason` VARCHAR(255) NULL DEFAULT NULL,
`status` ENUM('pending','processing','succeeded','failed') NOT NULL DEFAULT 'pending',
`gateway_refund_reference` VARCHAR(191) NULL DEFAULT NULL,
`created_by_admin_id` INT(11) NOT NULL,
`created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
`updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
PRIMARY KEY (`id`),
KEY `idx_refunds_payment_tx` (`payment_transaction_id`),
KEY `idx_refunds_user_id` (`user_id`),
FOREIGN KEY (`payment_transaction_id`) REFERENCES `payment_transactions`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='استرجاعات كاملة/جزئية لمعاملات دفع حقيقية';

-- Missing table: `sales_reps`
CREATE TABLE IF NOT EXISTS `sales_reps` (
`id` INT(11) NOT NULL AUTO_INCREMENT,
`user_id` bigint(20) UNSIGNED NOT NULL COMMENT 'صاحب الحساب (Tenant Isolation)',
`team_id` INT(11) NOT NULL AUTO_INCREMENT DEFAULT NULL,
`name` VARCHAR(255) NOT NULL,
`email` VARCHAR(255) DEFAULT NULL,
`is_active` TINYINT(1) NOT NULL DEFAULT 1,
`created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
`updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
PRIMARY KEY (`id`),
INDEX `idx_sales_rep_user` (`user_id`),
INDEX `idx_sales_rep_team` (`team_id`),
CONSTRAINT `fk_sales_rep_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
CONSTRAINT `fk_sales_rep_team` FOREIGN KEY (`team_id`) REFERENCES `sales_teams`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='مندوبي البيع';

-- Missing table: `ad_ab_test_variants`
CREATE TABLE IF NOT EXISTS `ad_ab_test_variants` (
`id` INT(11) NOT NULL AUTO_INCREMENT,
`user_id` bigint(20) UNSIGNED NOT NULL,
`ab_test_id` INT(11) NOT NULL AUTO_INCREMENT NOT NULL,
`creative_variant_id` INT(11) NOT NULL AUTO_INCREMENT NOT NULL COMMENT 'التنويع من ad_creative_variants',
`weight_pct` INT(11) NOT NULL DEFAULT 50 COMMENT 'النسبة المئوية لتوزيع الحركة (50/50...)',
`is_control` TINYINT(1) NOT NULL DEFAULT 0,
`created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
PRIMARY KEY (`id`),
FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
FOREIGN KEY (`ab_test_id`) REFERENCES `ad_ab_tests`(`id`) ON DELETE CASCADE,
FOREIGN KEY (`creative_variant_id`) REFERENCES `ad_creative_variants`(`id`) ON DELETE CASCADE,
UNIQUE KEY `idx_test_variant` (`ab_test_id`, `creative_variant_id`),
INDEX `idx_user_test` (`user_id`, `ab_test_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='أذرع تجربة A/B وأوزانها النسبية - بند 2';

-- Missing table: `ci_alerts`
CREATE TABLE IF NOT EXISTS `ci_alerts` (
`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
`user_id` bigint(20) UNSIGNED NOT NULL,
`competitor_id` bigint(20) UNSIGNED NOT NULL,
`change_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT DEFAULT NULL,
`type` VARCHAR(60) NOT NULL COMMENT 'مثال: new_service / pricing_change / new_landing_page / activity_spike',
`severity` ENUM('info','low','medium','high','critical') NOT NULL DEFAULT 'medium',
`title` VARCHAR(255) NOT NULL,
`message` VARCHAR(1000) NOT NULL,
`channel` ENUM('dashboard','email','in_app') NOT NULL DEFAULT 'dashboard',
`is_read` TINYINT(1) NOT NULL DEFAULT 0,
`sent_at` TIMESTAMP NULL DEFAULT NULL,
`created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
PRIMARY KEY (`id`),
KEY `idx_ci_alert_user_read` (`user_id`, `is_read`),
KEY `idx_ci_alert_competitor` (`competitor_id`),
FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
FOREIGN KEY (`competitor_id`) REFERENCES `competitors`(`id`) ON DELETE CASCADE,
FOREIGN KEY (`change_id`) REFERENCES `ci_changes`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='تنبيهات Competitor Intelligence - كل تنبيه مربوط بتغيير مكتشف فعليًا';

-- Missing table: `agency_commissions`
CREATE TABLE IF NOT EXISTS `agency_commissions` (
`id` INT(11) NOT NULL AUTO_INCREMENT,
`agency_id` int(11) NOT NULL COMMENT 'يشير إلى agencies.id',
`agency_client_id` int(11) NOT NULL COMMENT 'يشير إلى agency_clients.id (ربط العميل)',
`booking_id` INT(11) NOT NULL AUTO_INCREMENT NOT NULL COMMENT 'يشير إلى bookings.id - حجز واحد = عمولة واحدة كحد أقصى',
`commission_amount` DECIMAL(12,2) NOT NULL COMMENT 'total_amount × commission_rate / 100',
`status` ENUM('pending','paid') NOT NULL DEFAULT 'pending',
`created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
`updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
PRIMARY KEY (`id`),
UNIQUE KEY `uniq_agency_commissions_booking` (`booking_id`),
KEY `idx_agency_commissions_agency` (`agency_id`),
KEY `idx_agency_commissions_client` (`agency_client_id`),
KEY `idx_agency_commissions_status` (`status`),
CONSTRAINT `fk_agency_commissions_agency` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
CONSTRAINT `fk_agency_commissions_client` FOREIGN KEY (`agency_client_id`) REFERENCES `agency_clients` (`id`) ON DELETE CASCADE,
CONSTRAINT `fk_agency_commissions_booking` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='عمولات الوكالات من حجوزات عملائها';

-- Missing table: `booking_status_history`
CREATE TABLE IF NOT EXISTS `booking_status_history` (
`id` INT(11) NOT NULL AUTO_INCREMENT,
`booking_id` INT(11) NOT NULL AUTO_INCREMENT NOT NULL,
`from_status` VARCHAR(20) NULL DEFAULT NULL,
`to_status` VARCHAR(20) NOT NULL,
`changed_by_user_id` INT(11) NULL DEFAULT NULL,
`reason` VARCHAR(255) NULL DEFAULT NULL,
`created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
PRIMARY KEY (`id`),
KEY `idx_booking_status_history_booking_id` (`booking_id`),
CONSTRAINT `fk_booking_status_history_booking` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='سجل تغييرات حالة كل حجز (Audit Trail)';

-- Missing table: `email_campaign_recipients`
CREATE TABLE IF NOT EXISTS `email_campaign_recipients` (
`id` INT(11) NOT NULL AUTO_INCREMENT,
`campaign_id` INT(11) NOT NULL AUTO_INCREMENT NOT NULL,
`subscriber_id` INT(11) NOT NULL AUTO_INCREMENT NULL DEFAULT NULL,
`email` VARCHAR(191) NOT NULL,
`name` VARCHAR(191) NULL DEFAULT NULL,
`status` ENUM('pending','sent','opened','clicked','unsubscribed','bounced','failed') NOT NULL DEFAULT 'pending',
`open_token` VARCHAR(64) NULL DEFAULT NULL COMMENT 'توكن فريد لتتبع الفتح (pixel)',
`click_token` VARCHAR(64) NULL DEFAULT NULL COMMENT 'توكن فريد لتتبع الكليك',
`opened_at` DATETIME NULL DEFAULT NULL,
`clicked_at` DATETIME NULL DEFAULT NULL,
`open_count` INT(11) NOT NULL DEFAULT 0,
`click_count` INT(11) NOT NULL DEFAULT 0,
`error_message` TEXT NULL DEFAULT NULL,
`created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
`updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
PRIMARY KEY (`id`),
UNIQUE KEY `uq_email_cr_open_token` (`open_token`),
UNIQUE KEY `uq_email_cr_click_token` (`click_token`),
KEY `idx_email_cr_campaign_id` (`campaign_id`),
KEY `idx_email_cr_status` (`status`),
CONSTRAINT `fk_email_cr_campaign` FOREIGN KEY (`campaign_id`) REFERENCES `email_campaigns` (`id`) ON DELETE CASCADE,
CONSTRAINT `fk_email_cr_subscriber` FOREIGN KEY (`subscriber_id`) REFERENCES `email_subscribers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='مستلمو الحملة وتتبع التفاعل';

-- Missing column: `users`.`last_login` (from 2026_01_01_000001_create_users_table.sql)
SET @tourfecto_add_column_sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='last_login') = 0,
  'ALTER TABLE `users` ADD COLUMN `last_login` TIMESTAMP NULL DEFAULT NULL COMMENT ''آخر تسجيل دخول'' 
AFTER `last_activity`',
  'SELECT 1'
);
PREPARE tourfecto_add_column_stmt FROM @tourfecto_add_column_sql;
EXECUTE tourfecto_add_column_stmt;
DEALLOCATE PREPARE tourfecto_add_column_stmt;

-- Missing column: `users`.`login_attempts` (from 2026_01_01_000001_create_users_table.sql)
SET @tourfecto_add_column_sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='login_attempts') = 0,
  'ALTER TABLE `users` ADD COLUMN `login_attempts` INT(11) DEFAULT 0 COMMENT ''عدد محاولات تسجيل الدخول'' 
AFTER `last_login`',
  'SELECT 1'
);
PREPARE tourfecto_add_column_stmt FROM @tourfecto_add_column_sql;
EXECUTE tourfecto_add_column_stmt;
DEALLOCATE PREPARE tourfecto_add_column_stmt;

-- Missing column: `users`.`blocked_until` (from 2026_01_01_000001_create_users_table.sql)
SET @tourfecto_add_column_sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='blocked_until') = 0,
  'ALTER TABLE `users` ADD COLUMN `blocked_until` TIMESTAMP NULL DEFAULT NULL COMMENT ''ممنوع حتى التاريخ'' 
AFTER `login_attempts`',
  'SELECT 1'
);
PREPARE tourfecto_add_column_stmt FROM @tourfecto_add_column_sql;
EXECUTE tourfecto_add_column_stmt;
DEALLOCATE PREPARE tourfecto_add_column_stmt;

-- Missing column: `subscriptions`.`invoice_id` (from 2026_01_01_000002_create_subscriptions_table.sql)
SET @tourfecto_add_column_sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='subscriptions' AND COLUMN_NAME='invoice_id') = 0,
  'ALTER TABLE `subscriptions` ADD COLUMN `invoice_id` INT(11) DEFAULT NULL COMMENT ''معرف الفاتورة الحالية'' 
AFTER `next_billing_at`',
  'SELECT 1'
);
PREPARE tourfecto_add_column_stmt FROM @tourfecto_add_column_sql;
EXECUTE tourfecto_add_column_stmt;
DEALLOCATE PREPARE tourfecto_add_column_stmt;

-- Missing column: `subscriptions`.`payment_method` (from 2026_01_01_000002_create_subscriptions_table.sql)
SET @tourfecto_add_column_sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='subscriptions' AND COLUMN_NAME='payment_method') = 0,
  'ALTER TABLE `subscriptions` ADD COLUMN `payment_method` VARCHAR(50) DEFAULT NULL COMMENT ''طريقة الدفع'' 
AFTER `invoice_id`',
  'SELECT 1'
);
PREPARE tourfecto_add_column_stmt FROM @tourfecto_add_column_sql;
EXECUTE tourfecto_add_column_stmt;
DEALLOCATE PREPARE tourfecto_add_column_stmt;

-- Missing column: `subscriptions`.`subscription_id_external` (from 2026_01_01_000002_create_subscriptions_table.sql)
SET @tourfecto_add_column_sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='subscriptions' AND COLUMN_NAME='subscription_id_external') = 0,
  'ALTER TABLE `subscriptions` ADD COLUMN `subscription_id_external` VARCHAR(255) DEFAULT NULL COMMENT ''معرف الاشتراك الخارجي'' 
AFTER `payment_gateway`',
  'SELECT 1'
);
PREPARE tourfecto_add_column_stmt FROM @tourfecto_add_column_sql;
EXECUTE tourfecto_add_column_stmt;
DEALLOCATE PREPARE tourfecto_add_column_stmt;

-- Missing column: `generated_websites`.`template_id` (from 2026_08_07_000001_upgrade_website_builder_pro.sql)
SET @tourfecto_add_column_sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='generated_websites' AND COLUMN_NAME='template_id') = 0,
  'ALTER TABLE `generated_websites` ADD COLUMN `template_id` INT(11) DEFAULT NULL AFTER `theme_color`',
  'SELECT 1'
);
PREPARE tourfecto_add_column_stmt FROM @tourfecto_add_column_sql;
EXECUTE tourfecto_add_column_stmt;
DEALLOCATE PREPARE tourfecto_add_column_stmt;

-- Missing column: `generated_websites`.`niche_key` (from 2026_08_07_000001_upgrade_website_builder_pro.sql)
SET @tourfecto_add_column_sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='generated_websites' AND COLUMN_NAME='niche_key') = 0,
  'ALTER TABLE `generated_websites` ADD COLUMN `niche_key` VARCHAR(40) DEFAULT NULL AFTER `template_id`',
  'SELECT 1'
);
PREPARE tourfecto_add_column_stmt FROM @tourfecto_add_column_sql;
EXECUTE tourfecto_add_column_stmt;
DEALLOCATE PREPARE tourfecto_add_column_stmt;

-- Missing column: `generated_websites`.`logo_url` (from 2026_08_07_000001_upgrade_website_builder_pro.sql)
SET @tourfecto_add_column_sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='generated_websites' AND COLUMN_NAME='logo_url') = 0,
  'ALTER TABLE `generated_websites` ADD COLUMN `logo_url` VARCHAR(255) DEFAULT NULL AFTER `content_json`',
  'SELECT 1'
);
PREPARE tourfecto_add_column_stmt FROM @tourfecto_add_column_sql;
EXECUTE tourfecto_add_column_stmt;
DEALLOCATE PREPARE tourfecto_add_column_stmt;

-- Missing column: `generated_websites`.`favicon_url` (from 2026_08_07_000001_upgrade_website_builder_pro.sql)
SET @tourfecto_add_column_sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='generated_websites' AND COLUMN_NAME='favicon_url') = 0,
  'ALTER TABLE `generated_websites` ADD COLUMN `favicon_url` VARCHAR(255) DEFAULT NULL AFTER `logo_url`',
  'SELECT 1'
);
PREPARE tourfecto_add_column_stmt FROM @tourfecto_add_column_sql;
EXECUTE tourfecto_add_column_stmt;
DEALLOCATE PREPARE tourfecto_add_column_stmt;

-- Missing column: `generated_websites`.`seo_title` (from 2026_08_07_000001_upgrade_website_builder_pro.sql)
SET @tourfecto_add_column_sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='generated_websites' AND COLUMN_NAME='seo_title') = 0,
  'ALTER TABLE `generated_websites` ADD COLUMN `seo_title` VARCHAR(160) DEFAULT NULL AFTER `favicon_url`',
  'SELECT 1'
);
PREPARE tourfecto_add_column_stmt FROM @tourfecto_add_column_sql;
EXECUTE tourfecto_add_column_stmt;
DEALLOCATE PREPARE tourfecto_add_column_stmt;

-- Missing column: `generated_websites`.`seo_description` (from 2026_08_07_000001_upgrade_website_builder_pro.sql)
SET @tourfecto_add_column_sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='generated_websites' AND COLUMN_NAME='seo_description') = 0,
  'ALTER TABLE `generated_websites` ADD COLUMN `seo_description` VARCHAR(320) DEFAULT NULL AFTER `seo_title`',
  'SELECT 1'
);
PREPARE tourfecto_add_column_stmt FROM @tourfecto_add_column_sql;
EXECUTE tourfecto_add_column_stmt;
DEALLOCATE PREPARE tourfecto_add_column_stmt;

-- Missing column: `crm_contacts`.`lifecycle_stage` (from 2026_08_08_000011_create_crm_lifecycle_stages_table.sql)
SET @tourfecto_add_column_sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='crm_contacts' AND COLUMN_NAME='lifecycle_stage') = 0,
  'ALTER TABLE `crm_contacts` ADD COLUMN `lifecycle_stage` VARCHAR(50) DEFAULT NULL COMMENT ''مرحلة دورة حياة العميل - المرحلة 13 (G6): lead/qualified/customer/inactive/churned أو مرحلة مخصصة'' AFTER `status`',
  'SELECT 1'
);
PREPARE tourfecto_add_column_stmt FROM @tourfecto_add_column_sql;
EXECUTE tourfecto_add_column_stmt;
DEALLOCATE PREPARE tourfecto_add_column_stmt;

-- Missing column: `websites`.`target_customers` (from 2026_08_08_000051_onboarding_wizard.sql)
SET @tourfecto_add_column_sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='websites' AND COLUMN_NAME='target_customers') = 0,
  'ALTER TABLE `websites` ADD COLUMN `target_customers` TEXT NULL DEFAULT NULL
        COMMENT ''وصف العملاء المستهدفين (من خطوة 5 في Onboarding Wizard)'' AFTER `target_country`',
  'SELECT 1'
);
PREPARE tourfecto_add_column_stmt FROM @tourfecto_add_column_sql;
EXECUTE tourfecto_add_column_stmt;
DEALLOCATE PREPARE tourfecto_add_column_stmt;

-- Missing column: `websites`.`main_services` (from 2026_08_08_000051_onboarding_wizard.sql)
SET @tourfecto_add_column_sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='websites' AND COLUMN_NAME='main_services') = 0,
  'ALTER TABLE `websites` ADD COLUMN `main_services` TEXT NULL DEFAULT NULL
        COMMENT ''الخدمات الأساسية اللي بيقدمها النشاط (من خطوة 6 في Onboarding Wizard) - نص حر أو JSON array مبسّط''
        AFTER `target_customers`',
  'SELECT 1'
);
PREPARE tourfecto_add_column_stmt FROM @tourfecto_add_column_sql;
EXECUTE tourfecto_add_column_stmt;
DEALLOCATE PREPARE tourfecto_add_column_stmt;

-- Missing column: `users`.`notification_preferences` (from 2026_08_09_000045_add_notification_preferences.sql)
SET @tourfecto_add_column_sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='notification_preferences') = 0,
  'ALTER TABLE `users` ADD COLUMN `notification_preferences` TEXT NULL DEFAULT NULL COMMENT ''JSON: تفضيل تشغيل/إيقاف لكل فئة إشعار (reviews, content_publishing, leads, system)'' AFTER `notify_reviews`',
  'SELECT 1'
);
PREPARE tourfecto_add_column_stmt FROM @tourfecto_add_column_sql;
EXECUTE tourfecto_add_column_stmt;
DEALLOCATE PREPARE tourfecto_add_column_stmt;

-- Missing column: `review_requests`.`matched_review_id` (from 2026_08_10_000046_extend_review_requests_channel_attribution_optout.sql)
SET @tourfecto_add_column_sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='review_requests' AND COLUMN_NAME='matched_review_id') = 0,
  'ALTER TABLE `review_requests` ADD COLUMN `matched_review_id` BIGINT(20) UNSIGNED DEFAULT NULL
        COMMENT ''يشير لـ reviews.id الفعلي اللي طابق هذا الطلب (Attribution حقيقي)'' AFTER `crm_deal_id`',
  'SELECT 1'
);
PREPARE tourfecto_add_column_stmt FROM @tourfecto_add_column_sql;
EXECUTE tourfecto_add_column_stmt;
DEALLOCATE PREPARE tourfecto_add_column_stmt;

-- Missing column: `review_requests`.`reviewed_at` (from 2026_08_10_000046_extend_review_requests_channel_attribution_optout.sql)
SET @tourfecto_add_column_sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='review_requests' AND COLUMN_NAME='reviewed_at') = 0,
  'ALTER TABLE `review_requests` ADD COLUMN `reviewed_at` DATETIME DEFAULT NULL
        COMMENT ''وقت اكتشاف تطابق المراجعة فعليًا (لحساب Time-to-Review)'' AFTER `matched_review_id`',
  'SELECT 1'
);
PREPARE tourfecto_add_column_stmt FROM @tourfecto_add_column_sql;
EXECUTE tourfecto_add_column_stmt;
DEALLOCATE PREPARE tourfecto_add_column_stmt;

-- Missing column: `review_request_settings`.`email_subject` (from 2026_08_10_000046_extend_review_requests_channel_attribution_optout.sql)
SET @tourfecto_add_column_sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='review_request_settings' AND COLUMN_NAME='email_subject') = 0,
  'ALTER TABLE `review_request_settings` ADD COLUMN `email_subject` VARCHAR(190) DEFAULT NULL
        COMMENT ''عنوان رسالة الإيميل لو channel = email - يدعم {name}'' AFTER `message_template`',
  'SELECT 1'
);
PREPARE tourfecto_add_column_stmt FROM @tourfecto_add_column_sql;
EXECUTE tourfecto_add_column_stmt;
DEALLOCATE PREPARE tourfecto_add_column_stmt;

-- Missing column: `review_requests`.`attempts` (from 2026_08_10_000047_add_attempts_to_review_requests.sql)
SET @tourfecto_add_column_sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='review_requests' AND COLUMN_NAME='attempts') = 0,
  'ALTER TABLE `review_requests` ADD COLUMN `attempts` INT(11) NOT NULL DEFAULT 0
        COMMENT ''عدد محاولات الإرسال الفعلية - يمنع Infinite Retry (الحد الأقصى مُطبَّق في كود الخدمة)''
        AFTER `error_message`',
  'SELECT 1'
);
PREPARE tourfecto_add_column_stmt FROM @tourfecto_add_column_sql;
EXECUTE tourfecto_add_column_stmt;
DEALLOCATE PREPARE tourfecto_add_column_stmt;

-- Missing column: `gbp_scheduled_posts`.`queue_job_id` (from 2026_08_11_000043_add_queue_job_id_to_gbp_scheduled_posts.sql)
SET @tourfecto_add_column_sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='gbp_scheduled_posts' AND COLUMN_NAME='queue_job_id') = 0,
  'ALTER TABLE `gbp_scheduled_posts` ADD COLUMN `queue_job_id` INT(11) NULL DEFAULT NULL COMMENT ''يشير لـ jobs.id - لإلغاء المهمة المجدولة قبل تنفيذها'' AFTER `platform_connection_id`',
  'SELECT 1'
);
PREPARE tourfecto_add_column_stmt FROM @tourfecto_add_column_sql;
EXECUTE tourfecto_add_column_stmt;
DEALLOCATE PREPARE tourfecto_add_column_stmt;

-- Missing column: `ad_copies`.`ad_group_id` (from 2026_08_11_000044_add_ad_groups.sql)
SET @tourfecto_add_column_sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ad_copies' AND COLUMN_NAME='ad_group_id') = 0,
  'ALTER TABLE `ad_copies` ADD COLUMN `ad_group_id` INT(11) DEFAULT NULL AFTER `campaign_id`',
  'SELECT 1'
);
PREPARE tourfecto_add_column_stmt FROM @tourfecto_add_column_sql;
EXECUTE tourfecto_add_column_stmt;
DEALLOCATE PREPARE tourfecto_add_column_stmt;

-- Missing column: `ad_keywords`.`ad_group_id` (from 2026_08_11_000044_add_ad_groups.sql)
SET @tourfecto_add_column_sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ad_keywords' AND COLUMN_NAME='ad_group_id') = 0,
  'ALTER TABLE `ad_keywords` ADD COLUMN `ad_group_id` INT(11) DEFAULT NULL AFTER `campaign_id`',
  'SELECT 1'
);
PREPARE tourfecto_add_column_stmt FROM @tourfecto_add_column_sql;
EXECUTE tourfecto_add_column_stmt;
DEALLOCATE PREPARE tourfecto_add_column_stmt;

-- Missing column: `ad_campaigns`.`deleted_at` (from 2026_08_11_000044_add_ad_groups.sql)
SET @tourfecto_add_column_sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ad_campaigns' AND COLUMN_NAME='deleted_at') = 0,
  'ALTER TABLE `ad_campaigns` ADD COLUMN `deleted_at` TIMESTAMP NULL DEFAULT NULL COMMENT ''Soft delete - الحملة مخفية من القوائم لكن بياناتها التاريخية محفوظة بالكامل'' AFTER `status`',
  'SELECT 1'
);
PREPARE tourfecto_add_column_stmt FROM @tourfecto_add_column_sql;
EXECUTE tourfecto_add_column_stmt;
DEALLOCATE PREPARE tourfecto_add_column_stmt;

-- Missing column: `gbp_photos`.`error_message` (from 2026_08_11_000044_add_async_upload_status_to_gbp_photos.sql)
SET @tourfecto_add_column_sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='gbp_photos' AND COLUMN_NAME='error_message') = 0,
  'ALTER TABLE `gbp_photos` ADD COLUMN `error_message` TEXT NULL DEFAULT NULL COMMENT ''رسالة الخطأ لو فشل الرفع على Google'' AFTER `status`',
  'SELECT 1'
);
PREPARE tourfecto_add_column_stmt FROM @tourfecto_add_column_sql;
EXECUTE tourfecto_add_column_stmt;
DEALLOCATE PREPARE tourfecto_add_column_stmt;

-- Missing column: `invoices`.`subtotal` (from 2026_08_12_000050_add_tax_columns_to_invoices.sql)
SET @tourfecto_add_column_sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='invoices' AND COLUMN_NAME='subtotal') = 0,
  'ALTER TABLE `invoices` ADD COLUMN `subtotal` DECIMAL(10,2) NULL DEFAULT NULL COMMENT ''المبلغ قبل الضريبة'' AFTER `amount`',
  'SELECT 1'
);
PREPARE tourfecto_add_column_stmt FROM @tourfecto_add_column_sql;
EXECUTE tourfecto_add_column_stmt;
DEALLOCATE PREPARE tourfecto_add_column_stmt;

-- Missing column: `invoices`.`tax_country` (from 2026_08_12_000050_add_tax_columns_to_invoices.sql)
SET @tourfecto_add_column_sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='invoices' AND COLUMN_NAME='tax_country') = 0,
  'ALTER TABLE `invoices` ADD COLUMN `tax_country` CHAR(2) NULL DEFAULT NULL AFTER `subtotal`',
  'SELECT 1'
);
PREPARE tourfecto_add_column_stmt FROM @tourfecto_add_column_sql;
EXECUTE tourfecto_add_column_stmt;
DEALLOCATE PREPARE tourfecto_add_column_stmt;

-- Missing column: `invoices`.`tax_type` (from 2026_08_12_000050_add_tax_columns_to_invoices.sql)
SET @tourfecto_add_column_sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='invoices' AND COLUMN_NAME='tax_type') = 0,
  'ALTER TABLE `invoices` ADD COLUMN `tax_type` VARCHAR(30) NULL DEFAULT NULL COMMENT ''VAT / GST / ... - NULL يعني Not Configured'' AFTER `tax_country`',
  'SELECT 1'
);
PREPARE tourfecto_add_column_stmt FROM @tourfecto_add_column_sql;
EXECUTE tourfecto_add_column_stmt;
DEALLOCATE PREPARE tourfecto_add_column_stmt;

-- Missing column: `invoices`.`tax_amount` (from 2026_08_12_000050_add_tax_columns_to_invoices.sql)
SET @tourfecto_add_column_sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='invoices' AND COLUMN_NAME='tax_amount') = 0,
  'ALTER TABLE `invoices` ADD COLUMN `tax_amount` DECIMAL(10,2) NULL DEFAULT NULL COMMENT ''معلوماتي حاليًا - غير مضاف لمبلغ amount المخصوم فعليًا'' AFTER `tax_type`',
  'SELECT 1'
);
PREPARE tourfecto_add_column_stmt FROM @tourfecto_add_column_sql;
EXECUTE tourfecto_add_column_stmt;
DEALLOCATE PREPARE tourfecto_add_column_stmt;

-- Missing column: `websites`.`business_id` (from 2026_08_14_000049_create_businesses_table.sql)
SET @tourfecto_add_column_sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='websites' AND COLUMN_NAME='business_id') = 0,
  'ALTER TABLE `websites` ADD COLUMN `business_id` INT(11) DEFAULT NULL AFTER `user_id`',
  'SELECT 1'
);
PREPARE tourfecto_add_column_stmt FROM @tourfecto_add_column_sql;
EXECUTE tourfecto_add_column_stmt;
DEALLOCATE PREPARE tourfecto_add_column_stmt;

-- Missing column: `wallet_transactions`.`feature_key` (from 2026_08_15_000053_add_feature_key_to_wallet_transactions.sql)
SET @tourfecto_add_column_sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='wallet_transactions' AND COLUMN_NAME='feature_key') = 0,
  'ALTER TABLE `wallet_transactions` ADD COLUMN `feature_key` VARCHAR(50) NULL DEFAULT NULL
        COMMENT ''feature_key من pay_per_use_pricing (ai_analysis, chat_message...) - بيخصم عليه خصم الاستخدام الفردي''
        AFTER `related_subscription_plan`',
  'SELECT 1'
);
PREPARE tourfecto_add_column_stmt FROM @tourfecto_add_column_sql;
EXECUTE tourfecto_add_column_stmt;
DEALLOCATE PREPARE tourfecto_add_column_stmt;

-- Missing column: `user_api_keys`.`expires_at` (from 2026_08_15_000055_add_expires_at_to_user_api_keys.sql)
SET @tourfecto_add_column_sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='user_api_keys' AND COLUMN_NAME='expires_at') = 0,
  'ALTER TABLE `user_api_keys` ADD COLUMN `expires_at` TIMESTAMP NULL DEFAULT NULL COMMENT ''انتهاء صلاحية اختياري - NULL = لا ينتهي أبدًا'' AFTER `last_used_at`',
  'SELECT 1'
);
PREPARE tourfecto_add_column_stmt FROM @tourfecto_add_column_sql;
EXECUTE tourfecto_add_column_stmt;
DEALLOCATE PREPARE tourfecto_add_column_stmt;

-- Missing column: `user_api_keys`.`scopes` (from 2026_08_16_000002_add_scopes_to_user_api_keys.sql)
SET @tourfecto_add_column_sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='user_api_keys' AND COLUMN_NAME='scopes') = 0,
  'ALTER TABLE `user_api_keys` ADD COLUMN `scopes` TEXT NULL DEFAULT NULL COMMENT ''JSON: قائمة الصلاحيات (NULL = كل الصلاحيات)'' AFTER `expires_at`',
  'SELECT 1'
);
PREPARE tourfecto_add_column_stmt FROM @tourfecto_add_column_sql;
EXECUTE tourfecto_add_column_stmt;
DEALLOCATE PREPARE tourfecto_add_column_stmt;

-- Missing column: `crm_deals`.`assigned_rep_id` (from 2026_08_16_000010_create_revai_subscriptions_teams_benchmarks.sql)
SET @tourfecto_add_column_sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='crm_deals' AND COLUMN_NAME='assigned_rep_id') = 0,
  'ALTER TABLE `crm_deals` ADD COLUMN `assigned_rep_id` INT(11) DEFAULT NULL COMMENT ''مندوب البيع المسؤول عن الصفقة (Sales Attribution)'' AFTER `owner_user_id`',
  'SELECT 1'
);
PREPARE tourfecto_add_column_stmt FROM @tourfecto_add_column_sql;
EXECUTE tourfecto_add_column_stmt;
DEALLOCATE PREPARE tourfecto_add_column_stmt;

-- Missing column: `websites`.`connection_method` (from 2026_08_20_000001_auto_seo_embed.sql)
SET @tourfecto_add_column_sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='websites' AND COLUMN_NAME='connection_method') = 0,
  'ALTER TABLE `websites` ADD COLUMN `connection_method` ENUM(''script'',''api'',''wordpress'',''shopify'') NOT NULL DEFAULT ''script''
        COMMENT ''طريقة الربط'' AFTER `is_connected`',
  'SELECT 1'
);
PREPARE tourfecto_add_column_stmt FROM @tourfecto_add_column_sql;
EXECUTE tourfecto_add_column_stmt;
DEALLOCATE PREPARE tourfecto_add_column_stmt;

-- Missing column: `websites`.`embed_token` (from 2026_08_20_000001_auto_seo_embed.sql)
SET @tourfecto_add_column_sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='websites' AND COLUMN_NAME='embed_token') = 0,
  'ALTER TABLE `websites` ADD COLUMN `embed_token` VARCHAR(100) DEFAULT NULL
        COMMENT ''توكن السكربت العام - بيتحط في src بتاع embed.js'' AFTER `connection_method`',
  'SELECT 1'
);
PREPARE tourfecto_add_column_stmt FROM @tourfecto_add_column_sql;
EXECUTE tourfecto_add_column_stmt;
DEALLOCATE PREPARE tourfecto_add_column_stmt;

-- Missing column: `websites`.`embed_api_key` (from 2026_08_20_000001_auto_seo_embed.sql)
SET @tourfecto_add_column_sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='websites' AND COLUMN_NAME='embed_api_key') = 0,
  'ALTER TABLE `websites` ADD COLUMN `embed_api_key` VARCHAR(100) DEFAULT NULL
        COMMENT ''مفتاح API سرّي للتحكم البرمجي'' AFTER `embed_token`',
  'SELECT 1'
);
PREPARE tourfecto_add_column_stmt FROM @tourfecto_add_column_sql;
EXECUTE tourfecto_add_column_stmt;
DEALLOCATE PREPARE tourfecto_add_column_stmt;

-- Missing column: `websites`.`auto_fix_enabled` (from 2026_08_20_000001_auto_seo_embed.sql)
SET @tourfecto_add_column_sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='websites' AND COLUMN_NAME='auto_fix_enabled') = 0,
  'ALTER TABLE `websites` ADD COLUMN `auto_fix_enabled` TINYINT(1) NOT NULL DEFAULT 0
        COMMENT ''تفعيل التنفيذ التلقائي'' AFTER `auto_pilot_mode`',
  'SELECT 1'
);
PREPARE tourfecto_add_column_stmt FROM @tourfecto_add_column_sql;
EXECUTE tourfecto_add_column_stmt;
DEALLOCATE PREPARE tourfecto_add_column_stmt;

-- Missing column: `websites`.`connected_at` (from 2026_08_20_000001_auto_seo_embed.sql)
SET @tourfecto_add_column_sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='websites' AND COLUMN_NAME='connected_at') = 0,
  'ALTER TABLE `websites` ADD COLUMN `connected_at` TIMESTAMP NULL DEFAULT NULL AFTER `auto_fix_enabled`',
  'SELECT 1'
);
PREPARE tourfecto_add_column_stmt FROM @tourfecto_add_column_sql;
EXECUTE tourfecto_add_column_stmt;
DEALLOCATE PREPARE tourfecto_add_column_stmt;

-- Missing column: `websites`.`last_sync_at` (from 2026_08_20_000001_auto_seo_embed.sql)
SET @tourfecto_add_column_sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='websites' AND COLUMN_NAME='last_sync_at') = 0,
  'ALTER TABLE `websites` ADD COLUMN `last_sync_at` TIMESTAMP NULL DEFAULT NULL AFTER `connected_at`',
  'SELECT 1'
);
PREPARE tourfecto_add_column_stmt FROM @tourfecto_add_column_sql;
EXECUTE tourfecto_add_column_stmt;
DEALLOCATE PREPARE tourfecto_add_column_stmt;

-- Missing column: `websites`.`total_fixes_applied` (from 2026_08_20_000001_auto_seo_embed.sql)
SET @tourfecto_add_column_sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='websites' AND COLUMN_NAME='total_fixes_applied') = 0,
  'ALTER TABLE `websites` ADD COLUMN `total_fixes_applied` INT(11) NOT NULL DEFAULT 0 AFTER `last_sync_at`',
  'SELECT 1'
);
PREPARE tourfecto_add_column_stmt FROM @tourfecto_add_column_sql;
EXECUTE tourfecto_add_column_stmt;
DEALLOCATE PREPARE tourfecto_add_column_stmt;

-- Missing column: `websites`.`total_rollbacks` (from 2026_08_20_000001_auto_seo_embed.sql)
SET @tourfecto_add_column_sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='websites' AND COLUMN_NAME='total_rollbacks') = 0,
  'ALTER TABLE `websites` ADD COLUMN `total_rollbacks` INT(11) NOT NULL DEFAULT 0 AFTER `total_fixes_applied`',
  'SELECT 1'
);
PREPARE tourfecto_add_column_stmt FROM @tourfecto_add_column_sql;
EXECUTE tourfecto_add_column_stmt;
DEALLOCATE PREPARE tourfecto_add_column_stmt;

-- Missing column: `wo_fixes`.`can_auto_apply_external` (from 2026_08_20_000001_auto_seo_embed.sql)
SET @tourfecto_add_column_sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='wo_fixes' AND COLUMN_NAME='can_auto_apply_external') = 0,
  'ALTER TABLE `wo_fixes` ADD COLUMN `can_auto_apply_external` TINYINT(1) NOT NULL DEFAULT 0
        COMMENT ''الإصلاح ده ينفع يتحقن على موقع خارجي عبر embed.js''',
  'SELECT 1'
);
PREPARE tourfecto_add_column_stmt FROM @tourfecto_add_column_sql;
EXECUTE tourfecto_add_column_stmt;
DEALLOCATE PREPARE tourfecto_add_column_stmt;

-- Missing column: `wo_fixes`.`field_name` (from 2026_08_20_000001_auto_seo_embed.sql)
SET @tourfecto_add_column_sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='wo_fixes' AND COLUMN_NAME='field_name') = 0,
  'ALTER TABLE `wo_fixes` ADD COLUMN `field_name` VARCHAR(100) DEFAULT NULL
        COMMENT ''الحقل المستهدف - seo_title/seo_description/canonical_url/json_ld/llms_txt''',
  'SELECT 1'
);
PREPARE tourfecto_add_column_stmt FROM @tourfecto_add_column_sql;
EXECUTE tourfecto_add_column_stmt;
DEALLOCATE PREPARE tourfecto_add_column_stmt;

-- Missing column: `websites`.`indexnow_enabled` (from 2026_08_20_000002_phase2_indexing_ab.sql)
SET @tourfecto_add_column_sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='websites' AND COLUMN_NAME='indexnow_enabled') = 0,
  'ALTER TABLE `websites` ADD COLUMN `indexnow_enabled` TINYINT(1) NOT NULL DEFAULT 0
        COMMENT ''تفعيل الفهرسة الفورية التلقائية بعد كل إصلاح''',
  'SELECT 1'
);
PREPARE tourfecto_add_column_stmt FROM @tourfecto_add_column_sql;
EXECUTE tourfecto_add_column_stmt;
DEALLOCATE PREPARE tourfecto_add_column_stmt;

-- Missing column: `websites`.`seo_audit_frequency` (from 2026_08_20_000003_seo_phase23.sql)
SET @tourfecto_add_column_sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='websites' AND COLUMN_NAME='seo_audit_frequency') = 0,
  'ALTER TABLE `websites` ADD COLUMN `seo_audit_frequency` ENUM(''daily'',''weekly'',''monthly'') NOT NULL DEFAULT ''weekly'' AFTER `last_sync_at`',
  'SELECT 1'
);
PREPARE tourfecto_add_column_stmt FROM @tourfecto_add_column_sql;
EXECUTE tourfecto_add_column_stmt;
DEALLOCATE PREPARE tourfecto_add_column_stmt;

-- Missing column: `websites`.`last_seo_audit_at` (from 2026_08_20_000003_seo_phase23.sql)
SET @tourfecto_add_column_sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='websites' AND COLUMN_NAME='last_seo_audit_at') = 0,
  'ALTER TABLE `websites` ADD COLUMN `last_seo_audit_at` DATETIME NULL AFTER `seo_audit_frequency`',
  'SELECT 1'
);
PREPARE tourfecto_add_column_stmt FROM @tourfecto_add_column_sql;
EXECUTE tourfecto_add_column_stmt;
DEALLOCATE PREPARE tourfecto_add_column_stmt;

-- Missing column: `websites`.`last_indexnow_at` (from 2026_08_20_000003_seo_phase23.sql)
SET @tourfecto_add_column_sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='websites' AND COLUMN_NAME='last_indexnow_at') = 0,
  'ALTER TABLE `websites` ADD COLUMN `last_indexnow_at` DATETIME NULL AFTER `last_seo_audit_at`',
  'SELECT 1'
);
PREPARE tourfecto_add_column_stmt FROM @tourfecto_add_column_sql;
EXECUTE tourfecto_add_column_stmt;
DEALLOCATE PREPARE tourfecto_add_column_stmt;

-- Missing column: `websites`.`target_languages` (from 2026_08_21_000001_add_target_languages_to_websites.sql)
SET @tourfecto_add_column_sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='websites' AND COLUMN_NAME='target_languages') = 0,
  'ALTER TABLE `websites` ADD COLUMN `target_languages` JSON NULL AFTER auto_pilot_mode',
  'SELECT 1'
);
PREPARE tourfecto_add_column_stmt FROM @tourfecto_add_column_sql;
EXECUTE tourfecto_add_column_stmt;
DEALLOCATE PREPARE tourfecto_add_column_stmt;

-- Missing column: `websites`.`baidu_token` (from 2026_08_21_000003_add_baidu_token_to_websites.sql)
SET @tourfecto_add_column_sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='websites' AND COLUMN_NAME='baidu_token') = 0,
  'ALTER TABLE `websites` ADD COLUMN `baidu_token` VARCHAR(255) NULL AFTER indexnow_enabled',
  'SELECT 1'
);
PREPARE tourfecto_add_column_stmt FROM @tourfecto_add_column_sql;
EXECUTE tourfecto_add_column_stmt;
DEALLOCATE PREPARE tourfecto_add_column_stmt;

-- Missing column: `crm_deals`.`booking_id` (from 2026_08_26_000001_add_booking_id_to_crm_deals.sql)
SET @tourfecto_add_column_sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='crm_deals' AND COLUMN_NAME='booking_id') = 0,
  'ALTER TABLE `crm_deals` ADD COLUMN `booking_id` INT(11) NULL DEFAULT NULL COMMENT ''يشير إلى bookings.id - ربط الصفقة بالحجز الفعلي'' AFTER `contact_id`',
  'SELECT 1'
);
PREPARE tourfecto_add_column_stmt FROM @tourfecto_add_column_sql;
EXECUTE tourfecto_add_column_stmt;
DEALLOCATE PREPARE tourfecto_add_column_stmt;

-- Missing column: `crm_leads`.`conv_probability` (from 2026_08_28_000008_add_stat_lead_scoring.sql)
SET @tourfecto_add_column_sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='crm_leads' AND COLUMN_NAME='conv_probability') = 0,
  'ALTER TABLE `crm_leads` ADD COLUMN `conv_probability` DECIMAL(5,4) NULL DEFAULT NULL
        COMMENT ''احتمال التحويل الإحصائي (0-1) من معدل تحويل مصدر الـLead في بيانات الحساب (بند 6)''
        AFTER `score_reason`',
  'SELECT 1'
);
PREPARE tourfecto_add_column_stmt FROM @tourfecto_add_column_sql;
EXECUTE tourfecto_add_column_stmt;
DEALLOCATE PREPARE tourfecto_add_column_stmt;

-- Missing column: `crm_leads`.`score_confidence` (from 2026_08_28_000008_add_stat_lead_scoring.sql)
SET @tourfecto_add_column_sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='crm_leads' AND COLUMN_NAME='score_confidence') = 0,
  'ALTER TABLE `crm_leads` ADD COLUMN `score_confidence` ENUM(''low'',''moderate'',''high'') NULL DEFAULT NULL
        COMMENT ''ثقة النتيجة الإحصائية من حجم العينة (بند 6)''
        AFTER `conv_probability`',
  'SELECT 1'
);
PREPARE tourfecto_add_column_stmt FROM @tourfecto_add_column_sql;
EXECUTE tourfecto_add_column_stmt;
DEALLOCATE PREPARE tourfecto_add_column_stmt;

-- Missing column: `crm_leads`.`score_signals_json` (from 2026_08_28_000008_add_stat_lead_scoring.sql)
SET @tourfecto_add_column_sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='crm_leads' AND COLUMN_NAME='score_signals_json') = 0,
  'ALTER TABLE `crm_leads` ADD COLUMN `score_signals_json` JSON NULL DEFAULT NULL
        COMMENT ''إشارات إحصائية مخزنة فقط (عينة/تحويلات/فاصل Wilson) - ليست توصيات كحقيقة (بند 6)''
        AFTER `score_confidence`',
  'SELECT 1'
);
PREPARE tourfecto_add_column_stmt FROM @tourfecto_add_column_sql;
EXECUTE tourfecto_add_column_stmt;
DEALLOCATE PREPARE tourfecto_add_column_stmt;

-- Missing column: `rev_revenue_records`.`product_name` (from 2026_08_29_000001_add_product_dimension_to_rev_revenue_records.sql)
SET @tourfecto_add_column_sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='rev_revenue_records' AND COLUMN_NAME='product_name') = 0,
  'ALTER TABLE `rev_revenue_records` ADD COLUMN `product_name` VARCHAR(255) NULL COMMENT ''اسم المنتج/الخدمة (اختياري - G2)'' AFTER `source`',
  'SELECT 1'
);
PREPARE tourfecto_add_column_stmt FROM @tourfecto_add_column_sql;
EXECUTE tourfecto_add_column_stmt;
DEALLOCATE PREPARE tourfecto_add_column_stmt;

-- Missing column: `rev_revenue_records`.`category` (from 2026_08_29_000001_add_product_dimension_to_rev_revenue_records.sql)
SET @tourfecto_add_column_sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='rev_revenue_records' AND COLUMN_NAME='category') = 0,
  'ALTER TABLE `rev_revenue_records` ADD COLUMN `category` VARCHAR(100) NULL COMMENT ''تصنيف المنتج: rooms/tours/transfers/packages/other (اختياري - G2)'' AFTER `product_name`',
  'SELECT 1'
);
PREPARE tourfecto_add_column_stmt FROM @tourfecto_add_column_sql;
EXECUTE tourfecto_add_column_stmt;
DEALLOCATE PREPARE tourfecto_add_column_stmt;

-- Missing column: `websites`.`google_indexing_enabled` (from 2026_08_29_000004_seo_multi_crawl_rank_tracking_reports.sql)
SET @tourfecto_add_column_sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='websites' AND COLUMN_NAME='google_indexing_enabled') = 0,
  'ALTER TABLE `websites` ADD COLUMN `google_indexing_enabled` TINYINT(1) NOT NULL DEFAULT 0
    COMMENT ''تفعيل الإبلاغ لـ Google Indexing API'' AFTER `indexnow_enabled`',
  'SELECT 1'
);
PREPARE tourfecto_add_column_stmt FROM @tourfecto_add_column_sql;
EXECUTE tourfecto_add_column_stmt;
DEALLOCATE PREPARE tourfecto_add_column_stmt;

-- Missing column: `websites`.`last_google_indexed_at` (from 2026_08_29_000004_seo_multi_crawl_rank_tracking_reports.sql)
SET @tourfecto_add_column_sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='websites' AND COLUMN_NAME='last_google_indexed_at') = 0,
  'ALTER TABLE `websites` ADD COLUMN `last_google_indexed_at` DATETIME DEFAULT NULL
    COMMENT ''آخر وقت إبلاغ لـ Google Indexing API'' AFTER `google_indexing_enabled`',
  'SELECT 1'
);
PREPARE tourfecto_add_column_stmt FROM @tourfecto_add_column_sql;
EXECUTE tourfecto_add_column_stmt;
DEALLOCATE PREPARE tourfecto_add_column_stmt;

-- Missing column: `websites`.`last_rank_tracked_at` (from 2026_08_29_000004_seo_multi_crawl_rank_tracking_reports.sql)
SET @tourfecto_add_column_sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='websites' AND COLUMN_NAME='last_rank_tracked_at') = 0,
  'ALTER TABLE `websites` ADD COLUMN `last_rank_tracked_at` DATETIME DEFAULT NULL
    COMMENT ''آخر وقت فحص ترتيب الكلمات المفتاحية (Rank Tracking)'' AFTER `last_google_indexed_at`',
  'SELECT 1'
);
PREPARE tourfecto_add_column_stmt FROM @tourfecto_add_column_sql;
EXECUTE tourfecto_add_column_stmt;
DEALLOCATE PREPARE tourfecto_add_column_stmt;

SET FOREIGN_KEY_CHECKS=1;
SET @TOURFECTO_SCHEMA_PATCH_FINISHED = UTC_TIMESTAMP();
SELECT CONCAT('Tourfecto schema patch finished at ' , UTC_TIMESTAMP()) AS tourfecto_schema_patch_status;