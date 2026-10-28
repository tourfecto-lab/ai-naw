-- ============================================
-- Tourfecto - Migration: Create AI Reports Table
-- إنشاء جدول تقارير الذكاء الاصطناعي
-- @version 1.0.0
-- @author Tourfecto Team
-- @copyright 2026 Tourfecto
-- ============================================

-- ============================================
-- 1. إنشاء جدول تقارير الذكاء الاصطناعي
-- ============================================
CREATE TABLE IF NOT EXISTS `ai_reports` (
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
-- 2. إضافة عمود execution_time
-- ============================================
ALTER TABLE `ai_reports` 
ADD COLUMN `execution_time` INT(11) DEFAULT 0 COMMENT 'وقت التنفيذ بالمللي ثانية' 
AFTER `cost_in_usd`;

-- ============================================
-- 3. إضافة عمود ai_model
-- ============================================
ALTER TABLE `ai_reports` 
ADD COLUMN `ai_model` VARCHAR(50) DEFAULT 'gemini-1.5-flash' COMMENT 'نموذج الذكاء الاصطناعي المستخدم' 
AFTER `execution_time`;