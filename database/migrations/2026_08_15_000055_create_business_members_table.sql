-- ============================================================
-- Tourfecto Business Control Center - Migration: business_members table
-- Phase 10-11: Team Management + RBAC
-- @version 1.0.0  @date 2026-08-15
--
-- إضافية بالكامل: CREATE TABLE IF NOT EXISTS فقط. لا DROP ولا تعديل على
-- أي جدول موجود. جدول مستقل تمامًا - لا يمس `users` ولا `businesses`
-- الموجودين (المرجع الوحيد: `businesses.owner_user_id` هو المالك ويبقى
-- كما هو، والجدول ده بيضيف الأعضاء الإضافيين فقط).
--
-- نموذج العضوية:
--   - `owner` مش مخزّن هنا إطلاقًا - محدّد عبر `businesses.owner_user_id`
--     (مصدر الحقيقة الوحيد، مفيش نسختين ممكن تختلفوا).
--   - `admin` / `member` / `viewer` مخزّنين هنا كصفوف `status='active'`.
--   - الدعوات الغير مكتملة: صف `status='invited'` بـ `invited_email` +
--     `invite_token` (user_id = NULL لحد ما المدعو يقبل).
--
-- ليه `role` VARCHAR مش ENUM: نفس القاعدة الثابتة في باقي الموديول -
-- أي إضافة دور جديد (مثل accountant/reporting) محتاجة سطر في الكود بس،
-- مش Migration لتعديل ENUM (راجع نفس القرار في `businesses.business_type`).
-- ============================================================

CREATE TABLE IF NOT EXISTS `business_members` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,

    `business_id` INT(11) NOT NULL,
    `user_id` INT(11) DEFAULT NULL COMMENT 'NULL للدعوات غير المقبولة بعد',

    -- الأدوار: owner مش مخزّن هنا (بيتعرف من businesses.owner_user_id) -
    -- ده الجدول بيخزن admin/member/viewer بس، والأدوار بتتحقق في الكود
    -- (BusinessAccessService) مش على مستوى الداتابيز.
    `role` VARCHAR(20) NOT NULL DEFAULT 'viewer'
        COMMENT 'admin, member, viewer - يُتحقق منها في الكود',

    -- active: عضو فعلي. invited: دعوة معلقة (لسه ما قُبلتش).
    `status` VARCHAR(20) NOT NULL DEFAULT 'invited'
        COMMENT 'active, invited',

    -- معلومات الدعوة (بتفضل null للأعضاء المضافين مباشرة)
    `invited_by_user_id` INT(11) DEFAULT NULL,
    `invited_email` VARCHAR(255) DEFAULT NULL
        COMMENT 'بريد المدعو - إلزامي للدعوات الغير مسجلة (user_id NULL)',
    `invite_token` VARCHAR(64) DEFAULT NULL COMMENT 'توكن قبول الدعوة - فريد للدعوات المعلقة',
    `invite_expires_at` DATETIME DEFAULT NULL COMMENT 'صلاحية الدعوة (افتراضيًا 7 أيام)',

    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_business_user` (`business_id`, `user_id`)
        COMMENT 'منع التكرار: مستخدم واحد كعضو لنفس الـBusiness',
    UNIQUE KEY `uq_invite_token` (`invite_token`)
        COMMENT 'منع تصادم توكنات الدعوات - null مسموح (صفوف متعددة) في MySQL',
    INDEX `idx_member_user` (`user_id`),
    INDEX `idx_member_status` (`status`),
    CONSTRAINT `fk_member_business` FOREIGN KEY (`business_id`) REFERENCES `businesses` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Team Members + RBAC - أعضاء فريق الـBusiness وأدوارهم (Business Control Center)';
