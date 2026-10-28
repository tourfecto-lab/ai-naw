-- ============================================================
-- Tourfecto - Migration: SEO Strategy Agent (خطة 30/60/90 يوم)
-- جداول جديدة بالكامل - أول ذكر لها في السبيك الأصلية (§10)، مفيش أي
-- كود أو جدول موجود من قبل نبني عليه.
-- ============================================================

CREATE TABLE IF NOT EXISTS `seo_strategy_plans` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT NOT NULL,
    `website_id` BIGINT UNSIGNED NOT NULL,
    `summary` TEXT NULL COMMENT 'ملخص تنفيذي مختصر للخطة ككل',
    `based_on_seo_score` DECIMAL(5,1) NULL COMMENT 'SEO Score وقت توليد الخطة - لسياق تاريخي',
    `generated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_ssp_website` (`website_id`),
    INDEX `idx_ssp_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='خطط SEO الاستراتيجية (30/60/90 يوم) - Phase 14';

CREATE TABLE IF NOT EXISTS `seo_strategy_tasks` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `plan_id` BIGINT UNSIGNED NOT NULL,
    `phase` ENUM('30_days','60_days','90_days') NOT NULL,
    `week_label` VARCHAR(50) NULL COMMENT 'مثلاً "الأسبوع 1" - اختياري لتفصيل أدق داخل كل Phase',
    `title` VARCHAR(255) NOT NULL,
    `description` TEXT NULL,
    `priority` ENUM('high','medium','low') NOT NULL DEFAULT 'medium',
    `estimated_impact` ENUM('high','medium','low') NOT NULL DEFAULT 'medium',
    `difficulty` ENUM('easy','medium','hard') NOT NULL DEFAULT 'medium',
    `owner` VARCHAR(50) NULL COMMENT 'مين المفروض ينفذ (AI/العميل/الاتنين) - مش user_id، تصنيف نوع المنفذ',
    `status` ENUM('todo','in_progress','done') NOT NULL DEFAULT 'todo',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_sst_plan` (`plan_id`),
    CONSTRAINT `fk_sst_plan` FOREIGN KEY (`plan_id`) REFERENCES `seo_strategy_plans` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='مهام خطة SEO الاستراتيجية - كل مهمة مرتبطة بـ Phase (30/60/90) - Phase 14';
