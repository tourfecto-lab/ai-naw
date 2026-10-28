-- ============================================================
-- Tourfecto - Migration: حقول إضافية للملف الشخصي
-- (Display Name + Job Title + Bio) - جزء من تطوير
-- /profile/settings إلى Account & Workspace Settings Center.
-- لا تعديل على أي جدول تاني، ولا حذف لأي عمود موجود.
-- @version 1.0.0  @date 2026-08-09
-- ============================================================

ALTER TABLE `users`
    ADD COLUMN `display_name` VARCHAR(120) DEFAULT NULL COMMENT 'الاسم المعروض للمستخدم (اختياري، لو فاضي بيتحسب من first_name+last_name)' AFTER `last_name`,
    ADD COLUMN `job_title` VARCHAR(120) DEFAULT NULL COMMENT 'المسمى الوظيفي' AFTER `company_name`,
    ADD COLUMN `bio` VARCHAR(500) DEFAULT NULL COMMENT 'نبذة مختصرة عن المستخدم (حد أقصى 500 حرف)' AFTER `job_title`;
