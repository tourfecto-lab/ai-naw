-- ============================================
-- Tourfecto - Migration: Create Reviews Table
-- إنشاء جدول المراجعات
-- @version 1.0.0
-- @author Tourfecto Team
-- @copyright 2026 Tourfecto
-- ============================================

-- ============================================
-- 1. إنشاء جدول المراجعات
-- ============================================
CREATE TABLE IF NOT EXISTS `reviews` (
    `id` INT(11) NOT NULL AUTO_INCREMENT COMMENT 'المعرف الفريد للمراجعة',
    `website_id` INT(11) NOT NULL COMMENT 'معرف الموقع',
    `user_id` INT(11) NOT NULL COMMENT 'معرف المستخدم',
    `platform` ENUM('tripadvisor', 'google_business', 'booking', 'expedia', 'trustpilot', 'other') NOT NULL COMMENT 'المنصة',
    `platform_review_id` VARCHAR(255) DEFAULT NULL COMMENT 'معرف المراجعة في المنصة',
    
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
    `sentiment_label` ENUM('positive', 'neutral', 'negative') DEFAULT NULL COMMENT 'نوع المشاعر',
    `sentiment_confidence` DECIMAL(3, 2) DEFAULT NULL COMMENT 'ثقة التحليل',
    
    -- الرد الآلي
    `auto_reply_generated` TEXT DEFAULT NULL COMMENT 'الرد المولد آلياً',
    `auto_reply_language` VARCHAR(10) DEFAULT 'en' COMMENT 'لغة الرد',
    `reply_sent` TINYINT(1) DEFAULT 0 COMMENT 'حالة إرسال الرد',
    `reply_sent_at` TIMESTAMP NULL DEFAULT NULL COMMENT 'تاريخ إرسال الرد',
    
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
    INDEX `idx_website_id` (`website_id`),
    INDEX `idx_user_id` (`user_id`),
    INDEX `idx_platform` (`platform`),
    INDEX `idx_sentiment` (`sentiment_label`),
    INDEX `idx_rating` (`rating`),
    INDEX `idx_created_at` (`created_at`),
    INDEX `idx_is_processed` (`is_processed`),
    UNIQUE KEY `unique_platform_review` (`platform`, `platform_review_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول المراجعات';

-- ============================================
-- 2. إضافة عمود reply_approved_by
-- ============================================
ALTER TABLE `reviews` 
ADD COLUMN `reply_approved_by` INT(11) DEFAULT NULL COMMENT 'معرف المستخدم الذي وافق على الرد' 
AFTER `reply_sent_at`;

-- ============================================
-- 3. إضافة عمود is_ai_generated
-- ============================================
ALTER TABLE `reviews` 
ADD COLUMN `is_ai_generated` TINYINT(1) DEFAULT 1 COMMENT 'الرد مولد بالذكاء الاصطناعي' 
AFTER `reply_approved_by`;

-- ============================================
-- 4. إضافة فهارس إضافية
-- ============================================
CREATE INDEX `idx_review_date` ON `reviews` (`review_date`);
CREATE INDEX `idx_reply_sent` ON `reviews` (`reply_sent`);