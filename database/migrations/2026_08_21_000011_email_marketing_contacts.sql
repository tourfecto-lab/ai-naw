-- ============================================================
-- Tourfecto - Migration: Email Marketing - Contact Management
--
-- إدارة جهات الاتصال المتقدمة (منافس Brevo/Mailchimp):
--   - email_custom_fields           حقول مخصصة تعرّفها الحساب
--   - email_subscriber_custom_values قيم الحقول لكل مشترك
--   - email_tags                    وسوم لتصنيف المشتركين
--   - email_subscriber_tag          ربط مشترك بوسم (Many-to-Many)
--   - email_segments                شرائح محفوظة بظروف ديناميكية (JSON)
--   - email_suppressions            قائمة ممنوعين/ارتدادات/شكاوى على مستوى الحساب
--   - email_subscribers (توسعة)     عدادات تفاعل + درجة تفاعل + مصدر اشتراك
--
-- كل الجداول IF NOT EXISTS (idempotent). توسعات الأعمدة بتتطبّق مرة
-- واحدة؛ لو اتعاد تشغيلها بيفشل ALTER ومش بيكسر (نفس استراتيجية باقي
-- ميجريشنز المشروع - الاختبارات بتتجاهل خطأ الـ ALTER).
-- @version 1.1.0  @date 2026-08-21
-- ============================================================

CREATE TABLE IF NOT EXISTS `email_custom_fields` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `user_id` INT(11) NOT NULL COMMENT 'صاحب الحساب',
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

CREATE TABLE IF NOT EXISTS `email_subscriber_custom_values` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `subscriber_id` INT(11) NOT NULL,
    `field_id` INT(11) NOT NULL,
    `value` TEXT NULL DEFAULT NULL,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_email_scv_subscriber_field` (`subscriber_id`, `field_id`),
    KEY `idx_email_scv_field_id` (`field_id`),
    CONSTRAINT `fk_email_scv_subscriber` FOREIGN KEY (`subscriber_id`) REFERENCES `email_subscribers` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_email_scv_field` FOREIGN KEY (`field_id`) REFERENCES `email_custom_fields` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='قيم الحقول المخصصة للمشتركين';

CREATE TABLE IF NOT EXISTS `email_tags` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `user_id` INT(11) NOT NULL,
    `name` VARCHAR(100) NOT NULL,
    `color` VARCHAR(20) NULL DEFAULT NULL COMMENT 'لون الوسم (hex)',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_email_tags_user_name` (`user_id`, `name`),
    CONSTRAINT `fk_email_tags_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='وسوم تصنيف المشتركين';

CREATE TABLE IF NOT EXISTS `email_subscriber_tag` (
    `subscriber_id` INT(11) NOT NULL,
    `tag_id` INT(11) NOT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`subscriber_id`, `tag_id`),
    KEY `idx_email_st_tag_id` (`tag_id`),
    CONSTRAINT `fk_email_st_subscriber` FOREIGN KEY (`subscriber_id`) REFERENCES `email_subscribers` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_email_st_tag` FOREIGN KEY (`tag_id`) REFERENCES `email_tags` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='ربط المشترك بالوسوم';

CREATE TABLE IF NOT EXISTS `email_segments` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `user_id` INT(11) NOT NULL,
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

CREATE TABLE IF NOT EXISTS `email_suppressions` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `user_id` INT(11) NOT NULL,
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

-- توسعة email_subscribers بخصائص إدارة جهات الاتصال المتقدمة.
-- الأعمدة دي لو اتعاد تشغيل الـ migration على قاعدة محدّثة هتفشل
-- ALTER (Duplicate column) - متجاهَلة في bootstrap الاختبارات.
ALTER TABLE `email_subscribers`
    ADD COLUMN `complaint_count` INT(11) NOT NULL DEFAULT 0 AFTER `status`,
    ADD COLUMN `bounce_count` INT(11) NOT NULL DEFAULT 0 AFTER `complaint_count`,
    ADD COLUMN `engagement_score` INT(11) NOT NULL DEFAULT 0 COMMENT 'درجة تفاعل 0-100 تحسب من فتح/كليك',
    ADD COLUMN `optin_ip` VARCHAR(64) NULL DEFAULT NULL,
    ADD COLUMN `optin_at` TIMESTAMP NULL DEFAULT NULL,
    ADD COLUMN `language` VARCHAR(10) NULL DEFAULT NULL;
