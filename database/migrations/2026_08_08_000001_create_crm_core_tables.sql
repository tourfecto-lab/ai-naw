-- ============================================================
-- Tourfecto - Migration: CRM Core (Module: AI CRM)
-- @version 1.0.0  @date 2026-08-08
--
-- ملاحظة حرجة: كان الكود الحالي (CrmController + Models + CrmLeadService)
-- يفترض وجود الجداول crm_leads / crm_contacts / crm_deals / crm_pipeline_stages
-- ويُنفّذ عليها SELECT/JOIN مباشرة (مثال: "FROM crm_leads l JOIN crm_contacts c")
-- لكن لا توجد ولا Migration واحدة تنشئ هذه الجداول فعليًا في أي مكان بالمشروع
-- المرفوع - يعني كل صفحات CRM الحالية كانت ستفشل بخطأ "Table doesn't exist"
-- فور تشغيلها. هذا الملف ينشئ البنية الكاملة الناقصة، بنفس أسماء الأعمدة
-- التي يفترضها الكود الحالي بالفعل (fillable في كل Model) حتى لا نكسر أي
-- منطق موجود، ثم يضيف الجداول/الأعمدة الجديدة المطلوبة لبقية الـCRM
-- (Companies, Pipelines متعددة, Appointments تفاصيل, Lead Sources قابلة للتخصيص).
--
-- التوافق مع النظام الحالي: لا يوجد "tenant_id" منفصل في هذا المشروع -
-- كل حساب SaaS (عميل Tourfecto) هو نفسه صف في users، وعزل البيانات في كل
-- موديول موجود (websites, ai_reports...) يتم عبر عمود user_id = users.id.
-- نفس النمط بالظبط مُتّبع هنا لعزل بيانات كل عميل CRM عن الآخر (بند 31).
-- ============================================================

-- ------------------------------------------------------------
-- 1) الشركات (Companies) - كيان جديد لم يكن موجودًا إطلاقًا
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `crm_companies` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `user_id` INT(11) NOT NULL COMMENT 'صاحب الحساب (Tenant) - عزل كامل للبيانات',
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

-- ------------------------------------------------------------
-- 2) جهات الاتصال (Contacts) - كان مُفترضًا في الكود بدون جدول فعلي
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `crm_contacts` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `user_id` INT(11) NOT NULL COMMENT 'صاحب الحساب (Tenant)',
    `agency_id` INT(11) DEFAULT NULL COMMENT 'موجود مسبقًا في fillable الأصلي - وكالة White-Label المالكة لو موجودة',
    `company_id` INT(11) DEFAULT NULL,
    `name` VARCHAR(255) NOT NULL,
    `email` VARCHAR(255) DEFAULT NULL,
    `phone` VARCHAR(50) DEFAULT NULL,
    `country` VARCHAR(100) DEFAULT NULL,
    `language` VARCHAR(10) DEFAULT NULL,
    `source` VARCHAR(50) NOT NULL DEFAULT 'manual' COMMENT 'website, social_media, advertising, whatsapp, referral, manual, import, other',
    `status` ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    `tags` TEXT DEFAULT NULL COMMENT 'JSON array من الوسوم (VIP, Hot Lead...)',
    `notes` TEXT DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_crm_contacts_user` (`user_id`),
    INDEX `idx_crm_contacts_company` (`company_id`),
    INDEX `idx_crm_contacts_email` (`user_id`, `email`) COMMENT 'يخدم اكتشاف التكرار (بند 21)',
    INDEX `idx_crm_contacts_phone` (`user_id`, `phone`),
    CONSTRAINT `fk_crm_contacts_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_crm_contacts_company` FOREIGN KEY (`company_id`) REFERENCES `crm_companies` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جهات اتصال CRM';

-- ------------------------------------------------------------
-- 3) قوالب مسارات البيع (Pipelines) - لدعم أكثر من Pipeline (بند 6)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `crm_pipelines` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `user_id` INT(11) DEFAULT NULL COMMENT 'NULL = Pipeline افتراضي عام لكل الحسابات (متوافق مع السلوك الحالي)',
    `name` VARCHAR(150) NOT NULL,
    `pipeline_key` VARCHAR(100) NOT NULL,
    `is_default` TINYINT(1) NOT NULL DEFAULT 0,
    `sort_order` INT(11) NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_crm_pipelines_user` (`user_id`),
    CONSTRAINT `fk_crm_pipelines_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='قوالب مسارات البيع (Sales / Travel Bookings / Real Estate...)';

-- ------------------------------------------------------------
-- 4) مراحل المسار (Pipeline Stages) - كان الجدول مفترضًا بدون إنشاء فعلي
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `crm_pipeline_stages` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `agency_id` INT(11) DEFAULT NULL COMMENT 'موجود مسبقًا في fillable/كود Controller الحالي - NULL = مرحلة عامة افتراضية',
    `pipeline_id` INT(11) DEFAULT NULL COMMENT 'الـPipeline المالك؛ NULL = يتبع الـPipeline الافتراضي (توافق خلفي)',
    `name` VARCHAR(150) NOT NULL,
    `slug` VARCHAR(150) NOT NULL,
    `description` VARCHAR(500) DEFAULT NULL,
    `sort_order` INT(11) NOT NULL DEFAULT 0,
    `win_probability` TINYINT(3) UNSIGNED NOT NULL DEFAULT 0,
    `is_won` TINYINT(1) NOT NULL DEFAULT 0,
    `is_lost` TINYINT(1) NOT NULL DEFAULT 0,
    `color` VARCHAR(20) DEFAULT '#6366f1',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_crm_stages_pipeline` (`pipeline_id`),
    CONSTRAINT `fk_crm_stages_pipeline` FOREIGN KEY (`pipeline_id`) REFERENCES `crm_pipelines` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='مراحل مسار البيع';

-- ------------------------------------------------------------
-- 5) العملاء المحتملون (Leads) - نفس أعمدة fillable الحالية + إضافات AI-ready
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `crm_leads` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `contact_id` INT(11) NOT NULL,
    `owner_user_id` INT(11) DEFAULT NULL COMMENT 'Sales Rep المسؤول',
    `source` VARCHAR(50) DEFAULT NULL,
    `interest` VARCHAR(255) DEFAULT NULL,
    `value` DECIMAL(14,2) DEFAULT NULL COMMENT 'القيمة التقديرية للفرصة',
    `currency` VARCHAR(10) DEFAULT 'USD',
    `status` ENUM('new', 'nurturing', 'qualified', 'disqualified', 'converted') NOT NULL DEFAULT 'new',
    `priority` ENUM('low', 'medium', 'high') DEFAULT NULL COMMENT 'يُملأ لاحقًا بواسطة AI Lead Scoring (المرحلة القادمة) - NULL حاليًا = لا يوجد تقييم بعد',
    `score` TINYINT(3) UNSIGNED NOT NULL DEFAULT 0,
    `score_reason` VARCHAR(500) DEFAULT NULL COMMENT 'سبب الـScore - يُملأ من AI Lead Scoring مستقبلًا',
    `next_follow_up_at` DATETIME DEFAULT NULL,
    `last_engagement_at` DATETIME DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_crm_leads_contact` (`contact_id`),
    INDEX `idx_crm_leads_owner` (`owner_user_id`),
    INDEX `idx_crm_leads_status` (`status`),
    CONSTRAINT `fk_crm_leads_contact` FOREIGN KEY (`contact_id`) REFERENCES `crm_contacts` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_crm_leads_owner` FOREIGN KEY (`owner_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='العملاء المحتملون';

-- ------------------------------------------------------------
-- 6) الصفقات (Deals) - نفس أعمدة fillable الحالية + company_id/pipeline_id
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `crm_deals` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `owner_user_id` INT(11) NOT NULL COMMENT 'صاحب الحساب/المسؤول - يُستخدم أيضًا كعزل Tenant (نفس منطق Controller الحالي)',
    `lead_id` INT(11) DEFAULT NULL,
    `contact_id` INT(11) DEFAULT NULL,
    `company_id` INT(11) DEFAULT NULL,
    `pipeline_id` INT(11) DEFAULT NULL COMMENT 'NULL = المسار الافتراضي (توافق خلفي)',
    `stage_id` INT(11) NOT NULL,
    `title` VARCHAR(255) NOT NULL,
    `value` DECIMAL(14,2) NOT NULL DEFAULT 0,
    `currency` VARCHAR(10) NOT NULL DEFAULT 'USD',
    `probability` TINYINT(3) UNSIGNED DEFAULT NULL,
    `expected_close_date` DATE DEFAULT NULL,
    `closed_at` DATETIME DEFAULT NULL,
    `status` ENUM('open', 'won', 'lost') NOT NULL DEFAULT 'open',
    `lost_reason` VARCHAR(500) DEFAULT NULL,
    `notes` TEXT DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_crm_deals_owner` (`owner_user_id`),
    INDEX `idx_crm_deals_contact` (`contact_id`),
    INDEX `idx_crm_deals_company` (`company_id`),
    INDEX `idx_crm_deals_stage` (`stage_id`),
    INDEX `idx_crm_deals_status` (`status`),
    CONSTRAINT `fk_crm_deals_owner` FOREIGN KEY (`owner_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_crm_deals_lead` FOREIGN KEY (`lead_id`) REFERENCES `crm_leads` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_crm_deals_contact` FOREIGN KEY (`contact_id`) REFERENCES `crm_contacts` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_crm_deals_company` FOREIGN KEY (`company_id`) REFERENCES `crm_companies` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_crm_deals_stage` FOREIGN KEY (`stage_id`) REFERENCES `crm_pipeline_stages` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='الصفقات - Sales Pipeline';

-- ------------------------------------------------------------
-- 7) المهام (Tasks) - نفس أعمدة fillable الحالية
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `crm_tasks` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `user_id` INT(11) NOT NULL COMMENT 'صاحب الحساب (Tenant) - لعزل قوائم المهام',
    `created_by_user_id` INT(11) DEFAULT NULL,
    `assigned_to_user_id` INT(11) DEFAULT NULL,
    `related_type` VARCHAR(30) DEFAULT NULL COMMENT 'crm_leads | crm_contacts | crm_companies | crm_deals',
    `related_id` INT(11) DEFAULT NULL,
    `title` VARCHAR(255) NOT NULL,
    `description` TEXT DEFAULT NULL,
    `due_date` DATETIME DEFAULT NULL,
    `priority` ENUM('low', 'medium', 'high') NOT NULL DEFAULT 'medium',
    `status` ENUM('open', 'in_progress', 'done', 'cancelled') NOT NULL DEFAULT 'open',
    `completed_at` DATETIME DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_crm_tasks_user` (`user_id`),
    INDEX `idx_crm_tasks_related` (`related_type`, `related_id`),
    INDEX `idx_crm_tasks_due` (`due_date`),
    INDEX `idx_crm_tasks_status` (`status`),
    CONSTRAINT `fk_crm_tasks_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='مهام ومتابعات CRM';

-- ------------------------------------------------------------
-- 8) الملاحظات (Notes) - نفس أعمدة fillable الحالية
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `crm_notes` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `user_id` INT(11) NOT NULL COMMENT 'صاحب الحساب (Tenant)',
    `author_user_id` INT(11) DEFAULT NULL,
    `related_type` VARCHAR(30) DEFAULT NULL,
    `related_id` INT(11) DEFAULT NULL,
    `body` TEXT NOT NULL,
    `pinned` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_crm_notes_user` (`user_id`),
    INDEX `idx_crm_notes_related` (`related_type`, `related_id`),
    CONSTRAINT `fk_crm_notes_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='ملاحظات CRM';

-- ------------------------------------------------------------
-- 9) الاجتماعات / المواعيد (Meetings & Appointments) - نفس أعمدة fillable + تفاصيل الموعد
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `crm_meetings` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `user_id` INT(11) NOT NULL COMMENT 'صاحب الحساب (Tenant)',
    `organizer_user_id` INT(11) DEFAULT NULL,
    `related_type` VARCHAR(30) DEFAULT NULL,
    `related_id` INT(11) DEFAULT NULL,
    `contact_id` INT(11) DEFAULT NULL COMMENT 'العميل صاحب الموعد (Appointments - بند 18)',
    `title` VARCHAR(255) NOT NULL,
    `purpose` VARCHAR(255) DEFAULT NULL,
    `meeting_link` VARCHAR(500) DEFAULT NULL,
    `location` VARCHAR(255) DEFAULT NULL,
    `timezone` VARCHAR(60) DEFAULT NULL,
    `starts_at` DATETIME NOT NULL,
    `ends_at` DATETIME DEFAULT NULL,
    `status` ENUM('scheduled', 'confirmed', 'completed', 'cancelled', 'no_show') NOT NULL DEFAULT 'scheduled',
    `summary` TEXT DEFAULT NULL,
    `notes` TEXT DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_crm_meetings_user` (`user_id`),
    INDEX `idx_crm_meetings_contact` (`contact_id`),
    INDEX `idx_crm_meetings_starts` (`starts_at`),
    INDEX `idx_crm_meetings_status` (`status`),
    CONSTRAINT `fk_crm_meetings_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_crm_meetings_contact` FOREIGN KEY (`contact_id`) REFERENCES `crm_contacts` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='اجتماعات/مواعيد CRM';

-- ------------------------------------------------------------
-- 10) مصادر الـLeads القابلة للتخصيص (بند 4)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `crm_lead_sources` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `user_id` INT(11) DEFAULT NULL COMMENT 'NULL = مصدر افتراضي عام لكل الحسابات',
    `name` VARCHAR(150) NOT NULL,
    `source_key` VARCHAR(100) NOT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_crm_lead_sources_user` (`user_id`),
    CONSTRAINT `fk_crm_lead_sources_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='مصادر الـLeads القابلة للتخصيص';

-- ============================================================
-- Seed Data: Pipeline افتراضي + مراحله (نفس الأسماء المستخدمة في
-- CrmController::listPipelineStages الحالي WHERE agency_id IS NULL)
-- ============================================================

INSERT INTO `crm_pipelines` (`id`, `user_id`, `name`, `pipeline_key`, `is_default`, `sort_order`)
SELECT 1, NULL, 'المسار الافتراضي', 'default', 1, 0
WHERE NOT EXISTS (SELECT 1 FROM `crm_pipelines` WHERE `pipeline_key` = 'default' AND `user_id` IS NULL);

INSERT INTO `crm_pipeline_stages` (`agency_id`, `pipeline_id`, `name`, `slug`, `sort_order`, `win_probability`, `is_won`, `is_lost`, `color`)
SELECT NULL, (SELECT id FROM `crm_pipelines` WHERE `pipeline_key` = 'default' AND `user_id` IS NULL LIMIT 1),
       stage.name, stage.slug, stage.sort_order, stage.win_probability, stage.is_won, stage.is_lost, stage.color
FROM (
    SELECT 'عميل جديد' AS name, 'new_lead' AS slug, 0 AS sort_order, 5 AS win_probability, 0 AS is_won, 0 AS is_lost, '#6366f1' AS color
    UNION ALL SELECT 'مؤهّل', 'qualified', 1, 20, 0, 0, '#0ea5e9'
    UNION ALL SELECT 'تم التواصل', 'contacted', 2, 35, 0, 0, '#14b8a6'
    UNION ALL SELECT 'عرض سعر', 'proposal', 3, 55, 0, 0, '#f59e0b'
    UNION ALL SELECT 'تفاوض', 'negotiation', 4, 75, 0, 0, '#f97316'
    UNION ALL SELECT 'مكسوبة', 'won', 5, 100, 1, 0, '#22c55e'
    UNION ALL SELECT 'خاسرة', 'lost', 6, 0, 0, 1, '#ef4444'
) AS stage
WHERE NOT EXISTS (SELECT 1 FROM `crm_pipeline_stages` WHERE `agency_id` IS NULL);

INSERT INTO `crm_lead_sources` (`user_id`, `name`, `source_key`, `is_active`)
SELECT NULL, src.name, src.source_key, 1
FROM (
    SELECT 'الموقع الإلكتروني' AS name, 'website' AS source_key
    UNION ALL SELECT 'مواقع التواصل الاجتماعي', 'social_media'
    UNION ALL SELECT 'إعلانات', 'advertising'
    UNION ALL SELECT 'واتساب', 'whatsapp'
    UNION ALL SELECT 'توصية', 'referral'
    UNION ALL SELECT 'إدخال يدوي', 'manual'
    UNION ALL SELECT 'استيراد', 'import'
    UNION ALL SELECT 'أخرى', 'other'
) AS src
WHERE NOT EXISTS (SELECT 1 FROM `crm_lead_sources` WHERE `user_id` IS NULL);
