-- ============================================================
-- Tourfecto Business Control Center - Migration: business_audit_logs table
-- Phase 13-14: Centralized Business Audit Log
-- @version 1.0.0  @date 2026-08-15
--
-- إضافية بالكامل: CREATE TABLE IF NOT EXISTS فقط. لا DROP ولا تعديل على
-- أي جدول موجود.
--
-- التصميم:
--   - سجل موحّد لكل حدث على مستوى الـBusiness (تعديل بيانات، locations،
--     services، team، api keys...) - بيكمّل الـaudit_logs الموجود على
--     مستوى المستخدم (اللي بيتتبع أحداث الحساب/الأمان).
--   - `actor_user_id`: من نفّذ الحدث. CASCADE من users (لو اتحذف المستخدم
--     اتحذفت سجلاته) - بخلاف audit_logs على مستوى المستخدم اللي عمدًا
--     مفيهوش FK عشان "الحساب اتحذف" يفضل موجود. هنا السياق Business،
--     فالأحداث اللي نفذها مستخدم محذوف مش مفيدة.
--   - `action`: VARCHAR يُتحقق في الكود (مفيش ENUM - نفس قاعدة الموديول).
--   - `meta`: JSON للمتغيرات الإضافية (قبل/بعد، تفاصيل...).
--   - `ip_address`/`user_agent`: سياق أمني للحدث.
--   - ON DELETE CASCADE من businesses: حذف الـBusiness يحذف سجلاته
--     (مفيش معنى لسجل business مش موجود).
-- ============================================================

CREATE TABLE IF NOT EXISTS `business_audit_logs` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `business_id` INT(11) NOT NULL,
    `actor_user_id` INT(11) DEFAULT NULL COMMENT 'من نفّذ الحدث (NULL للأنظمة/الجداول)',
    `action` VARCHAR(80) NOT NULL COMMENT 'business_updated, location_created, member_invited, api_key_revoked...',
    `object_type` VARCHAR(50) DEFAULT NULL COMMENT 'business, location, service, member, api_key...',
    `object_id` VARCHAR(64) DEFAULT NULL,
    `result` VARCHAR(20) NOT NULL DEFAULT 'success' COMMENT 'success, failed',
    `meta` JSON DEFAULT NULL,
    `ip_address` VARCHAR(45) DEFAULT NULL,
    `user_agent` VARCHAR(255) DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    INDEX `idx_business_audit_business_time` (`business_id`, `created_at`),
    INDEX `idx_business_audit_action` (`action`),
    INDEX `idx_business_audit_actor` (`actor_user_id`),
    CONSTRAINT `fk_business_audit_business` FOREIGN KEY (`business_id`) REFERENCES `businesses` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_business_audit_actor` FOREIGN KEY (`actor_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Business Audit Log - سجل موحّد لأحداث الـBusiness (Business Control Center)';
