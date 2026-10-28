-- ============================================================
-- Tourfecto Business Control Center - Migration: business_api_keys table
-- Phase 12: Business-scoped API Keys (منفصلة عن users.api_token الشخصية)
-- @version 1.0.0  @date 2026-08-15
--
-- إضافية بالكامل: CREATE TABLE IF NOT EXISTS فقط. لا DROP ولا تعديل على
-- أي جدول موجود.
--
-- التصميم:
--   - مفتاح لكل Business، مخزّن hash فقط (password_hash) + prefix للبحث
--     السريع - نفس نمط UserApiKey تمامًا.
--   - `scope` (read / write) يُتحقق منها في الكود مش DB ENUM (نفس قاعدة
--     التوسعة الثابتة في الموديول: إضافة scope جديد محتاجة سطر في الكود).
--   - `created_by_user_id`: من أنشأ المفتاح (owner/admin عادةً) - للتتبع.
--   - ON DELETE CASCADE من businesses: حذف الـBusiness يحذف مفاتيحه
--     تلقائيًا (مفاتيح بلا Business مفيش ليه معنى - نفس نمط locations).
-- ============================================================

CREATE TABLE IF NOT EXISTS `business_api_keys` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `business_id` INT(11) NOT NULL,
    `created_by_user_id` INT(11) DEFAULT NULL COMMENT 'من أنشأ المفتاح - NULL لو اتُحذف الحساب (المفتاح بيتبع الـBusiness مش المنشئ)',

    `name` VARCHAR(120) NOT NULL,
    `key_prefix` VARCHAR(24) NOT NULL COMMENT 'بادئة معروضة للتعرف على المفتاح دون كشف hash',
    `key_hash` VARCHAR(255) NOT NULL,

    -- read: قراءة فقط. write: قراءة + كتابة (يُتحقق منها في الكود)
    `scope` VARCHAR(20) NOT NULL DEFAULT 'read' COMMENT 'read, write - يُتحقق منها في الكود',

    `last_used_at` DATETIME DEFAULT NULL,
    `revoked_at` DATETIME DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    INDEX `idx_business_api_key_business` (`business_id`),
    INDEX `idx_business_api_key_prefix` (`key_prefix`),
    CONSTRAINT `fk_business_api_key_business` FOREIGN KEY (`business_id`) REFERENCES `businesses` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Business API Keys - مفاتيح برمجية مرتبطة بالـBusiness (Business Control Center)';
