-- ============================================
-- Tourfecto - Migration: Create AI Articles Table
-- جدول المقالات المولّدة بالذكاء الاصطناعي (تسويق محتوى لشركات السياحة)
-- @version 1.0.0
-- ============================================

CREATE TABLE IF NOT EXISTS `ai_articles` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `user_id` INT(11) NOT NULL,
    `website_id` INT(11) DEFAULT NULL COMMENT 'الموقع المرتبط (اختياري)',
    `topic` VARCHAR(500) NOT NULL COMMENT 'الموضوع/الكلمة المفتاحية المطلوبة',
    `target_language` VARCHAR(10) NOT NULL DEFAULT 'ar',
    `tone` VARCHAR(50) DEFAULT 'professional' COMMENT 'أسلوب الكتابة',
    `title` VARCHAR(500) DEFAULT NULL,
    `meta_description` VARCHAR(500) DEFAULT NULL,
    `slug` VARCHAR(255) DEFAULT NULL,
    `content` LONGTEXT DEFAULT NULL COMMENT 'محتوى المقال بصيغة Markdown',
    `suggested_keywords` TEXT DEFAULT NULL COMMENT 'JSON array لكلمات مفتاحية مقترحة',
    `word_count` INT(11) DEFAULT 0,
    `status` ENUM('generating','completed','failed') NOT NULL DEFAULT 'generating',
    `error_message` TEXT DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_user_id` (`user_id`),
    INDEX `idx_website_id` (`website_id`),
    INDEX `idx_status` (`status`),
    INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='مقالات تسويقية مولّدة بالذكاء الاصطناعي';
