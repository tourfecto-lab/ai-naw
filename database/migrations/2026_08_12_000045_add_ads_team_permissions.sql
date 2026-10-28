-- ============================================================
-- Tourfecto - Migration: Ads Team Permissions (البند 27 من طلب Ads
-- Frontend - Viewer/Manager/Admin)
-- @version 1.0.0  @date 2026-08-12
--
-- ملحوظة صراحة مهمة: فحصت المشروع بالكامل ولقيت **مفيش أي مفهوم "فريق/
-- Team Members" موجود أصلًا في أي مكان** - كل `user_id` هو حساب مستقل
-- ومالك وحيد لبياناته (حتى `agencies`/`agency_clients` الموجودين هما
-- علاقة "وكالة تدير حسابات عملاء منفصلين"، مش "أعضاء فريق بأدوار مختلفة
-- على نفس الحساب"). فبناء Viewer/Manager/Admin حقيقي معناه بناء المفهوم
-- ده من الصفر لأول مرة - مش مجرد تفعيل حاجة موجودة.
--
-- الجدول ده بيسمح لصاحب حساب إعلانات (owner_user_id) إنه يضيف مستخدمين
-- Tourfecto حقيقيين تانيين (member_user_id - لازم يكون عنده حساب Tourfecto
-- بالفعل، مفيش دعوة بإيميل لشخص مالوش حساب في هذا الإصدار) كأعضاء فريق
-- بصلاحية محدّدة على موديول الإعلانات بتاعه بس.
-- ============================================================

CREATE TABLE IF NOT EXISTS `ad_team_members` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `owner_user_id` INT(11) NOT NULL COMMENT 'صاحب حساب الإعلانات الأصلي',
    `member_user_id` INT(11) NOT NULL COMMENT 'العضو المُضاف - لازم يكون له حساب Tourfecto بالفعل',
    `role` ENUM('viewer','manager','admin') NOT NULL DEFAULT 'viewer' COMMENT 'viewer=عرض فقط، manager=إدارة الحملات، admin=إدارة كاملة شاملة الربط/الإعدادات',
    `invited_by_user_id` INT(11) NOT NULL,
    `status` ENUM('active','removed') NOT NULL DEFAULT 'active',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_owner_member` (`owner_user_id`, `member_user_id`),
    FOREIGN KEY (`owner_user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`member_user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    INDEX `idx_member_user_id` (`member_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='أعضاء فريق موديول الإعلانات - Viewer/Manager/Admin على حساب مالك واحد';
