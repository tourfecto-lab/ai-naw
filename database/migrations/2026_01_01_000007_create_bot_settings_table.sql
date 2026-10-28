-- ============================================
-- Tourfecto - Migration: Create Bot Settings Table
-- إنشاء جدول إعدادات البوت
-- @version 1.0.0
-- @author Tourfecto Team
-- @copyright 2026 Tourfecto
-- ============================================

-- ============================================
-- 1. إنشاء جدول إعدادات البوت
-- ============================================
CREATE TABLE IF NOT EXISTS `bot_settings` (
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
    
    -- إعدادات الأمان
    `allowed_domains` JSON DEFAULT NULL COMMENT 'النطاقات المسموحة',
    `blocked_keywords` JSON DEFAULT NULL COMMENT 'الكلمات المحظورة',
    
    -- إعدادات الوقت
    `business_hours_start` TIME DEFAULT '09:00:00' COMMENT 'بداية ساعات العمل',
    `business_hours_end` TIME DEFAULT '18:00:00' COMMENT 'نهاية ساعات العمل',
    `timezone` VARCHAR(50) DEFAULT 'UTC' COMMENT 'المنطقة الزمنية',
    
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
-- 2. إضافة عمود auto_response_timeout
-- ============================================
ALTER TABLE `bot_settings` 
ADD COLUMN `auto_response_timeout` INT(11) DEFAULT 60 COMMENT 'مهلة الرد التلقائي بالثواني' 
AFTER `timezone`;

-- ============================================
-- 3. إضافة عمود max_conversation_length
-- ============================================
ALTER TABLE `bot_settings` 
ADD COLUMN `max_conversation_length` INT(11) DEFAULT 50 COMMENT 'الحد الأقصى لطول المحادثة' 
AFTER `auto_response_timeout`;

-- ============================================
-- 4. إضافة عمود webhook_secret
-- ============================================
ALTER TABLE `bot_settings` 
ADD COLUMN `webhook_secret` VARCHAR(255) DEFAULT NULL COMMENT 'سر Webhook للتأمين' 
AFTER `whatsapp_phone_number`;