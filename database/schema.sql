-- ============================================
-- Tourfecto - Full Database Schema
-- مخطط قاعدة البيانات الكامل
-- @version 1.0.0
-- @author Tourfecto Team
-- @copyright 2026 Tourfecto
-- ============================================

-- ============================================
-- 1. إنشاء قاعدة البيانات
-- ============================================
CREATE DATABASE IF NOT EXISTS `tourfecto_db` 
CHARACTER SET utf8mb4 
COLLATE utf8mb4_unicode_ci;

USE `tourfecto_db`;

-- ============================================
-- 2. إعدادات قاعدة البيانات
-- ============================================
SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = "STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION";
SET AUTOCOMMIT = 0;
SET TIME_ZONE = '+00:00';

-- ============================================
-- 3. جدول المستخدمين (users)
-- ============================================
DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
    `id` INT(11) NOT NULL AUTO_INCREMENT COMMENT 'المعرف الفريد للمستخدم',
    `owner_user_id` INT(11) NULL DEFAULT NULL COMMENT 'لو مش NULL: هذا الحساب عضو فريق تابع لصاحب الحساب ده (مش حساب مستقل بفوترة خاصة)',
    `company_name` VARCHAR(255) NOT NULL COMMENT 'اسم الشركة',
    `avatar_url` VARCHAR(500) DEFAULT NULL COMMENT 'رابط الصورة الشخصية (نسبي من جذر الموقع)',
    `job_title` VARCHAR(120) DEFAULT NULL COMMENT 'المسمى الوظيفي',
    `industry` VARCHAR(100) NULL DEFAULT NULL COMMENT 'صناعة/مجال النشاط - جزء من Workspace Settings',
    `workspace_logo_url` VARCHAR(255) NULL DEFAULT NULL COMMENT 'لوجو الـ Workspace (منفصل عن avatar_url الشخصي)',
    `bio` VARCHAR(500) DEFAULT NULL COMMENT 'نبذة مختصرة عن المستخدم (حد أقصى 500 حرف)',
    `email` VARCHAR(255) NOT NULL UNIQUE COMMENT 'البريد الإلكتروني',
    `password` VARCHAR(255) DEFAULT NULL COMMENT 'كلمة المرور القديمة (مشفرة) - خلف التوافق',
    `password_hash` VARCHAR(255) DEFAULT NULL COMMENT 'كلمة المرور (مشفرة) - العمود الصحيح',
    `password_changed_at` TIMESTAMP NULL DEFAULT NULL COMMENT 'آخر تغيير فعلي لكلمة المرور',
    `first_name` VARCHAR(100) DEFAULT NULL COMMENT 'الاسم الأول',
    `last_name` VARCHAR(100) DEFAULT NULL COMMENT 'اسم العائلة',
    `display_name` VARCHAR(120) DEFAULT NULL COMMENT 'الاسم المعروض للمستخدم',
    `phone` VARCHAR(50) DEFAULT NULL COMMENT 'رقم الهاتف',
    `country` VARCHAR(100) DEFAULT NULL COMMENT 'الدولة',
    `country_code` VARCHAR(10) DEFAULT NULL COMMENT 'رمز الدولة (ISO)',
    `language` VARCHAR(10) DEFAULT 'ar' COMMENT 'اللغة المفضلة',
    `timezone` VARCHAR(50) DEFAULT 'UTC' COMMENT 'المنطقة الزمنية',
    `role` ENUM('super_admin', 'admin', 'manager', 'agent', 'user') DEFAULT 'user' COMMENT 'دور المستخدم',
    `workspace_role` ENUM('admin', 'manager', 'sales', 'support', 'viewer') NULL DEFAULT NULL COMMENT 'دور العضو داخل الـ Workspace',
    `status` ENUM('active', 'inactive', 'banned') NOT NULL DEFAULT 'active' COMMENT 'حالة الحساب',
    `is_active` TINYINT(1) DEFAULT 1 COMMENT 'حالة النشاط',
    `email_verified` TINYINT(1) DEFAULT 0 COMMENT 'حالة التحقق من البريد',
    `email_verified_at` TIMESTAMP NULL DEFAULT NULL COMMENT 'تاريخ تأكيد البريد',
    `gdpr_consent` TINYINT(1) DEFAULT 0 COMMENT 'موافقة GDPR',
    `gdpr_consent_at` TIMESTAMP NULL DEFAULT NULL COMMENT 'تاريخ موافقة GDPR',
    `notify_email` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'تفعيل إشعارات البريد الإلكتروني',
    `notify_chat` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'تفعيل إشعارات المحادثات',
    `notify_reviews` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'تفعيل إشعارات المراجعات الجديدة',
    `notification_preferences` TEXT NULL DEFAULT NULL COMMENT 'JSON: تفضيل تشغيل/إيقاف لكل فئة إشعار',
    `notify_billing_usage` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'تفعيل إشعارات استهلاك الفوترة',
    `currency` VARCHAR(3) DEFAULT NULL COMMENT 'عملة تفضيل عرض الملف الشخصي (ISO 4217)',
    `two_factor_enabled` TINYINT(1) DEFAULT 0 COMMENT 'تفعيل المصادقة الثنائية',
    `two_factor_secret` VARCHAR(255) DEFAULT NULL COMMENT 'سر المصادقة الثنائية',
    `two_factor_recovery_codes` TEXT DEFAULT NULL COMMENT 'رموز الاسترداد للمصادقة الثنائية',
    `two_factor_confirmed_at` TIMESTAMP NULL DEFAULT NULL COMMENT 'تاريخ تأكيد المصادقة الثنائية',
    `api_token` VARCHAR(255) DEFAULT NULL COMMENT 'توكن API',
    `token_expiry` TIMESTAMP NULL DEFAULT NULL COMMENT 'تاريخ انتهاء التوكن',
    `remember_token` VARCHAR(255) DEFAULT NULL COMMENT 'توكن التذكر',
    `last_login` TIMESTAMP NULL DEFAULT NULL COMMENT 'آخر تسجيل دخول',
    `last_activity` TIMESTAMP NULL DEFAULT NULL COMMENT 'آخر نشاط',
    `login_attempts` INT(11) DEFAULT 0 COMMENT 'عدد محاولات تسجيل الدخول',
    `blocked_until` TIMESTAMP NULL DEFAULT NULL COMMENT 'ممنوع حتى التاريخ',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'تاريخ الإنشاء',
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'تاريخ التحديث',
    `deleted_at` TIMESTAMP NULL DEFAULT NULL COMMENT 'تاريخ الحذف (Soft Delete)',
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_email` (`email`),
    INDEX `idx_company` (`company_name`),
    INDEX `idx_api_token` (`api_token`),
    INDEX `idx_role` (`role`),
    INDEX `idx_is_active` (`is_active`),
    INDEX `idx_created_at` (`created_at`),
    INDEX `idx_email_verified` (`email_verified`),
    INDEX `idx_deleted_at` (`deleted_at`),
    INDEX `idx_last_activity` (`last_activity`),
    INDEX `idx_owner_user_id` (`owner_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول المستخدمين';

-- ============================================
-- 4. جدول باقات الاشتراك الفعلية (subscription_plans)
-- محرك الفوترة الحقيقي (Subscription::createSubscription) بيقرأ منه
-- بعمود plan_code (نمط: starter_monthly). منفصل تمامًا عن
-- plan_pricing_display (جدول العرض العام) - القيم هنا هي نفس أسعار
-- العرض بالدولار، والحدود هي نفس قيم plan_pricing_display.
-- ============================================
DROP TABLE IF EXISTS `subscription_plans`;
CREATE TABLE `subscription_plans` (
    `id` INT(11) NOT NULL AUTO_INCREMENT COMMENT 'المعرف الفريد',
    `plan_code` VARCHAR(50) NOT NULL COMMENT 'رمز الباقة الكامل (starter_monthly)',
    `name` VARCHAR(150) NOT NULL COMMENT 'اسم الباقة المعروض',
    `price` DECIMAL(10,2) NOT NULL DEFAULT 0 COMMENT 'السعر الفعلي (USD)',
    `currency` VARCHAR(10) NOT NULL DEFAULT 'USD' COMMENT 'العملة',
    `billing_cycle` VARCHAR(20) NOT NULL DEFAULT 'monthly' COMMENT 'monthly أو yearly',
    `ai_analysis_limit` INT(11) NOT NULL DEFAULT 0 COMMENT 'حد تحليلات AI',
    `ai_message_limit` INT(11) NOT NULL DEFAULT 0 COMMENT 'حد رسائل الشات الذكي',
    `review_auto_reply_limit` INT(11) NOT NULL DEFAULT 0 COMMENT 'حد الردود التلقائية',
    `features_json` TEXT DEFAULT NULL COMMENT 'JSON: مميزات إضافية (competitor_analysis, auto_pilot...)',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'تفعيل الباقة',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_plan_code` (`plan_code`),
    INDEX `idx_is_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='باقات الاشتراك الفعلية لمحرك الفوترة';

INSERT INTO `subscription_plans`
    (`plan_code`, `name`, `price`, `currency`, `billing_cycle`,
     `ai_analysis_limit`, `ai_message_limit`, `review_auto_reply_limit`,
     `features_json`, `is_active`)
VALUES
    ('starter_monthly', 'الباقة الأساسية', 49.00, 'USD', 'monthly',
     50, 100, 10, '{"competitor_analysis": 5, "auto_pilot": 0}', 1),
    ('starter_yearly', 'الباقة الأساسية', 490.00, 'USD', 'yearly',
     50, 100, 10, '{"competitor_analysis": 5, "auto_pilot": 0}', 1),
    ('professional_monthly', 'الباقة الاحترافية', 99.00, 'USD', 'monthly',
     200, 500, 50, '{"competitor_analysis": 20, "auto_pilot": 1}', 1),
    ('professional_yearly', 'الباقة الاحترافية', 990.00, 'USD', 'yearly',
     200, 500, 50, '{"competitor_analysis": 20, "auto_pilot": 1}', 1),
    ('enterprise_monthly', 'الباقة المؤسسية', 299.00, 'USD', 'monthly',
     1000, 2000, 200, '{"competitor_analysis": 100, "auto_pilot": 1}', 1),
    ('enterprise_yearly', 'الباقة المؤسسية', 2990.00, 'USD', 'yearly',
     1000, 2000, 200, '{"competitor_analysis": 100, "auto_pilot": 1}', 1)
ON DUPLICATE KEY UPDATE `plan_code` = `plan_code`;

-- ============================================
-- 4. جدول الاشتراكات (subscriptions)
-- ============================================
DROP TABLE IF EXISTS `subscriptions`;
CREATE TABLE `subscriptions` (
    `id` INT(11) NOT NULL AUTO_INCREMENT COMMENT 'المعرف الفريد للاشتراك',
    `user_id` INT(11) NOT NULL COMMENT 'معرف المستخدم',
    `plan_name` ENUM('starter', 'professional', 'enterprise') NOT NULL DEFAULT 'starter' COMMENT 'اسم الباقة',
    `plan_type` ENUM('monthly', 'yearly') NOT NULL DEFAULT 'monthly' COMMENT 'نوع الاشتراك',
    `plan_id` INT(11) DEFAULT NULL COMMENT 'مرجع لجدول subscription_plans',
    `status` ENUM('active', 'trialing', 'past_due', 'expired', 'cancelled', 'pending') NOT NULL DEFAULT 'pending' COMMENT 'حالة الاشتراك',
    `price` DECIMAL(10, 2) NOT NULL DEFAULT 0.00 COMMENT 'السعر',
    `currency` VARCHAR(3) DEFAULT 'USD' COMMENT 'العملة',
    `ai_credits` INT(11) NOT NULL DEFAULT 100 COMMENT 'رصيد الذكاء الاصطناعي',
    `ai_credits_used` INT(11) NOT NULL DEFAULT 0 COMMENT 'الرصيد المستخدم',
    `chat_credits` INT(11) NOT NULL DEFAULT 500 COMMENT 'رصيد الشات',
    `chat_credits_used` INT(11) NOT NULL DEFAULT 0 COMMENT 'رصيد الشات المستخدم',
    `review_credits` INT(11) NOT NULL DEFAULT 50 COMMENT 'رصيد المراجعات',
    `review_credits_used` INT(11) NOT NULL DEFAULT 0 COMMENT 'رصيد المراجعات المستخدم',
    `competitor_analysis_limit` INT(11) NOT NULL DEFAULT 10 COMMENT 'حد تحليل المنافسين',
    `competitor_analysis_used` INT(11) NOT NULL DEFAULT 0 COMMENT 'تحليل المنافسين المستخدم',
    `auto_pilot` TINYINT(1) DEFAULT 0 COMMENT 'تفعيل الطيار الآلي',
    `usage_ai_analysis_count` INT(11) DEFAULT 0 COMMENT 'عداد استهلاك تحليلات AI',
    `usage_ai_message_count` INT(11) DEFAULT 0 COMMENT 'عداد استهلاك رسائل الشات',
    `usage_review_reply_count` INT(11) DEFAULT 0 COMMENT 'عداد استهلاك الردود التلقائية',
    `last_usage_reset_at` DATETIME DEFAULT NULL COMMENT 'آخر تصفير للعدادات',
    `start_date` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'تاريخ البدء',
    `expiry_date` TIMESTAMP NULL DEFAULT NULL COMMENT 'تاريخ الانتهاء',
    `current_period_start` DATETIME DEFAULT NULL COMMENT 'بداية الفترة الحالية',
    `current_period_end` DATETIME DEFAULT NULL COMMENT 'نهاية الفترة الحالية',
    `trial_ends_at` DATETIME DEFAULT NULL COMMENT 'نهاية فترة التجربة',
    `cancel_at_period_end` TINYINT(1) DEFAULT 0 COMMENT 'إلغاء في نهاية الفترة',
    `cancelled_at` TIMESTAMP NULL DEFAULT NULL COMMENT 'تاريخ الإلغاء',
    `cancellation_reason` TEXT DEFAULT NULL COMMENT 'سبب الإلغاء',
    `last_billed_at` TIMESTAMP NULL DEFAULT NULL COMMENT 'تاريخ آخر فاتورة',
    `next_billing_at` TIMESTAMP NULL DEFAULT NULL COMMENT 'تاريخ الفاتورة التالية',
    `invoice_id` INT(11) DEFAULT NULL COMMENT 'معرف الفاتورة الحالية',
    `payment_method` VARCHAR(50) DEFAULT NULL COMMENT 'طريقة الدفع',
    `payment_gateway` VARCHAR(50) DEFAULT NULL COMMENT 'بوابة الدفع',
    `gateway_subscription_id` VARCHAR(255) DEFAULT NULL COMMENT 'معرف الاشتراك عند البوابة',
    `gateway_customer_id` VARCHAR(255) DEFAULT NULL COMMENT 'معرف العميل عند البوابة',
    `subscription_id_external` VARCHAR(255) DEFAULT NULL COMMENT 'معرف الاشتراك الخارجي',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'تاريخ الإنشاء',
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'تاريخ التحديث',
    PRIMARY KEY (`id`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    INDEX `idx_user_id` (`user_id`),
    INDEX `idx_status` (`status`),
    INDEX `idx_expiry` (`expiry_date`),
    INDEX `idx_plan` (`plan_name`),
    INDEX `idx_plan_id` (`plan_id`),
    INDEX `idx_created_at` (`created_at`),
    INDEX `idx_next_billing` (`next_billing_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول الاشتراكات';

-- ============================================
-- 5. جدول المواقع الإلكترونية (websites)
-- ============================================
DROP TABLE IF EXISTS `websites`;
CREATE TABLE `websites` (
    `id` INT(11) NOT NULL AUTO_INCREMENT COMMENT 'المعرف الفريد للموقع',
    `user_id` INT(11) NOT NULL COMMENT 'معرف المستخدم',
    `main_url` VARCHAR(500) NOT NULL COMMENT 'الرابط الرئيسي للموقع',
    `company_name` VARCHAR(255) DEFAULT NULL COMMENT 'اسم الشركة',
    `industry` VARCHAR(100) DEFAULT 'tourism' COMMENT 'نشاط الشركة',
    `target_language` VARCHAR(10) DEFAULT 'ar' COMMENT 'اللغة المستهدفة',
    `target_country` VARCHAR(100) DEFAULT NULL COMMENT 'الدولة المستهدفة',
    `meta_description` TEXT DEFAULT NULL COMMENT 'وصف الميتا',
    `logo_url` VARCHAR(500) DEFAULT NULL COMMENT 'رابط شعار الموقع',
    `social_links` JSON DEFAULT NULL COMMENT 'روابط التواصل الاجتماعي',
    `analytics_id` VARCHAR(100) DEFAULT NULL COMMENT 'معرف Google Analytics',
    `competitor_1_url` VARCHAR(500) DEFAULT NULL COMMENT 'رابط المنافس 1',
    `competitor_2_url` VARCHAR(500) DEFAULT NULL COMMENT 'رابط المنافس 2',
    `competitor_3_url` VARCHAR(500) DEFAULT NULL COMMENT 'رابط المنافس 3',
    `last_analysis_at` TIMESTAMP NULL DEFAULT NULL COMMENT 'تاريخ آخر تحليل',
    `is_verified` TINYINT(1) DEFAULT 0 COMMENT 'حالة التحقق من الموقع',
    `platform_user_id` VARCHAR(255) DEFAULT NULL COMMENT 'معرف المستخدم في المنصة',
    `platform_username` VARCHAR(255) DEFAULT NULL COMMENT 'اسم المستخدم في المنصة',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'تاريخ الإنشاء',
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'تاريخ التحديث',
    `deleted_at` TIMESTAMP NULL DEFAULT NULL COMMENT 'تاريخ الحذف (Soft Delete)',
    PRIMARY KEY (`id`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    INDEX `idx_user_id` (`user_id`),
    INDEX `idx_main_url` (`main_url`(255)),
    INDEX `idx_company` (`company_name`),
    INDEX `idx_industry` (`industry`),
    INDEX `idx_is_verified` (`is_verified`),
    INDEX `idx_created_at` (`created_at`),
    INDEX `idx_deleted_at` (`deleted_at`),
    INDEX `idx_last_analysis` (`last_analysis_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول المواقع الإلكترونية';

-- ============================================
-- 6. جدول تقارير الذكاء الاصطناعي (ai_reports)
-- ============================================
DROP TABLE IF EXISTS `ai_reports`;
CREATE TABLE `ai_reports` (
    `id` INT(11) NOT NULL AUTO_INCREMENT COMMENT 'المعرف الفريد للتقرير',
    `website_id` INT(11) NOT NULL COMMENT 'معرف الموقع',
    `user_id` INT(11) NOT NULL COMMENT 'معرف المستخدم',
    `report_type` ENUM('seo', 'aeo', 'geo', 'full') NOT NULL DEFAULT 'full' COMMENT 'نوع التقرير',
    `target_url` VARCHAR(500) NOT NULL COMMENT 'الرابط المستهدف',
    `competitor_urls` JSON NOT NULL COMMENT 'روابط المنافسين',
    `target_language` VARCHAR(10) DEFAULT 'ar' COMMENT 'اللغة المستهدفة',
    
    -- نتائج تحليل SEO
    `seo_keywords` JSON DEFAULT NULL COMMENT 'الكلمات المفتاحية SEO',
    `seo_title_suggestions` JSON DEFAULT NULL COMMENT 'اقتراحات العناوين SEO',
    `seo_meta_suggestions` JSON DEFAULT NULL COMMENT 'اقتراحات الميتا SEO',
    `seo_content_gaps` JSON DEFAULT NULL COMMENT 'فجوات المحتوى SEO',
    
    -- نتائج تحليل AEO
    `aeo_direct_answers` JSON DEFAULT NULL COMMENT 'الإجابات المباشرة AEO',
    `aeo_trust_signals` JSON DEFAULT NULL COMMENT 'إشارات الثقة AEO',
    `aeo_positioning_strategy` TEXT DEFAULT NULL COMMENT 'استراتيجية التموضع AEO',
    
    -- نتائج تحليل GEO
    `geo_faq_schema` JSON DEFAULT NULL COMMENT 'مخطط FAQ GEO',
    `geo_questions_generated` JSON DEFAULT NULL COMMENT 'الأسئلة المولدة GEO',
    `geo_map_integration` JSON DEFAULT NULL COMMENT 'تكامل الخرائط GEO',
    `geo_improvement_suggestions` TEXT DEFAULT NULL COMMENT 'اقتراحات التحسين GEO',
    
    -- النتيجة الكاملة
    `full_report_json` JSON NOT NULL COMMENT 'التقرير الكامل بصيغة JSON',
    
    -- إحصائيات التحليل
    `analysis_score` INT(11) DEFAULT 0 COMMENT 'درجة التحليل',
    `keywords_found` INT(11) DEFAULT 0 COMMENT 'عدد الكلمات المفتاحية المكتشفة',
    `competitors_analyzed` INT(11) DEFAULT 0 COMMENT 'عدد المنافسين المحللين',
    
    -- بيانات الكاش
    `cache_key` VARCHAR(255) DEFAULT NULL COMMENT 'مفتاح الكاش',
    `is_cached` TINYINT(1) DEFAULT 0 COMMENT 'حالة الكاش',
    `cached_until` TIMESTAMP NULL DEFAULT NULL COMMENT 'صلاحية الكاش حتى',
    
    -- حالة التقرير
    `status` ENUM('pending', 'processing', 'completed', 'failed') NOT NULL DEFAULT 'pending' COMMENT 'حالة التقرير',
    `error_message` TEXT DEFAULT NULL COMMENT 'رسالة الخطأ',
    
    -- API Usage
    `tokens_used` INT(11) DEFAULT 0 COMMENT 'عدد التوكنات المستخدمة',
    `cost_in_usd` DECIMAL(10, 6) DEFAULT 0.000000 COMMENT 'التكلفة بالدولار',
    `execution_time` INT(11) DEFAULT 0 COMMENT 'وقت التنفيذ بالمللي ثانية',
    `ai_model` VARCHAR(50) DEFAULT 'gemini-1.5-flash' COMMENT 'نموذج الذكاء الاصطناعي المستخدم',
    
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'تاريخ الإنشاء',
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'تاريخ التحديث',
    PRIMARY KEY (`id`),
    FOREIGN KEY (`website_id`) REFERENCES `websites`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    INDEX `idx_website_id` (`website_id`),
    INDEX `idx_user_id` (`user_id`),
    INDEX `idx_cache_key` (`cache_key`),
    INDEX `idx_created_at` (`created_at`),
    INDEX `idx_status` (`status`),
    INDEX `idx_report_type` (`report_type`),
    INDEX `idx_analysis_score` (`analysis_score`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول تقارير الذكاء الاصطناعي';

-- ============================================
-- 7. جدول المراجعات (reviews)
-- ============================================
DROP TABLE IF EXISTS `reviews`;
CREATE TABLE `reviews` (
    `id` INT(11) NOT NULL AUTO_INCREMENT COMMENT 'المعرف الفريد للمراجعة',
    `website_id` INT(11) NOT NULL COMMENT 'معرف الموقع',
    `user_id` INT(11) NOT NULL COMMENT 'معرف المستخدم',
    `source_platform` ENUM('tripadvisor', 'google_business', 'booking', 'expedia', 'trustpilot', 'other') NOT NULL COMMENT 'المنصة المصدر للمراجعة',
    `external_review_id` VARCHAR(255) DEFAULT NULL COMMENT 'المعرف الخارجي للمراجعة لدى المنصة',
    
    -- بيانات المراجع
    `reviewer_name` VARCHAR(255) DEFAULT NULL COMMENT 'اسم المراجع',
    `reviewer_email` VARCHAR(255) DEFAULT NULL COMMENT 'بريد المراجع (مشفر)',
    `reviewer_phone` VARCHAR(50) DEFAULT NULL COMMENT 'هاتف المراجع (مشفر)',
    `review_text` TEXT NOT NULL COMMENT 'نص المراجعة',
    `review_language` VARCHAR(10) DEFAULT 'en' COMMENT 'لغة المراجعة',
    `rating` DECIMAL(3, 2) DEFAULT 0.00 COMMENT 'التقييم',
    `review_date` TIMESTAMP NULL DEFAULT NULL COMMENT 'تاريخ المراجعة',
    
    -- تحليل المشاعر
    `sentiment_score` DECIMAL(3, 2) DEFAULT NULL COMMENT 'درجة المشاعر',
    `sentiment` ENUM('positive', 'neutral', 'negative') DEFAULT NULL COMMENT 'تصنيف المشاعر',
    `sentiment_confidence` DECIMAL(3, 2) DEFAULT NULL COMMENT 'ثقة التحليل',
    
    -- الرد الآلي
    `ai_generated_reply` TEXT DEFAULT NULL COMMENT 'الرد المولد بالذكاء الاصطناعي',
    `auto_reply_language` VARCHAR(10) DEFAULT 'en' COMMENT 'لغة الرد',
    `reply_sent` TINYINT(1) DEFAULT 0 COMMENT 'حالة إرسال الرد',
    `reply_sent_at` TIMESTAMP NULL DEFAULT NULL COMMENT 'تاريخ إرسال الرد',
    `reply_approved_by` INT(11) DEFAULT NULL COMMENT 'معرف المستخدم الذي وافق على الرد',
    `is_ai_generated` TINYINT(1) DEFAULT 1 COMMENT 'الرد مولد بالذكاء الاصطناعي',
    
    -- حالة المراجعة
    `is_processed` TINYINT(1) DEFAULT 0 COMMENT 'تمت المعالجة',
    `needs_attention` TINYINT(1) DEFAULT 0 COMMENT 'بحاجة إلى اهتمام',
    `processed_at` TIMESTAMP NULL DEFAULT NULL COMMENT 'تاريخ المعالجة',
    
    -- Metadata
    `webhook_raw_data` JSON DEFAULT NULL COMMENT 'بيانات Webhook الخام',
    `ip_address` VARCHAR(45) DEFAULT NULL COMMENT 'عنوان IP',
    `user_agent` TEXT DEFAULT NULL COMMENT 'متصفح المستخدم',
    
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'تاريخ الإنشاء',
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'تاريخ التحديث',
    PRIMARY KEY (`id`),
    FOREIGN KEY (`website_id`) REFERENCES `websites`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`reply_approved_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    INDEX `idx_website_id` (`website_id`),
    INDEX `idx_user_id` (`user_id`),
    INDEX `idx_source_platform` (`source_platform`),
    INDEX `idx_sentiment` (`sentiment`),
    INDEX `idx_rating` (`rating`),
    INDEX `idx_created_at` (`created_at`),
    INDEX `idx_is_processed` (`is_processed`),
    INDEX `idx_review_date` (`review_date`),
    INDEX `idx_reply_sent` (`reply_sent`),
    UNIQUE KEY `unique_platform_review` (`source_platform`, `external_review_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول المراجعات';

-- ============================================
-- 8. جدول رسائل الشات (chat_messages)
-- ============================================
DROP TABLE IF EXISTS `chat_messages`;
CREATE TABLE `chat_messages` (
    `id` INT(11) NOT NULL AUTO_INCREMENT COMMENT 'المعرف الفريد للرسالة',
    `website_id` INT(11) NOT NULL COMMENT 'معرف الموقع',
    `user_id` INT(11) NOT NULL COMMENT 'معرف المستخدم',
    `session_id` VARCHAR(255) DEFAULT NULL COMMENT 'معرف الجلسة',
    `platform` ENUM('whatsapp', 'telegram', 'messenger', 'webchat', 'other') NOT NULL DEFAULT 'whatsapp' COMMENT 'المنصة',
    `platform_message_id` VARCHAR(255) DEFAULT NULL COMMENT 'معرف الرسالة في المنصة',
    
    -- بيانات العميل
    `customer_name` VARCHAR(255) DEFAULT NULL COMMENT 'اسم العميل',
    `customer_phone` VARCHAR(50) DEFAULT NULL COMMENT 'هاتف العميل',
    `customer_email` VARCHAR(255) DEFAULT NULL COMMENT 'بريد العميل',
    `encrypted_phone` BLOB DEFAULT NULL COMMENT 'هاتف العميل مشفر',
    `encrypted_email` BLOB DEFAULT NULL COMMENT 'بريد العميل مشفر',
    
    -- محتوى الرسالة
    `message_direction` ENUM('incoming', 'outgoing') NOT NULL COMMENT 'اتجاه الرسالة',
    `message_text` TEXT NOT NULL COMMENT 'نص الرسالة',
    `message_language` VARCHAR(10) DEFAULT 'en' COMMENT 'لغة الرسالة',
    
    -- رد الذكاء الاصطناعي
    `ai_reply_generated` TEXT DEFAULT NULL COMMENT 'الرد المولد بالذكاء الاصطناعي',
    `ai_reply_language` VARCHAR(10) DEFAULT 'en' COMMENT 'لغة الرد',
    `ai_confidence_score` DECIMAL(3, 2) DEFAULT NULL COMMENT 'ثقة الرد',
    
    -- حالة البوت
    `bot_status` ENUM('pending_approval', 'approved', 'rejected', 'sent', 'failed') NOT NULL DEFAULT 'pending_approval' COMMENT 'حالة البوت',
    `is_auto_pilot` TINYINT(1) DEFAULT 0 COMMENT 'حالة الطيار الآلي',
    `approved_by_user_id` INT(11) DEFAULT NULL COMMENT 'معرف المستخدم الموافق',
    `approved_at` TIMESTAMP NULL DEFAULT NULL COMMENT 'تاريخ الموافقة',
    `sent_at` TIMESTAMP NULL DEFAULT NULL COMMENT 'تاريخ الإرسال',
    `conversation_started_at` TIMESTAMP NULL DEFAULT NULL COMMENT 'تاريخ بدء المحادثة',
    `conversation_ended_at` TIMESTAMP NULL DEFAULT NULL COMMENT 'تاريخ انتهاء المحادثة',
    `agent_responded` TINYINT(1) DEFAULT 0 COMMENT 'تم الرد من قبل الوكيل',
    
    -- Metadata
    `webhook_raw_data` JSON DEFAULT NULL COMMENT 'بيانات Webhook الخام',
    `ip_address` VARCHAR(45) DEFAULT NULL COMMENT 'عنوان IP',
    `user_agent` TEXT DEFAULT NULL COMMENT 'متصفح المستخدم',
    
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'تاريخ الإنشاء',
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'تاريخ التحديث',
    PRIMARY KEY (`id`),
    FOREIGN KEY (`website_id`) REFERENCES `websites`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`approved_by_user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    INDEX `idx_website_id` (`website_id`),
    INDEX `idx_user_id` (`user_id`),
    INDEX `idx_platform` (`platform`),
    INDEX `idx_bot_status` (`bot_status`),
    INDEX `idx_session_id` (`session_id`),
    INDEX `idx_created_at` (`created_at`),
    INDEX `idx_message_direction` (`message_direction`),
    INDEX `idx_is_auto_pilot` (`is_auto_pilot`),
    INDEX `idx_customer_phone` (`customer_phone`),
    INDEX `idx_bot_status_created` (`bot_status`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول رسائل الشات';

-- ============================================
-- 9. جدول إعدادات البوت (bot_settings)
-- ============================================
DROP TABLE IF EXISTS `bot_settings`;
CREATE TABLE `bot_settings` (
    `id` INT(11) NOT NULL AUTO_INCREMENT COMMENT 'المعرف الفريد للإعدادات',
    `user_id` INT(11) NOT NULL COMMENT 'معرف المستخدم',
    `website_id` INT(11) NOT NULL COMMENT 'معرف الموقع',
    `platform` ENUM('whatsapp', 'telegram', 'messenger', 'webchat', 'all') NOT NULL DEFAULT 'all' COMMENT 'المنصة',
    
    -- إعدادات التشغيل
    `is_enabled` TINYINT(1) DEFAULT 1 COMMENT 'حالة التفعيل',
    `auto_pilot` TINYINT(1) DEFAULT 0 COMMENT 'حالة الطيار الآلي',
    `requires_approval` TINYINT(1) DEFAULT 1 COMMENT 'طلب الموافقة',
    
    -- إعدادات الذكاء الاصطناعي
    `ai_model` VARCHAR(50) DEFAULT 'gemini-1.5-flash' COMMENT 'نموذج الذكاء الاصطناعي',
    `ai_temperature` DECIMAL(3, 2) DEFAULT 0.70 COMMENT 'درجة حرارة النموذج',
    `ai_max_tokens` INT(11) DEFAULT 2000 COMMENT 'الحد الأقصى للتوكنات',
    `ai_language` VARCHAR(10) DEFAULT 'auto' COMMENT 'لغة الذكاء الاصطناعي',
    
    -- إعدادات الردود
    `greeting_message` TEXT DEFAULT NULL COMMENT 'رسالة الترحيب',
    `farewell_message` TEXT DEFAULT NULL COMMENT 'رسالة الوداع',
    `fallback_message` TEXT DEFAULT NULL COMMENT 'رسالة الاحتياط',
    
    -- إعدادات التكامل
    `whatsapp_webhook_url` VARCHAR(500) DEFAULT NULL COMMENT 'رابط Webhook واتساب',
    `whatsapp_api_key` VARCHAR(255) DEFAULT NULL COMMENT 'مفتاح API واتساب',
    `whatsapp_phone_number` VARCHAR(50) DEFAULT NULL COMMENT 'رقم هاتف واتساب',
    `webhook_secret` VARCHAR(255) DEFAULT NULL COMMENT 'سر Webhook للتأمين',
    
    -- إعدادات الأمان
    `allowed_domains` JSON DEFAULT NULL COMMENT 'النطاقات المسموحة',
    `blocked_keywords` JSON DEFAULT NULL COMMENT 'الكلمات المحظورة',
    
    -- إعدادات الوقت
    `business_hours_start` TIME DEFAULT '09:00:00' COMMENT 'بداية ساعات العمل',
    `business_hours_end` TIME DEFAULT '18:00:00' COMMENT 'نهاية ساعات العمل',
    `timezone` VARCHAR(50) DEFAULT 'UTC' COMMENT 'المنطقة الزمنية',
    `auto_response_timeout` INT(11) DEFAULT 60 COMMENT 'مهلة الرد التلقائي بالثواني',
    `max_conversation_length` INT(11) DEFAULT 50 COMMENT 'الحد الأقصى لطول المحادثة',
    
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'تاريخ الإنشاء',
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'تاريخ التحديث',
    PRIMARY KEY (`id`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`website_id`) REFERENCES `websites`(`id`) ON DELETE CASCADE,
    INDEX `idx_user_id` (`user_id`),
    INDEX `idx_website_id` (`website_id`),
    INDEX `idx_platform` (`platform`),
    INDEX `idx_is_enabled` (`is_enabled`),
    INDEX `idx_auto_pilot` (`auto_pilot`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول إعدادات البوت';

-- ============================================
-- 10. جدول سجل استخدام API (api_usage_logs)
-- ============================================
DROP TABLE IF EXISTS `api_usage_logs`;
CREATE TABLE `api_usage_logs` (
    `id` INT(11) NOT NULL AUTO_INCREMENT COMMENT 'المعرف الفريد للسجل',
    `user_id` INT(11) NOT NULL COMMENT 'معرف المستخدم',
    `api_type` ENUM('gemini', 'whatsapp', 'tripadvisor', 'google', 'stripe', 'paypal', 'other') NOT NULL COMMENT 'نوع الـ API',
    `endpoint` VARCHAR(255) DEFAULT NULL COMMENT 'نقطة النهاية',
    `request_data` JSON DEFAULT NULL COMMENT 'بيانات الطلب',
    `response_data` JSON DEFAULT NULL COMMENT 'بيانات الاستجابة',
    `status_code` INT(11) DEFAULT NULL COMMENT 'رمز الحالة',
    `tokens_used` INT(11) DEFAULT 0 COMMENT 'عدد التوكنات المستخدمة',
    `cost_in_usd` DECIMAL(10, 6) DEFAULT 0.000000 COMMENT 'التكلفة بالدولار',
    `duration_ms` INT(11) DEFAULT 0 COMMENT 'مدة التنفيذ بالمللي ثانية',
    `ip_address` VARCHAR(45) DEFAULT NULL COMMENT 'عنوان IP',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'تاريخ الإنشاء',
    PRIMARY KEY (`id`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    INDEX `idx_user_id` (`user_id`),
    INDEX `idx_api_type` (`api_type`),
    INDEX `idx_status_code` (`status_code`),
    INDEX `idx_created_at` (`created_at`),
    INDEX `idx_tokens_used` (`tokens_used`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول سجل استخدام API';

-- ============================================
-- 11. جدول سجل الاستخدام اليومي (daily_usage_logs)
-- ============================================
DROP TABLE IF EXISTS `daily_usage_logs`;
CREATE TABLE `daily_usage_logs` (
    `id` INT(11) NOT NULL AUTO_INCREMENT COMMENT 'المعرف الفريد للسجل',
    `user_id` INT(11) NOT NULL COMMENT 'معرف المستخدم',
    `usage_type` VARCHAR(50) NOT NULL COMMENT 'نوع الاستخدام',
    `amount` INT(11) NOT NULL DEFAULT 1 COMMENT 'الكمية',
    `metadata` JSON DEFAULT NULL COMMENT 'بيانات إضافية',
    `usage_date` DATE NOT NULL COMMENT 'تاريخ الاستخدام',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'تاريخ الإنشاء',
    PRIMARY KEY (`id`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    INDEX `idx_user_id` (`user_id`),
    INDEX `idx_usage_type` (`usage_type`),
    INDEX `idx_usage_date` (`usage_date`),
    UNIQUE KEY `unique_daily_usage` (`user_id`, `usage_type`, `usage_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول سجل الاستخدام اليومي';

-- ============================================
-- 12. جدول الفواتير (invoices)
-- ============================================
DROP TABLE IF EXISTS `invoices`;
CREATE TABLE `invoices` (
    `id` INT(11) NOT NULL AUTO_INCREMENT COMMENT 'المعرف الفريد للفاتورة',
    `user_id` INT(11) NOT NULL COMMENT 'معرف المستخدم',
    `invoice_number` VARCHAR(50) NOT NULL UNIQUE COMMENT 'رقم الفاتورة',
    `plan_name` VARCHAR(50) NOT NULL COMMENT 'اسم الباقة',
    `plan_type` ENUM('monthly', 'yearly') NOT NULL COMMENT 'نوع الباقة',
    `amount` DECIMAL(10, 2) NOT NULL COMMENT 'المبلغ',
    `subtotal` DECIMAL(10,2) NULL DEFAULT NULL COMMENT 'المبلغ قبل الضريبة',
    `tax_country` CHAR(2) NULL DEFAULT NULL,
    `tax_type` VARCHAR(30) NULL DEFAULT NULL COMMENT 'VAT / GST / ... - NULL يعني Not Configured',
    `tax_amount` DECIMAL(10,2) NULL DEFAULT NULL COMMENT 'معلوماتي حاليًا - غير مضاف لمبلغ amount المخصوم فعليًا',
    `currency` VARCHAR(3) DEFAULT 'USD' COMMENT 'العملة',
    `status` ENUM('pending', 'paid', 'failed', 'cancelled') DEFAULT 'pending' COMMENT 'حالة الفاتورة',
    `payment_method` VARCHAR(50) DEFAULT NULL COMMENT 'طريقة الدفع',
    `transaction_id` VARCHAR(255) DEFAULT NULL COMMENT 'معرف المعاملة',
    `items` JSON DEFAULT NULL COMMENT 'بنود الفاتورة',
    `due_date` DATE NOT NULL COMMENT 'تاريخ الاستحقاق',
    `paid_at` TIMESTAMP NULL DEFAULT NULL COMMENT 'تاريخ الدفع',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'تاريخ الإنشاء',
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'تاريخ التحديث',
    PRIMARY KEY (`id`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    INDEX `idx_user_id` (`user_id`),
    INDEX `idx_status` (`status`),
    INDEX `idx_invoice_number` (`invoice_number`),
    INDEX `idx_created_at` (`created_at`),
    INDEX `idx_due_date` (`due_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول الفواتير';

-- ============================================
-- 13. جدول سجلات الموافقات (chat_approval_logs)
-- ============================================
DROP TABLE IF EXISTS `chat_approval_logs`;
CREATE TABLE `chat_approval_logs` (
    `id` INT(11) NOT NULL AUTO_INCREMENT COMMENT 'المعرف الفريد للسجل',
    `message_id` INT(11) NOT NULL COMMENT 'معرف الرسالة',
    `user_id` INT(11) NOT NULL COMMENT 'معرف المستخدم',
    `action` ENUM('approved', 'rejected') NOT NULL COMMENT 'الإجراء',
    `reason` TEXT DEFAULT NULL COMMENT 'السبب',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'تاريخ الإنشاء',
    PRIMARY KEY (`id`),
    FOREIGN KEY (`message_id`) REFERENCES `chat_messages`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    INDEX `idx_message_id` (`message_id`),
    INDEX `idx_user_id` (`user_id`),
    INDEX `idx_action` (`action`),
    INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول سجلات الموافقات';

-- ============================================
-- 14. جدول موافقات GDPR (gdpr_consents)
-- ============================================
DROP TABLE IF EXISTS `gdpr_consents`;
CREATE TABLE `gdpr_consents` (
    `id` INT(11) NOT NULL AUTO_INCREMENT COMMENT 'المعرف الفريد للسجل',
    `user_id` INT(11) NOT NULL COMMENT 'معرف المستخدم',
    `consent_type` VARCHAR(100) NOT NULL COMMENT 'نوع الموافقة',
    `consent_data` JSON DEFAULT NULL COMMENT 'بيانات الموافقة',
    `ip_address` VARCHAR(45) DEFAULT NULL COMMENT 'عنوان IP',
    `user_agent` TEXT DEFAULT NULL COMMENT 'متصفح المستخدم',
    `revoked_at` TIMESTAMP NULL DEFAULT NULL COMMENT 'تاريخ سحب الموافقة',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'تاريخ الإنشاء',
    PRIMARY KEY (`id`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    INDEX `idx_user_id` (`user_id`),
    INDEX `idx_consent_type` (`consent_type`),
    INDEX `idx_created_at` (`created_at`),
    INDEX `idx_revoked_at` (`revoked_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول موافقات GDPR';

-- ============================================
-- 15. جدول حظر معدل الطلبات (rate_limit_blocks)
-- ============================================
DROP TABLE IF EXISTS `rate_limit_blocks`;
CREATE TABLE `rate_limit_blocks` (
    `id` INT(11) NOT NULL AUTO_INCREMENT COMMENT 'المعرف الفريد للسجل',
    `identifier` VARCHAR(255) NOT NULL COMMENT 'المعرف (IP, API Key, إلخ)',
    `reason` TEXT DEFAULT NULL COMMENT 'سبب الحظر',
    `expires_at` TIMESTAMP NOT NULL COMMENT 'تاريخ الانتهاء',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'تاريخ الإنشاء',
    PRIMARY KEY (`id`),
    INDEX `idx_identifier` (`identifier`),
    INDEX `idx_expires_at` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول حظر معدل الطلبات';

-- ============================================
-- 16. جدول توكنات CSRF (csrf_tokens)
-- ============================================
DROP TABLE IF EXISTS `csrf_tokens`;
CREATE TABLE `csrf_tokens` (
    `id` INT(11) NOT NULL AUTO_INCREMENT COMMENT 'المعرف الفريد للسجل',
    `token` VARCHAR(255) NOT NULL COMMENT 'توكن CSRF',
    `expires_at` TIMESTAMP NOT NULL COMMENT 'تاريخ الانتهاء',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'تاريخ الإنشاء',
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_token` (`token`),
    INDEX `idx_expires_at` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول توكنات CSRF';

-- ============================================
-- 17. جدول طلبات حذف البيانات (gdpr_deletion_requests)
-- ============================================
DROP TABLE IF EXISTS `gdpr_deletion_requests`;
CREATE TABLE `gdpr_deletion_requests` (
    `id` INT(11) NOT NULL AUTO_INCREMENT COMMENT 'المعرف الفريد للسجل',
    `user_id` INT(11) NOT NULL COMMENT 'معرف المستخدم',
    `request_date` TIMESTAMP NOT NULL COMMENT 'تاريخ الطلب',
    `status` ENUM('pending', 'processing', 'completed', 'failed') DEFAULT 'pending' COMMENT 'حالة الطلب',
    `completed_date` TIMESTAMP NULL DEFAULT NULL COMMENT 'تاريخ الإكمال',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'تاريخ الإنشاء',
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'تاريخ التحديث',
    PRIMARY KEY (`id`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    INDEX `idx_user_id` (`user_id`),
    INDEX `idx_status` (`status`),
    INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول طلبات حذف البيانات (GDPR)';

-- ============================================
-- 18. جدول سجلات تصدير البيانات (gdpr_export_requests)
-- ============================================
DROP TABLE IF EXISTS `gdpr_export_requests`;
CREATE TABLE `gdpr_export_requests` (
    `id` INT(11) NOT NULL AUTO_INCREMENT COMMENT 'المعرف الفريد للسجل',
    `user_id` INT(11) NOT NULL COMMENT 'معرف المستخدم',
    `format` VARCHAR(20) NOT NULL COMMENT 'صيغة التصدير',
    `status` ENUM('pending', 'processing', 'completed', 'failed') DEFAULT 'pending' COMMENT 'حالة الطلب',
    `file_path` VARCHAR(500) DEFAULT NULL COMMENT 'مسار الملف',
    `file_size` INT(11) DEFAULT NULL COMMENT 'حجم الملف بالبايت',
    `completed_date` TIMESTAMP NULL DEFAULT NULL COMMENT 'تاريخ الإكمال',
    `expires_at` TIMESTAMP NULL DEFAULT NULL COMMENT 'تاريخ انتهاء صلاحية الملف',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'تاريخ الإنشاء',
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'تاريخ التحديث',
    PRIMARY KEY (`id`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    INDEX `idx_user_id` (`user_id`),
    INDEX `idx_status` (`status`),
    INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول طلبات تصدير البيانات (GDPR)';

-- ============================================
-- 19. جدول سجلات النشاط (activity_logs)
-- ============================================
DROP TABLE IF EXISTS `activity_logs`;
CREATE TABLE `activity_logs` (
    `id` INT(11) NOT NULL AUTO_INCREMENT COMMENT 'المعرف الفريد للسجل',
    `user_id` INT(11) DEFAULT NULL COMMENT 'معرف المستخدم',
    `session_id` VARCHAR(255) DEFAULT NULL COMMENT 'معرف الجلسة',
    `ip_address` VARCHAR(45) DEFAULT NULL COMMENT 'عنوان IP',
    `user_agent` TEXT DEFAULT NULL COMMENT 'متصفح المستخدم',
    `event_type` VARCHAR(100) NOT NULL COMMENT 'نوع الحدث',
    `event_data` JSON DEFAULT NULL COMMENT 'بيانات الحدث',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'تاريخ الإنشاء',
    PRIMARY KEY (`id`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    INDEX `idx_user_id` (`user_id`),
    INDEX `idx_event_type` (`event_type`),
    INDEX `idx_created_at` (`created_at`),
    INDEX `idx_session_id` (`session_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول سجلات النشاط';

-- ============================================
-- 19ب. جداول التتبع (Login History / Visitor Logs / Impersonation)
-- ============================================

-- ============================================
-- Tourfecto - Migration: Create Tracking Tables
-- جداول تتبع الزوار وسجل تسجيل الدخول والانتحال الإداري (Impersonation)
-- @version 1.0.0
-- @author Tourfecto Team
-- @copyright 2026 Tourfecto
-- ============================================

-- ============================================
-- 1. جدول سجل تسجيل الدخول (login_history)
-- يسجّل كل محاولة دخول ناجحة/فاشلة لكل حساب، مع الموقع الجغرافي ونوع الجهاز
-- ============================================
DROP TABLE IF EXISTS `login_history`;
CREATE TABLE `login_history` (
    `id` INT(11) NOT NULL AUTO_INCREMENT COMMENT 'المعرف الفريد للسجل',
    `user_id` INT(11) DEFAULT NULL COMMENT 'معرف المستخدم (NULL لو المحاولة فشلت قبل تحديد المستخدم)',
    `email_attempted` VARCHAR(255) DEFAULT NULL COMMENT 'البريد المستخدم في محاولة الدخول',
    `status` ENUM('success', 'failed') NOT NULL DEFAULT 'success' COMMENT 'نتيجة محاولة الدخول',
    `ip_address` VARCHAR(45) DEFAULT NULL COMMENT 'عنوان IP',
    `user_agent` TEXT DEFAULT NULL COMMENT 'الـ User Agent الخام',
    `device_type` VARCHAR(50) DEFAULT NULL COMMENT 'desktop / mobile / tablet / bot',
    `browser` VARCHAR(100) DEFAULT NULL COMMENT 'اسم المتصفح',
    `platform` VARCHAR(100) DEFAULT NULL COMMENT 'نظام التشغيل',
    `country` VARCHAR(100) DEFAULT NULL COMMENT 'الدولة (Geo IP)',
    `city` VARCHAR(100) DEFAULT NULL COMMENT 'المدينة (Geo IP)',
    `region` VARCHAR(100) DEFAULT NULL COMMENT 'المنطقة/المحافظة (Geo IP)',
    `latitude` DECIMAL(10,6) DEFAULT NULL COMMENT 'خط العرض',
    `longitude` DECIMAL(10,6) DEFAULT NULL COMMENT 'خط الطول',
    `session_id` VARCHAR(255) DEFAULT NULL COMMENT 'معرف الجلسة',
    `is_impersonation` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'هل الدخول كان عبر انتحال الأدمن',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'تاريخ ووقت المحاولة',
    PRIMARY KEY (`id`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    INDEX `idx_user_id` (`user_id`),
    INDEX `idx_status` (`status`),
    INDEX `idx_created_at` (`created_at`),
    INDEX `idx_ip_address` (`ip_address`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='سجل تسجيل الدخول لكل حساب';

-- ============================================
-- 2. جدول سجلات انتحال الأدمن لحسابات العملاء (impersonation_logs)
-- كل مرة الأدمن يدخل بحساب عميل لأغراض الدعم الفني، لازم يتسجل هنا
-- ============================================
DROP TABLE IF EXISTS `impersonation_logs`;
CREATE TABLE `impersonation_logs` (
    `id` INT(11) NOT NULL AUTO_INCREMENT COMMENT 'المعرف الفريد للسجل',
    `admin_id` INT(11) NOT NULL COMMENT 'معرف الأدمن اللي بدأ الجلسة',
    `target_user_id` INT(11) NOT NULL COMMENT 'معرف العميل المستهدف',
    `ip_address` VARCHAR(45) DEFAULT NULL COMMENT 'عنوان IP الخاص بالأدمن',
    `reason` VARCHAR(255) DEFAULT NULL COMMENT 'سبب الدخول (اختياري، لأغراض التوثيق)',
    `started_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'وقت بدء الانتحال',
    `ended_at` TIMESTAMP NULL DEFAULT NULL COMMENT 'وقت انتهاء الانتحال',
    PRIMARY KEY (`id`),
    FOREIGN KEY (`admin_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`target_user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    INDEX `idx_admin_id` (`admin_id`),
    INDEX `idx_target_user_id` (`target_user_id`),
    INDEX `idx_started_at` (`started_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='سجل انتحال الأدمن لحسابات العملاء';

-- ============================================
-- 3. جدول تتبع الزوار (visitor_logs)
-- يسجّل زيارات الموقع التسويقي العام + تصفح العملاء داخل المنصة بعد الدخول
-- ============================================
DROP TABLE IF EXISTS `visitor_logs`;
CREATE TABLE `visitor_logs` (
    `id` INT(11) NOT NULL AUTO_INCREMENT COMMENT 'المعرف الفريد للزيارة',
    `visitor_id` VARCHAR(64) NOT NULL COMMENT 'معرف الزائر الثابت (كوكي)، بيربط زيارات نفس المتصفح ببعض',
    `user_id` INT(11) DEFAULT NULL COMMENT 'معرف المستخدم لو كان مسجل دخول وقت الزيارة',
    `session_id` VARCHAR(255) DEFAULT NULL COMMENT 'معرف جلسة PHP وقت الزيارة',
    `page_url` VARCHAR(500) NOT NULL COMMENT 'المسار اللي اتزار',
    `referrer` VARCHAR(500) DEFAULT NULL COMMENT 'المصدر (Referrer)',
    `ip_address` VARCHAR(45) DEFAULT NULL COMMENT 'عنوان IP',
    `user_agent` TEXT DEFAULT NULL COMMENT 'الـ User Agent الخام',
    `device_type` VARCHAR(50) DEFAULT NULL COMMENT 'desktop / mobile / tablet / bot',
    `browser` VARCHAR(100) DEFAULT NULL COMMENT 'اسم المتصفح',
    `platform` VARCHAR(100) DEFAULT NULL COMMENT 'نظام التشغيل',
    `country` VARCHAR(100) DEFAULT NULL COMMENT 'الدولة (Geo IP)',
    `city` VARCHAR(100) DEFAULT NULL COMMENT 'المدينة (Geo IP)',
    `is_authenticated` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'هل الزائر كان مسجل دخول',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'تاريخ ووقت الزيارة',
    PRIMARY KEY (`id`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    INDEX `idx_visitor_id` (`visitor_id`),
    INDEX `idx_user_id` (`user_id`),
    INDEX `idx_created_at` (`created_at`),
    INDEX `idx_page_url` (`page_url`(191)),
    INDEX `idx_is_authenticated` (`is_authenticated`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='سجل تتبع الزوار (الموقع التسويقي + داخل المنصة)';

-- ============================================
-- 20. تفعيل القيود الخارجية
-- ============================================
SET FOREIGN_KEY_CHECKS = 1;

-- ============================================
-- 21. إنشاء مستخدمين للتحليل
-- ============================================
-- يمكن إضافة مستخدمين افتراضيين هنا للاختبار

-- ============================================
-- 22. إنشاء إجراءات مخزنة (Stored Procedures)
-- ============================================

-- 22.1 إجراء لتنظيف البيانات القديمة
DELIMITER //
CREATE PROCEDURE `clean_old_data`()
BEGIN
    -- حذف سجلات API القديمة (أكثر من 90 يوم)
    DELETE FROM `api_usage_logs` WHERE `created_at` < DATE_SUB(NOW(), INTERVAL 90 DAY);
    
    -- حذف سجلات الاستخدام اليومي القديمة (أكثر من 365 يوم)
    DELETE FROM `daily_usage_logs` WHERE `usage_date` < DATE_SUB(CURDATE(), INTERVAL 365 DAY);
    
    -- حذف توكنات CSRF المنتهية
    DELETE FROM `csrf_tokens` WHERE `expires_at` < NOW();
    
    -- حذف سجلات الحظر المنتهية
    DELETE FROM `rate_limit_blocks` WHERE `expires_at` < NOW();
    
    -- حذف سجلات الموافقات القديمة (أكثر من 30 يوم)
    DELETE FROM `chat_approval_logs` WHERE `created_at` < DATE_SUB(NOW(), INTERVAL 30 DAY);
    
    -- حذف سجلات النشاط القديمة (أكثر من 30 يوم)
    DELETE FROM `activity_logs` WHERE `created_at` < DATE_SUB(NOW(), INTERVAL 30 DAY);
END//
DELIMITER ;

-- 22.2 إجراء لتحديث إحصائيات المستخدمين
DELIMITER //
CREATE PROCEDURE `update_user_stats`()
BEGIN
    UPDATE `users` u
    SET 
        `last_activity` = (
            SELECT MAX(`created_at`) 
            FROM `activity_logs` 
            WHERE `user_id` = u.`id`
        )
    WHERE EXISTS (
        SELECT 1 
        FROM `activity_logs` 
        WHERE `user_id` = u.`id`
    );
END//
DELIMITER ;

-- ============================================
-- 23. إنشاء مشغلات (Triggers)
-- ============================================

-- 23.1 مشغل لتسجيل النشاط عند إنشاء مستخدم
DELIMITER //
CREATE TRIGGER `after_user_insert` 
AFTER INSERT ON `users` 
FOR EACH ROW
BEGIN
    INSERT INTO `activity_logs` (`user_id`, `event_type`, `event_data`)
    VALUES (NEW.`id`, 'user_created', JSON_OBJECT('email', NEW.`email`, 'company', NEW.`company_name`));
END//
DELIMITER ;

-- 23.2 مشغل لتسجيل النشاط عند تغيير حالة الاشتراك
DELIMITER //
CREATE TRIGGER `after_subscription_update` 
AFTER UPDATE ON `subscriptions` 
FOR EACH ROW
BEGIN
    IF OLD.`status` != NEW.`status` THEN
        INSERT INTO `activity_logs` (`user_id`, `event_type`, `event_data`)
        VALUES (
            NEW.`user_id`, 
            'subscription_status_changed',
            JSON_OBJECT('old_status', OLD.`status`, 'new_status', NEW.`status`, 'plan', NEW.`plan_name`)
        );
    END IF;
END//
DELIMITER ;

-- ============================================
-- 24. ملاحظات نهائية
-- ============================================
-- 1. جميع الجداول تستخدم utf8mb4_unicode_ci لدعم جميع اللغات
-- 2. تم إضافة المفاتيح الخارجية لضمان سلامة البيانات
-- 3. تم إضافة الفهارس لتحسين الأداء
-- 4. تم إضافة إجراءات ومشغلات لأتمتة المهام
-- 5. تم دعم الحذف الناعم (Soft Delete) في الجداول الرئيسية
-- ============================================