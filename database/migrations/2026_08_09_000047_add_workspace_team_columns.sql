-- ============================================================
-- Tourfecto - Migration: بنية Workspace / Team الأساسية
-- (Settings Center - Phase 8)
--
-- قرار معماري مهم بعد نقاش طويل مع صاحب المشروع: مفيش جدول "workspace"
-- منفصل - كل حساب (users row) هو Workspace بحد ذاته. لما مستخدم يدعو
-- عضو فريق، بيتعمل له users row جديد بره النظام، بس بعلاقة owner_user_id
-- بتاعت صاحب الحساب الأصلي. ده قرار مختلف عن نظام agencies (White-Label)
-- الموجود بالفعل - ده لفريق واحد بيشتغل على نفس الحساب، مش عميل بيدير
-- عملاء. راجع CHANGELOG.md لتفاصيل هذا القرار وتبعاته.
--
-- ⚠️ لاحظ: العمود owner_user_id ده بيوصف "مين بيملك الحساب ده"، لكنه
-- لوحده مايخليش عضو الفريق يشوف بيانات CRM/مواقع/تقارير صاحب الحساب -
-- ده محتاج تعديلات إضافية في موديولات تانية (شوف CHANGELOG.md).
-- @version 1.0.0  @date 2026-08-09
-- ============================================================

ALTER TABLE `users`
    ADD COLUMN `owner_user_id` INT(11) NULL DEFAULT NULL COMMENT 'لو مش NULL: هذا الحساب عضو فريق تابع لصاحب الحساب ده (مش حساب مستقل بفوترة خاصة)' AFTER `id`,
    ADD COLUMN `workspace_role` ENUM('admin', 'manager', 'sales', 'support', 'viewer') NULL DEFAULT NULL COMMENT 'دور العضو داخل الـ Workspace - يُستخدم فقط لو owner_user_id مش NULL. منفصل تمامًا عن عمود role الأصلي (صلاحيات منصة Tourfecto الداخلية)' AFTER `owner_user_id`,
    ADD COLUMN `industry` VARCHAR(100) NULL DEFAULT NULL COMMENT 'صناعة/مجال النشاط - جزء من Workspace Settings' AFTER `company_name`,
    ADD COLUMN `workspace_logo_url` VARCHAR(255) NULL DEFAULT NULL COMMENT 'لوجو الـ Workspace (منفصل عن avatar_url الشخصي)' AFTER `industry`,
    ADD KEY `idx_owner_user_id` (`owner_user_id`);

-- FK بـ ON DELETE SET NULL عمدًا (مش CASCADE) - عشان لو صاحب حساب
-- حذف حسابه هو، أعضاء الفريق التابعين له ميتمسحوش تلقائيًا معاه
-- (نفس درس Phase 5 بالظبط - CASCADE غير المدروس خطر). بدل كده، بيرجعوا
-- حسابات مستقلة من غير Workspace (owner_user_id = NULL)، والتطبيق هو
-- اللي بيقرر بعد كده يعاملهم إزاي (شوف WorkspaceController.php).
ALTER TABLE `users`
    ADD CONSTRAINT `fk_users_owner_user_id` FOREIGN KEY (`owner_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;
