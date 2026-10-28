-- ============================================================
-- Tourfecto - الجزء الثاني من _PENDING_TO_RUN_ON_SERVER.sql (أقسام 2026-08-15 .. 2026-08-18)
-- اتفصل عن 2026_07_14_000007b لأنه بيعتمد على جداول بتتعمل في migrations لاحقة
-- (ai_conversations, user_api_keys...). كل الأقسام idempotent.
-- ============================================================

-- ------------------------------------------------------------
-- AI Chat Platform - تحسين تنافسي (2026-08-15):
-- عمود next_recommended_action على ai_conversations
-- (من migration منفصل 2026_08_15_000002_add_next_action_to_ai_conversations.sql)
-- ------------------------------------------------------------
SET @__fx_col_1_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ai_conversations' AND COLUMN_NAME = 'next_recommended_action'
);
SET @__fx_col_1_sql := IF(@__fx_col_1_exists = 0,
    'ALTER TABLE `ai_conversations` ADD COLUMN `next_recommended_action` VARCHAR(50) DEFAULT NULL
        COMMENT ''آخر إجراء تالي موصى به من AIConversationEngine (next_action: ask_destination, ask_dates, ask_budget, send_quote, handoff_to_human...)''
        AFTER `ai_summary`',
    'SELECT 1');
PREPARE __fx_col_1_stmt FROM @__fx_col_1_sql;
EXECUTE __fx_col_1_stmt;
DEALLOCATE PREPARE __fx_col_1_stmt;

SET @__fx_idx_1_exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ai_conversations' AND INDEX_NAME = 'idx_next_recommended_action'
);
SET @__fx_idx_1_sql := IF(@__fx_idx_1_exists = 0,
    'ALTER TABLE `ai_conversations` ADD INDEX `idx_next_recommended_action` (`next_recommended_action`)',
    'SELECT 1');
PREPARE __fx_idx_1_stmt FROM @__fx_idx_1_sql;
EXECUTE __fx_idx_1_stmt;
DEALLOCATE PREPARE __fx_idx_1_stmt;
-- ============================================================
-- Tourfecto - Migration: جداول GBP Reputation Intelligence (Tier 1/2)
-- @date 2026-08-15
--
-- قواعد الرد التلقائي على مراجعات Google Business Profile (نفس فكرة
-- Birdeye BirdAI / Podium Automation Rules). بدون هذا الجدول هتشتغل
-- باقي الموديول بشكل كامل، بس خاصية الرد الآلي + التنبيهات هتعطل.
-- ============================================================
CREATE TABLE IF NOT EXISTS `gbp_reply_rules` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `website_id` INT(11) NOT NULL,
    `user_id` INT(11) NOT NULL,
    `name` VARCHAR(150) NOT NULL,
    `trigger_type` ENUM('rating_range', 'sentiment') NOT NULL DEFAULT 'rating_range',
    `rating_min` DECIMAL(2,1) DEFAULT NULL,
    `rating_max` DECIMAL(2,1) DEFAULT NULL,
    `sentiment_label` ENUM('positive', 'neutral', 'negative', 'mixed') DEFAULT NULL,
    `action` ENUM('auto_reply', 'notify', 'auto_reply_and_notify') NOT NULL DEFAULT 'auto_reply',
    `reply_mode` ENUM('ai', 'custom') NOT NULL DEFAULT 'ai',
    `custom_reply` TEXT DEFAULT NULL,
    `priority` INT(11) NOT NULL DEFAULT 100,
    `enabled` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_reply_rules_website` (`website_id`),
    KEY `idx_reply_rules_user` (`user_id`),
    KEY `idx_reply_rules_enabled` (`enabled`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='قواعد الرد التلقائي على مراجعات GBP';

-- ============================================================
-- Onboarding Wizard v2 (Competitor Snapshots) - 2026-08-15
-- ============================================================

CREATE TABLE IF NOT EXISTS `onboarding_competitor_snapshots` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `website_id` INT(11) NOT NULL,
    `user_id` INT(11) NOT NULL,
    `competitor_id` BIGINT UNSIGNED DEFAULT NULL COMMENT 'id من جدول competitors لو تم التسجيل فيه بنجاح',
    `domain` VARCHAR(500) NOT NULL,
    `title` VARCHAR(500) DEFAULT NULL,
    `meta_description` VARCHAR(1000) DEFAULT NULL,
    `tech_signals` JSON DEFAULT NULL COMMENT 'إشارات تقنية حقيقية (مثلاً: cms_hint) من استجابة الـHTTP الفعلية',
    `http_status` INT(11) DEFAULT NULL,
    `error` VARCHAR(255) DEFAULT NULL,
    `fetched_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_onsnap_website` (`website_id`),
    KEY `idx_onsnap_user` (`user_id`),
    KEY `idx_onsnap_competitor` (`competitor_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='لقطات لحظية للصفحات الرئيسية للمنافسين وقت الـOnboarding - عرض فوري فقط';
-- ============================================
-- Tourfecto - AI Chat & Customer Communication Platform
-- Migration: Learning Loop (Resolution + Knowledge Gaps)
-- Created: 2026-08-16
--
-- ملاحظات:
--   1. هذا الملف إضافي بالكامل: لا يعدّل أي جدول أو بيانات موجودة.
--   2. شغّل هذا الملف مرة واحدة بعد نسخة احتياطية من قاعدة البيانات.
--   3. الفكرة مستوحاة من "Resolution Learning Loop" في Zendesk وIntercom
--      Fin Flywheel: نتعلم من نتيجة كل محادثة (هل الـAI حل فعلاً أم أحيل
--      لموظف؟) ونكتشف فجوات المعرفة (أسئلة لم يستطع الـAI الإجابة عنها)
--      لنقترح إضافتها لقاعدة المعرفة تلقائيًا.
-- ============================================

SET FOREIGN_KEY_CHECKS = 0;

-- ============================================
-- 1) أحداث نتيجة المحادثة (Resolution Events)
--    تُسجَّل عند إغلاق/حل محادثة: هل الـAI حلّها بالكامل، أم أحيلت لموظف؟
--    أساس حساب "AI Resolution Rate" الحقيقي والتحسين المستمر.
-- ============================================
CREATE TABLE IF NOT EXISTS `ai_resolution_events` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `website_id` INT(11) NOT NULL,
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

-- ============================================
-- 2) فجوات المعرفة (Knowledge Gaps)
--    أسئلة العملاء التي لم يستطع الـAI الإجابة عنها فتحوّل لموظف.
--    تُجمَّع حسب السؤال بعد تسويته نصيًا، وتُقترح لصاحب الشركة
--    لإضافتها لقاعدة المعرفة (Flywheel). نفس المحادثة لا تُحسب إلا مرة.
-- ============================================
CREATE TABLE IF NOT EXISTS `ai_knowledge_gaps` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `website_id` INT(11) NOT NULL,
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

SET FOREIGN_KEY_CHECKS = 1;
-- ============================================
-- Tourfecto - AI Chat & Customer Communication Platform
-- Migration: In-Chat Quotes (بيع داخل الشات)
-- Created: 2026-08-16
--
-- ملاحظات:
--   1. هذا الملف إضافي بالكامل: لا يعدّل أي جدول أو بيانات موجودة.
--   2. شغّل هذا الملف مرة واحدة بعد نسخة احتياطية من قاعدة البيانات.
--   3. الفكرة: الموظف/الوكيل يقدر يبني "عرض سعر" (Quote) جوه المحادثة
--      (بنود + أسعار)، يبعته للعميل عبر قناته، ويتتبع قبوله/رفضه —
--      من غير ما يسيب السياق. نفس نمط عروض أسعار Intercom/Zendesk.
-- ============================================

SET FOREIGN_KEY_CHECKS = 0;

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

SET FOREIGN_KEY_CHECKS = 1;
-- ============================================================
-- Tourfecto - Onboarding Wizard v3 (Professional Upgrades) - 2026-08-16
--
-- 1) onboarding_drafts: حفظ المسودة على السيرفر (استئناف عبر الأجهزة -
--    لو المستخدم سجّل الدخول من جهاز تاني، بيانات الـWizard بترجع له).
--    كمان بنخزن فيه "أقصى خطوة وصلها" عشان لوحة الفونيل الإدارية تحسب
--    معدل التسرب (drop-off) لكل خطوة بدل تخمين من الأحداث المتناثرة.
-- ============================================================

CREATE TABLE IF NOT EXISTS `onboarding_drafts` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `user_id` INT(11) NOT NULL,
    `draft` JSON DEFAULT NULL COMMENT 'بيانات نموذج الـWizard (business_name, main_url, industry, ...)',
    `step` TINYINT(4) NOT NULL DEFAULT 1 COMMENT 'أقصى خطوة وصلها المستخدم - للتتبع في لوحة الفونيل',
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_draft_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='مسودات Onboarding على السيرفر + تتبع أقصى خطوة (فونيل)';

-- ============================================================
-- Tourfecto - تفعيل موديول الشات (chat) في القائمة الجانبية للكل
-- @version 1.0.0  @date 2026-08-18
--
-- موديول الشات متاح افتراضيًا من الكود ومن مهاجرة feature flags
-- (2026_07_26_000031)، والـ FeatureFlagService بيرجع "متاحة" افتراضيًا
-- لأي ميزة غير مسجلة. السطر ده ضمانة صريحة: يشغّل مفتاح chat حتى لو
-- المهاجرة القديمة دي متشغّلتش على السيرفر، أو حد أطفاه من لوحة
-- الأدمن (Admin > Features) بالغلط. Idempotent - آمن يتنفذ أكتر من مرة.
-- ============================================================

CREATE TABLE IF NOT EXISTS `feature_flags` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `feature_key` VARCHAR(50) NOT NULL COMMENT 'نفس مفتاح القائمة الجانبية (ai_analyze, chat, crm...)',
    `label` VARCHAR(150) NOT NULL,
    `is_enabled` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'مفعّلة للكل بشكل افتراضي',
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_feature_key` (`feature_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='تفعيل/تعطيل الميزات للموقع كله - قابل للتعديل من لوحة الأدمن';

INSERT INTO `feature_flags` (`feature_key`, `label`, `is_enabled`) VALUES ('chat', 'الشات', 1)
ON DUPLICATE KEY UPDATE `is_enabled` = 1;
-- ============================================================
-- Tourfecto - Migration: صلاحيات مفاتيح API (API Key Scopes)
-- Settings Center Competitive Upgrade - Phase 16A.
--
-- يضيف عمود `scopes` لمفاتيح المستخدم الشخصية بحيث كل مفتاح يقدر
-- يُقيَّد بمجموعة صلاحيات محددة (زي GitHub Fine-grained PAT):
-- `NULL` = وصول كامل لكل شيء (التوافق الخلفي مع المفاتيح القديمة)؛
-- قيمة JSON = قائمة الصلاحيات الفعلية للمفتاح.
-- @version 1.0.0  @date 2026-08-16
-- ============================================================

SET @__fx_col_1_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_api_keys' AND COLUMN_NAME = 'scopes'
);
SET @__fx_col_1_sql := IF(@__fx_col_1_exists = 0,
    'ALTER TABLE `user_api_keys` ADD COLUMN `scopes` TEXT NULL DEFAULT NULL COMMENT ''JSON: قائمة الصلاحيات (NULL = كل الصلاحيات)'' AFTER `expires_at`',
    'SELECT 1');
PREPARE __fx_col_1_stmt FROM @__fx_col_1_sql;
EXECUTE __fx_col_1_stmt;
DEALLOCATE PREPARE __fx_col_1_stmt;
