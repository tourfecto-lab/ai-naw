-- ============================================================
-- Tourfecto - Migration: سجل نشاط الحساب (Audit Log)
-- (Settings Center - Phase 7)
--
-- نطاق هذا الجدول: نشاط المستخدم على حسابه الشخصي هو نفسه (نفس
-- الأحداث اللي بقت حقيقية فعليًا بعد Phase 1-6: تعديل بروفايل،
-- تغيير باسورد، مفاتيح API، جلسات، تفضيلات إشعارات، إيقاف/حذف
-- حساب). ده مش Audit Log على مستوى Workspace/فريق كامل - ده لسه
-- معلّق على قرار الـ Workspace من Phase 1.
-- @version 1.0.0  @date 2026-08-09
-- ============================================================

CREATE TABLE IF NOT EXISTS `audit_logs` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `user_id` INT(11) NOT NULL COMMENT 'صاحب الحدث',

    `action` VARCHAR(60) NOT NULL COMMENT 'مثال: password_changed, api_key_created',
    `object_type` VARCHAR(60) DEFAULT NULL COMMENT 'مثال: api_key, session',
    `object_id` VARCHAR(60) DEFAULT NULL COMMENT 'معرف العنصر المتأثر إن وجد',
    `result` ENUM('success', 'failed') NOT NULL DEFAULT 'success',
    `meta` TEXT DEFAULT NULL COMMENT 'JSON - تفاصيل إضافية غير حساسة فقط (ممنوع تخزين كلمات مرور/مفاتيح)',

    `ip_address` VARCHAR(45) DEFAULT NULL,
    `user_agent` VARCHAR(255) DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    KEY `idx_user_id_created_at` (`user_id`, `created_at`),
    KEY `idx_action` (`action`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='سجل نشاط أمني/إعدادات لحساب المستخدم - للقراءة فقط من الواجهة، مفيش أي endpoint للتعديل أو الحذف';
