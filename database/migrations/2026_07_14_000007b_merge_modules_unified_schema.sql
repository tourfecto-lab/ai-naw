-- ============================================================
-- Tourfecto - Migration: دمج الموديولات الخمسة (SEO/Social/Creative/
-- White-Label/Marketing Assistant) داخل قاعدة البيانات الموحدة.
-- @version 1.0.0  @date 2026-07-14
--
-- مبدأ هذه الهجرة: لا تكرار. كل مفهوم كان له جدول مطابق فعليًا في
-- الموقع الأساسي أُعيد استخدامه بدل إنشاء جدول موازٍ:
--   - SEO/AEO/GEO         -> `ai_reports` + `ai_articles` (موجودان مسبقًا)
--   - Google Business     -> `platform_connections` (موجود مسبقًا، ووُسِّع
--                            هنا ليشمل منصات السوشيال ميديا كمان بدل جدول
--                            social_accounts منفصل من الموديول الأصلي)
--   - CRM / التقييمات     -> `reviews` (موجود مسبقًا)
--   - رصيد AI            -> أعمدة `subscriptions.ai_credits*` (موجودة)،
--                            وُسِّعت هنا بدل جدول ai_credit_ledger منفصل
--   - Queue لكل الموديولات -> جدول `jobs` الموحد (موجود مسبقًا) بدل:
--                            publishing_queue / queue_jobs / generation_jobs
--   - Activity Log        -> جدول `activity_logs` جديد وحيد يجمع
--                            (SEO Hub + Marketing Hub + White-Label) بدل
--                            3 جداول منفصلة بنفس الغرض
--
-- الجداول الجديدة فعليًا (مفاهيم غير موجودة في الموقع الأساسي إطلاقًا):
--   social_posts, social_post_targets  (محتوى السوشيال ميديا والجدولة)
--   media_items, video_scripts          (Creative Studio)
--   ai_assistant_interactions           (Marketing Assistant)
--   agencies, agency_branding, agency_domains, agency_clients,
--   agency_email_templates              (White-Label multi-tenancy)
--   ad_campaigns                        (إدارة الإعلانات - لا يوجد أي
--                                         موديول مرفوع يغطيها؛ جدول جديد
--                                         بسيط بنفس نمط platform_connections)
-- ============================================================

-- ------------------------------------------------------------
-- 1) توسعة platform_connections ليشمل منصات السوشيال ميديا
--    (بدل جدول social_accounts منفصل من ai-marketing-automation-hub)
-- ------------------------------------------------------------
ALTER TABLE `platform_connections`
    MODIFY COLUMN `platform` VARCHAR(50) NOT NULL
        COMMENT 'google_business, tripadvisor, facebook, instagram, linkedin, tiktok, twitter_x, google_ads, meta_ads';

-- ------------------------------------------------------------
-- 2) توسعة subscriptions برصيد موحد للموديولات الجديدة
--    (بدل ai_credit_ledger الخاص بالموديول الأصلي)
-- ------------------------------------------------------------
SET @__fx_col_1_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'subscriptions' AND COLUMN_NAME = 'social_posts_limit'
);
SET @__fx_col_1_sql := IF(@__fx_col_1_exists = 0,
    'ALTER TABLE `subscriptions` ADD COLUMN `social_posts_limit` INT(11) NOT NULL DEFAULT 100
        COMMENT ''حد منشورات السوشيال ميديا شهريًا'' AFTER `competitor_analysis_used`',
    'SELECT 1');
PREPARE __fx_col_1_stmt FROM @__fx_col_1_sql;
EXECUTE __fx_col_1_stmt;
DEALLOCATE PREPARE __fx_col_1_stmt;

SET @__fx_col_2_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'subscriptions' AND COLUMN_NAME = 'social_posts_used'
);
SET @__fx_col_2_sql := IF(@__fx_col_2_exists = 0,
    'ALTER TABLE `subscriptions` ADD COLUMN `social_posts_used` INT(11) NOT NULL DEFAULT 0
        COMMENT ''منشورات السوشيال ميديا المستخدمة'' AFTER `social_posts_limit`',
    'SELECT 1');
PREPARE __fx_col_2_stmt FROM @__fx_col_2_sql;
EXECUTE __fx_col_2_stmt;
DEALLOCATE PREPARE __fx_col_2_stmt;

SET @__fx_col_3_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'subscriptions' AND COLUMN_NAME = 'media_credits_limit'
);
SET @__fx_col_3_sql := IF(@__fx_col_3_exists = 0,
    'ALTER TABLE `subscriptions` ADD COLUMN `media_credits_limit` INT(11) NOT NULL DEFAULT 50
        COMMENT ''حد توليد الوسائط (صور/فيديو) شهريًا'' AFTER `social_posts_used`',
    'SELECT 1');
PREPARE __fx_col_3_stmt FROM @__fx_col_3_sql;
EXECUTE __fx_col_3_stmt;
DEALLOCATE PREPARE __fx_col_3_stmt;

SET @__fx_col_4_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'subscriptions' AND COLUMN_NAME = 'media_credits_used'
);
SET @__fx_col_4_sql := IF(@__fx_col_4_exists = 0,
    'ALTER TABLE `subscriptions` ADD COLUMN `media_credits_used` INT(11) NOT NULL DEFAULT 0
        COMMENT ''رصيد الوسائط المستخدم'' AFTER `media_credits_limit`',
    'SELECT 1');
PREPARE __fx_col_4_stmt FROM @__fx_col_4_sql;
EXECUTE __fx_col_4_stmt;
DEALLOCATE PREPARE __fx_col_4_stmt;

SET @__fx_col_5_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'subscriptions' AND COLUMN_NAME = 'max_agencies'
);
SET @__fx_col_5_sql := IF(@__fx_col_5_exists = 0,
    'ALTER TABLE `subscriptions` ADD COLUMN `max_agencies` INT(11) NOT NULL DEFAULT 0
        COMMENT ''عدد الوكالات (White-Label) المسموح إنشاؤها؛ 0 = غير مفعّل'' AFTER `media_credits_used`',
    'SELECT 1');
PREPARE __fx_col_5_stmt FROM @__fx_col_5_sql;
EXECUTE __fx_col_5_stmt;
DEALLOCATE PREPARE __fx_col_5_stmt;

-- ------------------------------------------------------------
-- 3) منشورات السوشيال ميديا (من ai-marketing-automation-hub)
--    جدول محتوى واحد + جدول أهداف نشر منفصل (منصة/موعد لكل هدف)
--    بدل دمج كل شيء في جدول واحد يصعب توسيعه.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `social_posts` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `user_id` INT(11) NOT NULL,
    `website_id` INT(11) DEFAULT NULL COMMENT 'الموقع/النشاط المرتبط (اختياري)',
    `content` TEXT NOT NULL COMMENT 'نص المنشور',
    `media_item_id` INT(11) DEFAULT NULL COMMENT 'صورة/فيديو مرتبط من Creative Studio',
    `hashtags` TEXT DEFAULT NULL COMMENT 'JSON array',
    `status` ENUM('draft','scheduled','publishing','published','failed') NOT NULL DEFAULT 'draft',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`website_id`) REFERENCES `websites`(`id`) ON DELETE SET NULL,
    INDEX `idx_user_id` (`user_id`),
    INDEX `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='محتوى منشورات السوشيال ميديا';

CREATE TABLE IF NOT EXISTS `social_post_targets` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `social_post_id` INT(11) NOT NULL,
    `platform_connection_id` INT(11) NOT NULL COMMENT 'يشير لـ platform_connections.id',
    `scheduled_at` TIMESTAMP NULL DEFAULT NULL,
    `published_at` TIMESTAMP NULL DEFAULT NULL,
    `external_post_id` VARCHAR(255) DEFAULT NULL COMMENT 'معرف المنشور في المنصة بعد النشر',
    `status` ENUM('pending','scheduled','publishing','published','failed') NOT NULL DEFAULT 'pending',
    `last_error` TEXT DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    FOREIGN KEY (`social_post_id`) REFERENCES `social_posts`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`platform_connection_id`) REFERENCES `platform_connections`(`id`) ON DELETE CASCADE,
    INDEX `idx_scheduled_at` (`scheduled_at`),
    INDEX `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='أهداف نشر كل منصة/موعد لكل منشور';

-- ------------------------------------------------------------
-- 4) Creative Studio (من ai-creative-studio)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `media_items` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `user_id` INT(11) NOT NULL,
    `type` ENUM('social_image','marketing_image','instagram_post','facebook_cover',
                'youtube_thumbnail','story','reels_cover','short_video') NOT NULL,
    `prompt` TEXT DEFAULT NULL COMMENT 'وصف التوليد المُدخل',
    `file_path` VARCHAR(500) DEFAULT NULL COMMENT 'مسار الملف داخل storage/uploads',
    `thumbnail_path` VARCHAR(500) DEFAULT NULL,
    `width` INT(11) DEFAULT NULL,
    `height` INT(11) DEFAULT NULL,
    `status` ENUM('generating','completed','failed') NOT NULL DEFAULT 'generating',
    `error_message` TEXT DEFAULT NULL,
    `job_id` INT(11) DEFAULT NULL COMMENT 'يشير لـ jobs.id عند التوليد غير المتزامن',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    INDEX `idx_user_id` (`user_id`),
    INDEX `idx_type` (`type`),
    INDEX `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='مكتبة الوسائط المولّدة بالذكاء الاصطناعي';

CREATE TABLE IF NOT EXISTS `video_scripts` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `user_id` INT(11) NOT NULL,
    `topic` VARCHAR(500) NOT NULL,
    `platform` ENUM('tiktok','instagram_reels','youtube_shorts','general') NOT NULL DEFAULT 'general',
    `duration_seconds` INT(11) DEFAULT 30,
    `script_text` LONGTEXT DEFAULT NULL,
    `scenes` TEXT DEFAULT NULL COMMENT 'JSON array من المشاهد',
    `status` ENUM('generating','completed','failed') NOT NULL DEFAULT 'generating',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    INDEX `idx_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='سكربتات الفيديوهات القصيرة';

-- ------------------------------------------------------------
-- 5) Marketing Assistant (من ai-marketing-assistant)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ai_assistant_interactions` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `user_id` INT(11) NOT NULL,
    `type` VARCHAR(50) NOT NULL COMMENT 'نوع الأداة (ad_copy, email_subject, slogan...)',
    `title` VARCHAR(255) NOT NULL,
    `input_payload` LONGTEXT NOT NULL,
    `output` LONGTEXT NOT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    INDEX `idx_user_id` (`user_id`),
    INDEX `idx_type` (`type`),
    INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='سجل استخدام مساعد التسويق الذكي';

-- ------------------------------------------------------------
-- 6) White-Label / Multi-Tenancy (من ai-white-label-hub)
--    ملاحظة: agency_users الأصلي من الموديول أُلغي بالكامل. الوكالة هي
--    مجرد "مساحة عمل" مملوكة لمستخدم موجود فعليًا في users (role
--    مناسب)، وعملاء الوكالة (agency_clients) يشيرون لـ users كذلك،
--    مش نظام دخول منفصل. هذا هو جوهر توحيد الـ Authentication.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `agencies` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `owner_user_id` INT(11) NOT NULL COMMENT 'صاحب الوكالة - يشير لـ users.id',
    `name` VARCHAR(255) NOT NULL,
    `slug` VARCHAR(100) NOT NULL UNIQUE,
    `status` ENUM('active','suspended','pending') NOT NULL DEFAULT 'pending',
    `plan_seats` INT(11) NOT NULL DEFAULT 5 COMMENT 'أقصى عدد عملاء تحت الوكالة',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    FOREIGN KEY (`owner_user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    INDEX `idx_owner` (`owner_user_id`),
    INDEX `idx_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='وكالات White-Label';

CREATE TABLE IF NOT EXISTS `agency_branding` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `agency_id` INT(11) NOT NULL,
    `logo_path` VARCHAR(500) DEFAULT NULL,
    `favicon_path` VARCHAR(500) DEFAULT NULL,
    `primary_color` VARCHAR(20) DEFAULT '#4F46E5',
    `secondary_color` VARCHAR(20) DEFAULT '#0EA5E9',
    `custom_css` LONGTEXT DEFAULT NULL,
    `support_email` VARCHAR(255) DEFAULT NULL,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_agency` (`agency_id`),
    FOREIGN KEY (`agency_id`) REFERENCES `agencies`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='هوية بصرية مخصصة لكل وكالة';

CREATE TABLE IF NOT EXISTS `agency_domains` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `agency_id` INT(11) NOT NULL,
    `domain` VARCHAR(255) NOT NULL UNIQUE,
    `status` ENUM('pending_dns','verified','failed') NOT NULL DEFAULT 'pending_dns',
    `ssl_status` ENUM('pending','active','failed') NOT NULL DEFAULT 'pending',
    `verified_at` TIMESTAMP NULL DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    FOREIGN KEY (`agency_id`) REFERENCES `agencies`(`id`) ON DELETE CASCADE,
    INDEX `idx_domain` (`domain`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='نطاقات مخصصة لكل وكالة';

CREATE TABLE IF NOT EXISTS `agency_clients` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `agency_id` INT(11) NOT NULL,
    `client_user_id` INT(11) NOT NULL COMMENT 'يشير لـ users.id - العميل نفسه مستخدم عادي بدور مناسب',
    `status` ENUM('active','suspended') NOT NULL DEFAULT 'active',
    `added_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_agency_client` (`agency_id`, `client_user_id`),
    FOREIGN KEY (`agency_id`) REFERENCES `agencies`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`client_user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='ربط عملاء كل وكالة بمستخدمين حقيقيين';

CREATE TABLE IF NOT EXISTS `agency_email_templates` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `agency_id` INT(11) NOT NULL,
    `template_key` VARCHAR(100) NOT NULL COMMENT 'welcome, invoice, report_ready...',
    `subject` VARCHAR(255) NOT NULL,
    `body_html` LONGTEXT NOT NULL,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_agency_template` (`agency_id`, `template_key`),
    FOREIGN KEY (`agency_id`) REFERENCES `agencies`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='قوالب بريد مخصصة لكل وكالة';

-- ------------------------------------------------------------
-- 7) إدارة الإعلانات — لا يغطيها أي موديول مرفوع، جدول جديد بسيط
--    بنفس نمط platform_connections تمامًا للاتساق المعماري.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ad_campaigns` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `user_id` INT(11) NOT NULL,
    `website_id` INT(11) DEFAULT NULL,
    `platform_connection_id` INT(11) DEFAULT NULL COMMENT 'يشير لـ platform_connections.id (google_ads/meta_ads)',
    `name` VARCHAR(255) NOT NULL,
    `objective` VARCHAR(100) DEFAULT NULL,
    `daily_budget` DECIMAL(10,2) DEFAULT NULL,
    `currency` VARCHAR(3) DEFAULT 'USD',
    `status` ENUM('draft','active','paused','completed') NOT NULL DEFAULT 'draft',
    `external_campaign_id` VARCHAR(255) DEFAULT NULL,
    `impressions` BIGINT UNSIGNED DEFAULT 0,
    `clicks` BIGINT UNSIGNED DEFAULT 0,
    `spend` DECIMAL(12,2) DEFAULT 0.00,
    `started_at` TIMESTAMP NULL DEFAULT NULL,
    `ended_at` TIMESTAMP NULL DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`website_id`) REFERENCES `websites`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`platform_connection_id`) REFERENCES `platform_connections`(`id`) ON DELETE SET NULL,
    INDEX `idx_user_id` (`user_id`),
    INDEX `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='حملات إعلانية';

-- ------------------------------------------------------------
-- 8) سجل نشاط موحّد لكل الموديولات (بدل 3 جداول منفصلة كانت مكررة
--    في SEO Hub وMarketing Automation Hub وWhite-Label Hub)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `activity_logs` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT(11) DEFAULT NULL COMMENT 'NULL لو الحدث نظامي بحت',
    `agency_id` INT(11) DEFAULT NULL,
    `module` VARCHAR(50) NOT NULL COMMENT 'seo, social, creative_studio, white_label, marketing_assistant, billing, system',
    `action` VARCHAR(100) NOT NULL COMMENT 'article.published, post.scheduled, media.generated...',
    `subject_type` VARCHAR(100) DEFAULT NULL,
    `subject_id` INT(11) DEFAULT NULL,
    `meta` JSON DEFAULT NULL,
    `ip_address` VARCHAR(45) DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`agency_id`) REFERENCES `agencies`(`id`) ON DELETE SET NULL,
    INDEX `idx_user_id` (`user_id`),
    INDEX `idx_module` (`module`),
    INDEX `idx_action` (`action`),
    INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='سجل نشاط موحّد لكل المنصة';
-- ============================================================
-- Tourfecto - Migration: توسعة أدوار المستخدمين لدمج White-Label
-- @version 1.0.0  @date 2026-07-14
--
-- ملاحظة مهمة: بحسب توثيق app/Models/User.php الحالي (تم التحقق من
-- phpMyAdmin مباشرة بتاريخ 2026-07-12)، enum الأدوار الفعلي الحالي هو:
--   ('super_admin', 'admin', 'manager', 'agent')
-- بدون قيمة 'user'. هذه الهجرة تضيف قيمتين جديدتين فقط ولا تحذف أو
-- تُعدّل أي قيمة موجودة، فهي غير مدمّرة (non-destructive) بالكامل.
--
-- إن كانت enum الحقيقية على السيرفر مختلفة عن هذا (يُتحقق منه عبر
-- database/audit_real_schema.php قبل التنفيذ)، يجب تعديل قائمة القيم
-- تحت لتطابق القيم الحقيقية بدل استبدالها بالكامل.
-- ============================================================

ALTER TABLE `users`
    MODIFY COLUMN `role` ENUM(
        'super_admin', 'admin', 'manager', 'agent',
        'agency_owner',   -- صاحب وكالة White-Label، له صلاحيات كاملة على عملائه فقط
        'client'          -- عميل تابع لوكالة (agency_clients) أو عميل مباشر بلا صلاحيات إدارية
    ) NOT NULL DEFAULT 'client'
    COMMENT 'دور المستخدم - تم توسيعه 2026-07-14 لدمج نظام White-Label دون حذف القيم الأصلية';
-- ============================================================
-- Tourfecto - Migration: جدول الإشعارات الموحّد
-- @version 1.0.0  @date 2026-07-14
-- لا يوجد في أي من الموديولات الخمسة أو الموقع الأساسي جدول إشعارات
-- جاهز؛ لوحة "الإشعارات" المطلوبة تحتاجه فعليًا، فهذا جدول جديد بسيط
-- بنفس نمط باقي الجداول الموحّدة (لا صلة له بأي موديول مصدر لأنه غير
-- موجود في أي منها أصلًا).
-- ============================================================
CREATE TABLE IF NOT EXISTS `notifications` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT(11) NOT NULL,
    `type` VARCHAR(50) NOT NULL COMMENT 'article_published, post_failed, subscription_expiring, review_received...',
    `title` VARCHAR(255) NOT NULL,
    `body` TEXT DEFAULT NULL,
    `link` VARCHAR(500) DEFAULT NULL COMMENT 'رابط داخلي عند الضغط على الإشعار',
    `read_at` TIMESTAMP NULL DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    INDEX `idx_user_unread` (`user_id`, `read_at`),
    INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='إشعارات المستخدمين';
-- ============================================================
-- Tourfecto - Migration: دمج ai-competitor-intelligence-hub
-- @version 1.0.0  @date 2026-07-14
--
-- ملاحظة: /ai/competitors و /ai/keywords في AIController.php كانا
-- صفحتين "قريبًا" فاضيتين فعليًا (بدون أي جدول أو منطق خلفهم). هذه
-- الهجرة تبني البنية التحتية الحقيقية لهم. تتبع المنافسين هنا "مستمر"
-- (تُحفظ مرة وتُتابع بمرور الوقت) بعكس `ai_reports.competitor_urls`
-- الموجود أصلًا واللي بيسجّل مقارنة لحظية واحدة فقط داخل كل تقرير.
-- ============================================================

-- ملاحظة تصحيح مهمة (2026-07-14): جدول `competitors` كان موجودًا بالفعل
-- في قاعدة البيانات الحقيقية من قبل هذه الهجرة (تأكيد من تصدير
-- phpMyAdmin حقيقي)، بأعمدة مختلفة عن الافتراض الأول:
--   id, website_id, user_id, competitor_domain, competitor_name,
--   competitor_tripadvisor_url, notes, is_active, created_at
-- لا يوجد عمود last_analyzed_at ولا name/url مباشرة. تم تحديث
-- app/Models/Competitor.php وCompetitorAnalysisService.php ليطابقا هذه
-- الأعمدة الحقيقية. لا CREATE TABLE هنا للمنافسين لأنه غير مطلوب إطلاقًا
-- (الجدول موجود ومُستخدم فعليًا - ده كان اكتشاف إن /ai/competitors كان
-- عنده جدول حقيقي خلفه من الأساس، بس الـ Controller كان بيتجاهله
-- ويعرض صفحة "قريبًا" ثابتة).

CREATE TABLE IF NOT EXISTS `tracked_keywords` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `user_id` INT(11) NOT NULL,
    `website_id` INT(11) NOT NULL,
    `keyword` VARCHAR(255) NOT NULL,
    `current_position` INT(11) DEFAULT NULL COMMENT 'ترتيب موقعك الحالي لهذه الكلمة',
    `search_volume` INT(11) DEFAULT NULL,
    `difficulty` TINYINT(3) UNSIGNED DEFAULT NULL COMMENT '0-100',
    `last_checked_at` TIMESTAMP NULL DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`website_id`) REFERENCES `websites`(`id`) ON DELETE CASCADE,
    UNIQUE KEY `uniq_website_keyword` (`website_id`, `keyword`),
    INDEX `idx_website_id` (`website_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='كلمات مفتاحية متابَعة لكل موقع';

CREATE TABLE IF NOT EXISTS `competitor_recommendations` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `competitor_id` INT(11) NOT NULL,
    `website_id` INT(11) NOT NULL,
    `recommendation` TEXT NOT NULL,
    `priority` ENUM('low','medium','high') NOT NULL DEFAULT 'medium',
    `status` ENUM('open','done','dismissed') NOT NULL DEFAULT 'open',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    FOREIGN KEY (`competitor_id`) REFERENCES `competitors`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`website_id`) REFERENCES `websites`(`id`) ON DELETE CASCADE,
    INDEX `idx_website_id` (`website_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='توصيات مبنية على مقارنة المنافسين';
-- ============================================================
-- Tourfecto - Migration: دمج القيمة الجديدة من ai-google-business-hub
-- @version 1.0.0  @date 2026-07-14
--
-- ملاحظة: OAuth واستيراد المراجعات لـ Google Business موجودان بالفعل
-- وشغّالان في ReputationController.php + platform_connections. القيمة
-- الجديدة الوحيدة غير الموجودة أصلًا هي: توليد محتوى منشورات Google
-- Business Profile بالذكاء الاصطناعي وجدولة نشرها تلقائيًا - فهذا فقط
-- ما تضيفه هذه الهجرة (بدل نسخ gbh_business_profiles/gbh_clients/
-- gbh_connections/gbh_reviews المكررة بالكامل مع platform_connections
-- وreviews الموجودين).
-- ============================================================

CREATE TABLE IF NOT EXISTS `gbp_content` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `user_id` INT(11) NOT NULL,
    `website_id` INT(11) NOT NULL,
    `type` ENUM('update','offer','event','product') NOT NULL DEFAULT 'update',
    `prompt` TEXT DEFAULT NULL,
    `generated_text` TEXT DEFAULT NULL,
    `media_item_id` INT(11) DEFAULT NULL COMMENT 'صورة مرتبطة من Creative Studio (اختياري)',
    `status` ENUM('draft','ready','failed') NOT NULL DEFAULT 'draft',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`website_id`) REFERENCES `websites`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`media_item_id`) REFERENCES `media_items`(`id`) ON DELETE SET NULL,
    INDEX `idx_website_id` (`website_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='محتوى منشورات Google Business Profile المولّد بالذكاء الاصطناعي';

CREATE TABLE IF NOT EXISTS `gbp_scheduled_posts` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `gbp_content_id` INT(11) NOT NULL,
    `platform_connection_id` INT(11) NOT NULL COMMENT 'يشير لـ platform_connections.id (platform=google_business)',
    `scheduled_at` TIMESTAMP NOT NULL,
    `published_at` TIMESTAMP NULL DEFAULT NULL,
    `google_post_id` VARCHAR(255) DEFAULT NULL,
    `status` ENUM('pending','processing','published','failed','cancelled') NOT NULL DEFAULT 'pending',
    `attempts` TINYINT(3) UNSIGNED NOT NULL DEFAULT 0,
    `error_message` VARCHAR(500) DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    FOREIGN KEY (`gbp_content_id`) REFERENCES `gbp_content`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`platform_connection_id`) REFERENCES `platform_connections`(`id`) ON DELETE CASCADE,
    INDEX `idx_due` (`status`, `scheduled_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدولة نشر منشورات Google Business Profile';
-- ============================================================
-- Tourfecto - Migration: أساس CRM حقيقي من ai-marketing-automation-pro
-- @version 1.0.0  @date 2026-07-14
--
-- نطاق هذه الهجرة (مقصود ومحدود): جهات الاتصال (Contacts) والعملاء
-- المحتملين (Leads) فقط - تقوّي لوحة CRM الموجودة فعلاً برصيد بيانات
-- حقيقي بدل الاكتفاء بعرض المواقع/المراجعات فقط.
--
-- الموديول الأصلي كان نظام Multi-Tenant منفصل بالكامل (`tenants` table
-- خاص به). تم استبدال `tenant_id` هنا بـ `agency_id` (اختياري، NULL
-- لو العميل مباشر بدون وكالة) ليشير لجدول `agencies` الموحّد الموجود
-- بالفعل من دمج White-Label، بدل نظام تعدد مساحات عمل موازٍ.
--
-- محرك الحملات البريدية/SMS/WhatsApp/الـ Workflows الآلية (24 جدول
-- إضافي في الموديول الأصلي: workflows, journeys, email_campaigns,
-- sms_messages, whatsapp_messages...) **لم يُدمج في هذه المرحلة** -
-- نطاقه وتعقيده (محرك تنفيذ Workflow كامل + تكامل بريد/SMS/WhatsApp
-- حقيقي) يحتاج مرحلة منفصلة مخطط لها بعناية بدل دمج جزئي غير مكتمل.
-- ============================================================

CREATE TABLE IF NOT EXISTS `crm_contacts` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `user_id` INT(11) NOT NULL COMMENT 'صاحب سجل جهة الاتصال (وكالة أو مستخدم مباشر)',
    `agency_id` INT(11) DEFAULT NULL COMMENT 'NULL = عميل مباشر بدون وكالة',
    `name` VARCHAR(255) NOT NULL,
    `email` VARCHAR(255) DEFAULT NULL,
    `phone` VARCHAR(50) DEFAULT NULL,
    `source` VARCHAR(100) DEFAULT NULL COMMENT 'website_form, manual, import...',
    `notes` TEXT DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`agency_id`) REFERENCES `agencies`(`id`) ON DELETE SET NULL,
    INDEX `idx_user_id` (`user_id`),
    INDEX `idx_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جهات اتصال CRM';

CREATE TABLE IF NOT EXISTS `crm_leads` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `contact_id` INT(11) NOT NULL,
    `owner_user_id` INT(11) DEFAULT NULL COMMENT 'المسؤول عن متابعة هذا العميل المحتمل',
    `status` ENUM('new','nurturing','qualified','disqualified','converted') NOT NULL DEFAULT 'new',
    `score` SMALLINT NOT NULL DEFAULT 0,
    `last_engagement_at` TIMESTAMP NULL DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    FOREIGN KEY (`contact_id`) REFERENCES `crm_contacts`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`owner_user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    INDEX `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='عملاء محتملون (Leads) مرتبطون بجهة اتصال';
-- ============================================================
-- Tourfecto - Migration: توسعة إدارة الإعلانات من ai-ads-management-hub
-- @version 1.0.0  @date 2026-07-14
--
-- ملاحظة: جدول `ad_accounts` الأصلي (اتصال OAuth بمنصات الإعلانات)
-- اتجوهل تمامًا - `platform_connections` الموجود عندك فعلاً (ومُوسَّع
-- مسبقًا ليشمل google_ads/meta_ads) بيغطي نفس الغرض بالظبط.
-- ============================================================

SET @__fx_col_1_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ad_campaigns' AND COLUMN_NAME = 'product_or_service'
);
SET @__fx_col_1_sql := IF(@__fx_col_1_exists = 0,
    'ALTER TABLE `ad_campaigns` ADD COLUMN `product_or_service` TEXT DEFAULT NULL COMMENT ''نص حر يُستخدم كأساس توليد الذكاء الاصطناعي'' AFTER `objective`',
    'SELECT 1');
PREPARE __fx_col_1_stmt FROM @__fx_col_1_sql;
EXECUTE __fx_col_1_stmt;
DEALLOCATE PREPARE __fx_col_1_stmt;

SET @__fx_col_2_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ad_campaigns' AND COLUMN_NAME = 'target_audience_brief'
);
SET @__fx_col_2_sql := IF(@__fx_col_2_exists = 0,
    'ALTER TABLE `ad_campaigns` ADD COLUMN `target_audience_brief` TEXT DEFAULT NULL AFTER `product_or_service`',
    'SELECT 1');
PREPARE __fx_col_2_stmt FROM @__fx_col_2_sql;
EXECUTE __fx_col_2_stmt;
DEALLOCATE PREPARE __fx_col_2_stmt;

SET @__fx_col_3_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ad_campaigns' AND COLUMN_NAME = 'budget_total'
);
SET @__fx_col_3_sql := IF(@__fx_col_3_exists = 0,
    'ALTER TABLE `ad_campaigns` ADD COLUMN `budget_total` DECIMAL(12,2) DEFAULT NULL AFTER `daily_budget`',
    'SELECT 1');
PREPARE __fx_col_3_stmt FROM @__fx_col_3_sql;
EXECUTE __fx_col_3_stmt;
DEALLOCATE PREPARE __fx_col_3_stmt;

SET @__fx_col_4_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ad_campaigns' AND COLUMN_NAME = 'start_date'
);
SET @__fx_col_4_sql := IF(@__fx_col_4_exists = 0,
    'ALTER TABLE `ad_campaigns` ADD COLUMN `start_date` DATE DEFAULT NULL AFTER `budget_total`',
    'SELECT 1');
PREPARE __fx_col_4_stmt FROM @__fx_col_4_sql;
EXECUTE __fx_col_4_stmt;
DEALLOCATE PREPARE __fx_col_4_stmt;

SET @__fx_col_5_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ad_campaigns' AND COLUMN_NAME = 'end_date'
);
SET @__fx_col_5_sql := IF(@__fx_col_5_exists = 0,
    'ALTER TABLE `ad_campaigns` ADD COLUMN `end_date` DATE DEFAULT NULL AFTER `start_date`',
    'SELECT 1');
PREPARE __fx_col_5_stmt FROM @__fx_col_5_sql;
EXECUTE __fx_col_5_stmt;
DEALLOCATE PREPARE __fx_col_5_stmt;

SET @__fx_col_6_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ad_campaigns' AND COLUMN_NAME = 'ai_generated'
);
SET @__fx_col_6_sql := IF(@__fx_col_6_exists = 0,
    'ALTER TABLE `ad_campaigns` ADD COLUMN `ai_generated` TINYINT(1) NOT NULL DEFAULT 0 AFTER `end_date`',
    'SELECT 1');
PREPARE __fx_col_6_stmt FROM @__fx_col_6_sql;
EXECUTE __fx_col_6_stmt;
DEALLOCATE PREPARE __fx_col_6_stmt;

SET @__fx_col_7_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ad_campaigns' AND COLUMN_NAME = 'auto_optimize'
);
SET @__fx_col_7_sql := IF(@__fx_col_7_exists = 0,
    'ALTER TABLE `ad_campaigns` ADD COLUMN `auto_optimize` TINYINT(1) NOT NULL DEFAULT 0 AFTER `ai_generated`',
    'SELECT 1');
PREPARE __fx_col_7_stmt FROM @__fx_col_7_sql;
EXECUTE __fx_col_7_stmt;
DEALLOCATE PREPARE __fx_col_7_stmt;

CREATE TABLE IF NOT EXISTS `ad_copies` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `campaign_id` INT(11) NOT NULL,
    `headline` VARCHAR(255) DEFAULT NULL,
    `description` VARCHAR(500) DEFAULT NULL,
    `primary_text` TEXT DEFAULT NULL COMMENT 'نص أطول لمنصات Meta/LinkedIn/TikTok',
    `call_to_action` VARCHAR(100) DEFAULT NULL,
    `variant_label` VARCHAR(50) DEFAULT NULL COMMENT 'A/B/C لاختبار التنويعات',
    `ai_generated` TINYINT(1) NOT NULL DEFAULT 1,
    `status` ENUM('pending_review','approved','rejected','live') NOT NULL DEFAULT 'pending_review',
    `performance_score` DECIMAL(5,2) DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    FOREIGN KEY (`campaign_id`) REFERENCES `ad_campaigns`(`id`) ON DELETE CASCADE,
    INDEX `idx_campaign_id` (`campaign_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='نصوص إعلانية مولّدة بالذكاء الاصطناعي';

CREATE TABLE IF NOT EXISTS `ad_keywords` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `campaign_id` INT(11) NOT NULL,
    `keyword` VARCHAR(255) NOT NULL,
    `match_type` ENUM('exact','phrase','broad','negative') NOT NULL DEFAULT 'broad',
    `ai_relevance_score` DECIMAL(5,2) DEFAULT NULL,
    `estimated_search_volume` INT(11) DEFAULT NULL,
    `estimated_cpc` DECIMAL(10,2) DEFAULT NULL,
    `ai_generated` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    FOREIGN KEY (`campaign_id`) REFERENCES `ad_campaigns`(`id`) ON DELETE CASCADE,
    INDEX `idx_campaign_id` (`campaign_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='كلمات مفتاحية مستهدفة لحملة إعلانية (بحث مدفوع، تختلف عن tracked_keywords العضوية)';

CREATE TABLE IF NOT EXISTS `ad_audiences` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `campaign_id` INT(11) NOT NULL,
    `name` VARCHAR(255) NOT NULL,
    `age_min` TINYINT(3) UNSIGNED DEFAULT NULL,
    `age_max` TINYINT(3) UNSIGNED DEFAULT NULL,
    `genders` VARCHAR(50) DEFAULT NULL,
    `locations_json` JSON DEFAULT NULL,
    `interests_json` JSON DEFAULT NULL,
    `estimated_reach` BIGINT UNSIGNED DEFAULT NULL,
    `ai_generated` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    FOREIGN KEY (`campaign_id`) REFERENCES `ad_campaigns`(`id`) ON DELETE CASCADE,
    INDEX `idx_campaign_id` (`campaign_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جماهير مستهدفة مقترحة لحملة إعلانية';

CREATE TABLE IF NOT EXISTS `ad_budget_recommendations` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `campaign_id` INT(11) NOT NULL,
    `recommended_daily_budget` DECIMAL(12,2) NOT NULL,
    `bid_strategy` VARCHAR(100) DEFAULT NULL,
    `reasoning` TEXT DEFAULT NULL,
    `confidence_score` DECIMAL(5,2) DEFAULT NULL COMMENT '0-100',
    `applied` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    FOREIGN KEY (`campaign_id`) REFERENCES `ad_campaigns`(`id`) ON DELETE CASCADE,
    INDEX `idx_campaign_id` (`campaign_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='توصيات ميزانية مولّدة بالذكاء الاصطناعي';

CREATE TABLE IF NOT EXISTS `ad_performance_reports` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `campaign_id` INT(11) NOT NULL,
    `date_start` DATE NOT NULL,
    `date_end` DATE NOT NULL,
    `impressions` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `clicks` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `conversions` DECIMAL(12,2) NOT NULL DEFAULT 0,
    `spend` DECIMAL(12,2) NOT NULL DEFAULT 0,
    `revenue` DECIMAL(12,2) DEFAULT NULL,
    `ctr` DECIMAL(6,4) DEFAULT NULL,
    `cpc` DECIMAL(10,4) DEFAULT NULL,
    `roas` DECIMAL(10,4) DEFAULT NULL,
    `synced_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    FOREIGN KEY (`campaign_id`) REFERENCES `ad_campaigns`(`id`) ON DELETE CASCADE,
    INDEX `idx_campaign_dates` (`campaign_id`, `date_start`, `date_end`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='تقارير أداء الحملات - تحتاج مزامنة حقيقية عبر platform_connections لاحقًا';

CREATE TABLE IF NOT EXISTS `ad_optimization_logs` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `campaign_id` INT(11) NOT NULL,
    `action_type` ENUM(
        'increase_budget','decrease_budget','pause_campaign',
        'rotate_ad_copy','add_keywords','add_negative_keywords',
        'narrow_audience','broaden_audience','no_action_recommended'
    ) NOT NULL,
    `description` TEXT NOT NULL,
    `ai_confidence` DECIMAL(5,2) DEFAULT NULL,
    `applied_automatically` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    FOREIGN KEY (`campaign_id`) REFERENCES `ad_campaigns`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='سجل قرارات تحسين الحملات';
-- ============================================================
-- Tourfecto - Migration: توسعة CRM من ai-crm-leads-hub
-- @version 1.0.0  @date 2026-07-14
--
-- ملاحظة: جداول `tenants`/`users`/`customers`/`leads` الأصلية اتجوهلت
-- بالكامل - `agencies`/`users`/`crm_contacts`/`crm_leads` الموجودة
-- عندك فعلاً بتغطي نفس المفهوم. `lead_scores` اتجوهل (عمود
-- `crm_leads.score` الموجود يكفي). `activities` اتجوهل (استخدم
-- `activity_logs` الموحّد الموجود بدل جدول تكرار). `whatsapp_messages`/
-- `email_messages` اتجوهلوا لأنهم يحتاجوا تكامل API حقيقي (WhatsApp
-- Business API / SMTP) غير موجود بعد - إضافتهم فارغين هتكون واجهة
-- بلا وظيفة حقيقية.
-- ============================================================

CREATE TABLE IF NOT EXISTS `crm_pipeline_stages` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `agency_id` INT(11) DEFAULT NULL COMMENT 'NULL = مرحلة افتراضية عامة لكل المستخدمين',
    `name` VARCHAR(100) NOT NULL,
    `slug` VARCHAR(100) NOT NULL,
    `sort_order` INT(11) NOT NULL DEFAULT 0,
    `win_probability` TINYINT(3) UNSIGNED NOT NULL DEFAULT 0 COMMENT '0-100',
    `is_won` TINYINT(1) NOT NULL DEFAULT 0,
    `is_lost` TINYINT(1) NOT NULL DEFAULT 0,
    `color` VARCHAR(20) DEFAULT '#6366f1',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    FOREIGN KEY (`agency_id`) REFERENCES `agencies`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='مراحل مسار البيع (Pipeline)';

-- مراحل افتراضية عامة جاهزة للاستخدام فورًا (agency_id = NULL)
INSERT INTO `crm_pipeline_stages` (`agency_id`, `name`, `slug`, `sort_order`, `win_probability`, `is_won`, `is_lost`, `color`) VALUES
(NULL, 'جديد', 'new', 1, 10, 0, 0, '#6366f1'),
(NULL, 'تواصل أولي', 'contacted', 2, 25, 0, 0, '#0EA5E9'),
(NULL, 'مؤهَّل', 'qualified', 3, 50, 0, 0, '#F59E0B'),
(NULL, 'عرض سعر', 'proposal', 4, 75, 0, 0, '#8B5CF6'),
(NULL, 'مكسوبة', 'won', 5, 100, 1, 0, '#22C55E'),
(NULL, 'خسرانة', 'lost', 6, 0, 0, 1, '#EF4444');

CREATE TABLE IF NOT EXISTS `crm_deals` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `owner_user_id` INT(11) DEFAULT NULL,
    `lead_id` INT(11) DEFAULT NULL,
    `contact_id` INT(11) DEFAULT NULL,
    `stage_id` INT(11) NOT NULL,
    `title` VARCHAR(200) NOT NULL,
    `value` DECIMAL(14,2) DEFAULT 0,
    `currency` VARCHAR(10) DEFAULT 'USD',
    `probability` TINYINT(3) UNSIGNED DEFAULT 0,
    `expected_close_date` DATE DEFAULT NULL,
    `closed_at` TIMESTAMP NULL DEFAULT NULL,
    `status` ENUM('open','won','lost') NOT NULL DEFAULT 'open',
    `lost_reason` VARCHAR(255) DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    FOREIGN KEY (`owner_user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`lead_id`) REFERENCES `crm_leads`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`contact_id`) REFERENCES `crm_contacts`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`stage_id`) REFERENCES `crm_pipeline_stages`(`id`),
    INDEX `idx_stage` (`stage_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='صفقات (Deals/Opportunities)';

CREATE TABLE IF NOT EXISTS `crm_tasks` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `assigned_to_user_id` INT(11) DEFAULT NULL,
    `related_type` ENUM('lead','contact','deal') DEFAULT NULL,
    `related_id` INT(11) DEFAULT NULL,
    `title` VARCHAR(200) NOT NULL,
    `description` TEXT DEFAULT NULL,
    `due_date` TIMESTAMP NULL DEFAULT NULL,
    `priority` ENUM('low','medium','high','urgent') NOT NULL DEFAULT 'medium',
    `status` ENUM('pending','in_progress','completed','cancelled') NOT NULL DEFAULT 'pending',
    `completed_at` TIMESTAMP NULL DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    FOREIGN KEY (`assigned_to_user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    INDEX `idx_related` (`related_type`, `related_id`),
    INDEX `idx_due_date` (`due_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='مهام متابعة CRM';

CREATE TABLE IF NOT EXISTS `crm_meetings` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `organizer_user_id` INT(11) DEFAULT NULL,
    `related_type` ENUM('lead','contact','deal') DEFAULT NULL,
    `related_id` INT(11) DEFAULT NULL,
    `title` VARCHAR(200) NOT NULL,
    `meeting_link` VARCHAR(255) DEFAULT NULL,
    `starts_at` TIMESTAMP NOT NULL,
    `ends_at` TIMESTAMP NULL DEFAULT NULL,
    `status` ENUM('scheduled','completed','cancelled','no_show') NOT NULL DEFAULT 'scheduled',
    `summary` TEXT DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    FOREIGN KEY (`organizer_user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    INDEX `idx_related` (`related_type`, `related_id`),
    INDEX `idx_starts_at` (`starts_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='اجتماعات CRM';

CREATE TABLE IF NOT EXISTS `crm_notes` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `author_user_id` INT(11) DEFAULT NULL,
    `related_type` ENUM('lead','contact','deal') NOT NULL,
    `related_id` INT(11) NOT NULL,
    `body` TEXT NOT NULL,
    `pinned` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    FOREIGN KEY (`author_user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    INDEX `idx_related` (`related_type`, `related_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='ملاحظات CRM';
-- ============================================================
-- Tourfecto - Migration: توسعة التحليلات من ai-analytics-insights-hub
-- @version 1.0.0  @date 2026-07-14
--
-- نطاق محدود عمدًا: أضفنا فقط جداول تخزين بيانات الزيارات/التحويلات/
-- الأجهزة/الدول - دي جداول "استقبال بيانات" (تحتاج مصدر حقيقي يغذّيها،
-- زي Google Analytics API عبر platform_connections). باقي جداول
-- الموديول الأصلي (landing_pages, social_insights, local_performance,
-- ai_search_traffic, keyword_rankings, user_behavior, generated_reports)
-- اتأجّلوا لمرحلة تالية - إضافتهم دلوقتي هتبني واجهة بلا مصدر بيانات
-- حقيقي وراها.
-- ============================================================

CREATE TABLE IF NOT EXISTS `analytics_traffic` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `website_id` INT(11) NOT NULL,
    `date` DATE NOT NULL,
    `sessions` INT(11) NOT NULL DEFAULT 0,
    `users` INT(11) NOT NULL DEFAULT 0,
    `pageviews` INT(11) NOT NULL DEFAULT 0,
    `bounce_rate` DECIMAL(5,2) DEFAULT NULL,
    `avg_session_duration_seconds` INT(11) DEFAULT NULL,
    `source` VARCHAR(50) DEFAULT 'manual' COMMENT 'google_analytics, manual...',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    FOREIGN KEY (`website_id`) REFERENCES `websites`(`id`) ON DELETE CASCADE,
    UNIQUE KEY `uniq_website_date` (`website_id`, `date`),
    INDEX `idx_date` (`date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='زيارات الموقع اليومية';

CREATE TABLE IF NOT EXISTS `analytics_conversions` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `website_id` INT(11) NOT NULL,
    `date` DATE NOT NULL,
    `goal_name` VARCHAR(100) NOT NULL COMMENT 'booking, contact_form, whatsapp_click...',
    `conversions` INT(11) NOT NULL DEFAULT 0,
    `revenue` DECIMAL(12,2) DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    FOREIGN KEY (`website_id`) REFERENCES `websites`(`id`) ON DELETE CASCADE,
    INDEX `idx_website_date` (`website_id`, `date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='أهداف وتحويلات الموقع';

CREATE TABLE IF NOT EXISTS `analytics_device_breakdown` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `website_id` INT(11) NOT NULL,
    `date` DATE NOT NULL,
    `device_type` ENUM('desktop','mobile','tablet') NOT NULL,
    `sessions` INT(11) NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    FOREIGN KEY (`website_id`) REFERENCES `websites`(`id`) ON DELETE CASCADE,
    UNIQUE KEY `uniq_website_date_device` (`website_id`, `date`, `device_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='توزيع الزيارات حسب نوع الجهاز';

CREATE TABLE IF NOT EXISTS `analytics_country_breakdown` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `website_id` INT(11) NOT NULL,
    `date` DATE NOT NULL,
    `country_code` CHAR(2) NOT NULL,
    `sessions` INT(11) NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    FOREIGN KEY (`website_id`) REFERENCES `websites`(`id`) ON DELETE CASCADE,
    UNIQUE KEY `uniq_website_date_country` (`website_id`, `date`, `country_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='توزيع الزيارات حسب الدولة';

