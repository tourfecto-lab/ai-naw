-- ============================================================
-- Tourfecto - Website Optimizer Core Schema (Reconstruction)
-- @version 1.0.0  @date 2026-08-20
--
-- جداول المحرك الأساسي لتحسين محركات البحث (wo_*). كانت موجودة على
-- سيرفر الإنتاج لكن schema بتاعها كان ناقص من الريبو، فبنت "نسخة
-- مخلصة" منها عشان الريبو يبقى قابلاً للـ deploy من الصفر وتقدر
-- Phase 21 (Auto SEO) تشتغل عليها.
--
-- الأعمدة مبنية على الاستخدام الفعلي في الكود:
--   WebsiteOptimizerController + AutoSeoController + AutoSeoEmbedService
--
-- ملاحظة: بدون FOREIGN KEYS (نفس نمط auto_pilot_change_log الموجود)
-- لتجنب تعارض أنواع المفاتيح بين الجداول القديمة والجديدة.
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;

-- ============================================================
-- 1) جلسات التدقيق (Audit Sessions)
-- ============================================================
CREATE TABLE IF NOT EXISTS `wo_audits` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `website_id` INT(11) NOT NULL COMMENT 'مرجع websites.id',
    `generated_website_id` INT(11) DEFAULT NULL COMMENT 'مرجع generated_websites.id لو الموقع مبني بالـ Builder',
    `user_id` INT(11) NOT NULL COMMENT 'مرجع users.id',
    `status` VARCHAR(20) NOT NULL DEFAULT 'running' COMMENT 'running|completed|failed',
    `overall_score` DECIMAL(5,1) DEFAULT NULL COMMENT '0-100',
    `started_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `completed_at` TIMESTAMP NULL DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_wo_audits_website` (`website_id`),
    KEY `idx_wo_audits_user` (`user_id`),
    KEY `idx_wo_audits_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='جلسات تدقيق Website Optimizer';

-- ============================================================
-- 2) نتائج الفحص (Audit Findings)
-- ============================================================
CREATE TABLE IF NOT EXISTS `wo_audit_findings` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `audit_id` INT(11) NOT NULL,
    `category` VARCHAR(30) NOT NULL COMMENT 'seo|aeo|geo|speed|security|mobile|accessibility|availability|broken_links',
    `check_key` VARCHAR(100) DEFAULT NULL COMMENT 'title_tag/meta_description_missing/structured_data/llms_txt...',
    `title` VARCHAR(255) NOT NULL,
    `status` VARCHAR(20) NOT NULL DEFAULT 'info' COMMENT 'pass|fail|warn|info',
    `severity` VARCHAR(20) NOT NULL DEFAULT 'medium' COMMENT 'critical|high|medium|low|info',
    `message` TEXT DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_woaf_audit` (`audit_id`),
    KEY `idx_woaf_category` (`category`),
    KEY `idx_woaf_check_key` (`check_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='نتائج فحص Website Optimizer';

-- ============================================================
-- 3) الإصلاحات المقترحة (Fixes)
-- ============================================================
CREATE TABLE IF NOT EXISTS `wo_fixes` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `audit_id` INT(11) NOT NULL,
    `category` VARCHAR(30) NOT NULL,
    `title` VARCHAR(255) NOT NULL,
    `description` TEXT DEFAULT NULL,
    `fix_type` VARCHAR(30) DEFAULT NULL COMMENT 'meta|schema|html|robots|sitemap|content...',
    `code_snippet` TEXT DEFAULT NULL COMMENT 'الكود الجاهز للنسخ أو للحقن',
    `target_file` VARCHAR(255) DEFAULT NULL,
    `suggested_value` TEXT DEFAULT NULL,
    `check_key` VARCHAR(100) DEFAULT NULL,
    `status` VARCHAR(20) NOT NULL DEFAULT 'pending' COMMENT 'pending|applied|dismissed',
    `applied_at` TIMESTAMP NULL DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_wofix_audit` (`audit_id`),
    KEY `idx_wofix_category` (`category`),
    KEY `idx_wofix_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='الإصلاحات المقترحة من Website Optimizer';

-- ============================================================
-- 4) الروابط المكسورة (Broken Links)
-- ============================================================
CREATE TABLE IF NOT EXISTS `wo_broken_links` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `audit_id` INT(11) NOT NULL,
    `source_url` VARCHAR(500) NOT NULL,
    `target_url` VARCHAR(500) NOT NULL,
    `link_type` VARCHAR(30) DEFAULT NULL COMMENT 'internal|external|image|resource',
    `status_code` INT(11) DEFAULT NULL,
    `error` VARCHAR(500) DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_wobl_audit` (`audit_id`),
    KEY `idx_wobl_status` (`status_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='الروابط المكسورة المكتشفة في التدقيق';

SET FOREIGN_KEY_CHECKS = 1;
