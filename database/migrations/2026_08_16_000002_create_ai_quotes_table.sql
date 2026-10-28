-- ============================================
-- Tourfecto - AI Chat & Customer Communication Platform
-- Migration: In-Chat Quotes (بيع داخل الشات)
-- Created: 2026-08-16
--
-- ملاحظات:
--   1. هذا الملف إضافي بالكامل: لا يعدّل أي جدول أو بيانات موجودة.
--   2. شغّل هذا الملف مرة واحدة بعد نسخة احتياطية من قاعدة البيانات.
--   3. الفكرة: الموظف/الوكيل يقدر يبني "عرض سعر" (Quote) جوه المحادثة
--      (بنود + أسعار)، يبعته للعميل عبر قناته، ويتتبع قبوله/رفضه —
--      من غير ما يسيب السياق. نفس نمط عروض أسعار Intercom/Zendesk.
-- ============================================

SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS `ai_quotes` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `website_id` INT(11) NOT NULL,
    `conversation_id` INT(11) DEFAULT NULL,
    `lead_id` INT(11) DEFAULT NULL,
    `quote_number` VARCHAR(30) DEFAULT NULL COMMENT 'رقم مرجعي بشري للعرض',
    `customer_name` VARCHAR(255) DEFAULT NULL,
    `customer_phone` VARCHAR(50) DEFAULT NULL,
    `customer_email` VARCHAR(191) DEFAULT NULL,
    `channel` VARCHAR(30) DEFAULT NULL COMMENT 'القناة اللي اتُبعتها عليها',
    `items` JSON DEFAULT NULL COMMENT '[{name, qty, unit_price, line_total, notes}]',
    `subtotal` DECIMAL(12,2) DEFAULT 0,
    `discount` DECIMAL(12,2) DEFAULT 0,
    `total` DECIMAL(12,2) DEFAULT 0,
    `currency` VARCHAR(10) DEFAULT 'USD',
    `status` ENUM('draft','sent','accepted','declined','expired','cancelled') DEFAULT 'draft',
    `notes` TEXT DEFAULT NULL COMMENT 'ملاحظات داخلية للموظف',
    `customer_message` TEXT DEFAULT NULL COMMENT 'رسالة العرض اللي اتُبعتت للعميل',
    `sent_at` DATETIME DEFAULT NULL,
    `responded_at` DATETIME DEFAULT NULL,
    `created_by_user_id` INT(11) DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_ai_quotes_website` (`website_id`),
    KEY `idx_ai_quotes_conversation` (`conversation_id`),
    KEY `idx_ai_quotes_lead` (`lead_id`),
    KEY `idx_ai_quotes_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
