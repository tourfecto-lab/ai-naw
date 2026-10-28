-- ============================================================
-- Tourfecto - Migration: CRM Product Catalog + Deal Line Items
-- (المرحلة 13 - G3)
-- @version 1.0.0  @date 2026-08-15
--
-- كتالوج منتجات (إضافة) + سطور بنود للصفقات. كل المنافسين الكبار
-- (Pipedrive/Zoho/Freshsales) يسمحون بربط منتجات بالصفقات بحيث تُحسب
-- قيمة الصفقة = Σ (سعر × كمية). هذا الملف يضيف الجدولين كإضافة خالصة
-- بدون أي تعديل على جداول crm_deals أو استعلاماته - القيمة المعاد
-- حسابها تُكتب من CrmProductService إلى crm_deals.value بعد أي تغيير
-- في البنود (Additive - بند 40).
-- ============================================================

CREATE TABLE IF NOT EXISTS `crm_products` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `user_id` INT(11) NOT NULL COMMENT 'صاحب الحساب (Tenant) - عزل كامل',
    `name` VARCHAR(255) NOT NULL,
    `description` TEXT DEFAULT NULL,
    `sku` VARCHAR(100) DEFAULT NULL,
    `price` DECIMAL(14,2) NOT NULL DEFAULT 0,
    `currency` VARCHAR(10) NOT NULL DEFAULT 'USD',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_crm_products_user` (`user_id`),
    INDEX `idx_crm_products_name` (`user_id`, `name`),
    CONSTRAINT `fk_crm_products_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='كتالوج منتجات CRM - المرحلة 13 (G3)';

CREATE TABLE IF NOT EXISTS `crm_deal_items` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `user_id` INT(11) NOT NULL COMMENT 'صاحب الحساب (Tenant)',
    `deal_id` INT(11) NOT NULL,
    `product_id` INT(11) DEFAULT NULL COMMENT 'NULL لو بند حر (غير مرتبط بمنتج)',
    `product_name` VARCHAR(255) NOT NULL COMMENT 'لقطة اسم المنتج وقت الإضافة',
    `description` TEXT DEFAULT NULL,
    `unit_price` DECIMAL(14,2) NOT NULL DEFAULT 0,
    `quantity` DECIMAL(12,2) NOT NULL DEFAULT 1,
    `discount` DECIMAL(14,2) NOT NULL DEFAULT 0 COMMENT 'خصم نقدي على السطر',
    `line_total` DECIMAL(14,2) NOT NULL DEFAULT 0 COMMENT '(unit_price * quantity) - discount',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_crm_deal_items_deal` (`deal_id`),
    INDEX `idx_crm_deal_items_user` (`user_id`),
    CONSTRAINT `fk_crm_deal_items_deal` FOREIGN KEY (`deal_id`) REFERENCES `crm_deals` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_crm_deal_items_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_crm_deal_items_product` FOREIGN KEY (`product_id`) REFERENCES `crm_products` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='بنود الصفقات (Line Items) - المرحلة 13 (G3)';
