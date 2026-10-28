-- ============================================
-- Tourfecto - Migration: Create Chat Messages Table
-- إنشاء جدول رسائل الشات
-- @version 1.0.0
-- @author Tourfecto Team
-- @copyright 2026 Tourfecto
-- ============================================

-- ============================================
-- 1. إنشاء جدول رسائل الشات
-- ============================================
CREATE TABLE IF NOT EXISTS `chat_messages` (
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
    INDEX `idx_is_auto_pilot` (`is_auto_pilot`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول رسائل الشات';

-- ============================================
-- 2. إضافة عمود conversation_started_at
-- ============================================
ALTER TABLE `chat_messages` 
ADD COLUMN `conversation_started_at` TIMESTAMP NULL DEFAULT NULL COMMENT 'تاريخ بدء المحادثة' 
AFTER `sent_at`;

-- ============================================
-- 3. إضافة عمود conversation_ended_at
-- ============================================
ALTER TABLE `chat_messages` 
ADD COLUMN `conversation_ended_at` TIMESTAMP NULL DEFAULT NULL COMMENT 'تاريخ انتهاء المحادثة' 
AFTER `conversation_started_at`;

-- ============================================
-- 4. إضافة عمود agent_responded
-- ============================================
ALTER TABLE `chat_messages` 
ADD COLUMN `agent_responded` TINYINT(1) DEFAULT 0 COMMENT 'تم الرد من قبل الوكيل' 
AFTER `conversation_ended_at`;

-- ============================================
-- 5. إضافة فهارس إضافية
-- ============================================
CREATE INDEX `idx_customer_phone` ON `chat_messages` (`customer_phone`);
CREATE INDEX `idx_bot_status_created` ON `chat_messages` (`bot_status`, `created_at`);