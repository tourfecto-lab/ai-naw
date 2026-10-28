-- ============================================================
-- Tourfecto - Migration: أعمدة activity_logs الموحّدة
-- @date 2026-10-28
--
-- المشكلة: الجدول بيتنشأ أولًا بالشكل القديم في 2026_01_01_000000_base_schema.sql
-- (event_type / event_data)، وبعدها 2026_07_14_000007b بتعمل
-- CREATE TABLE IF NOT EXISTS `activity_logs` بالشكل الموحّد (module / action / ...)،
-- فبتتخطّى لأن الجدول موجود، وأي قاعدة بتتبني من الهجرات بتطلع من غير الأعمدة اللي الكود
-- (وسجلات Creative Studio والـ Marketing Assistant) بيكتب فيها.
--
-- الهجرة دي إضافية وآمنة للتكرار (ADD COLUMN IF NOT EXISTS - صيغة MariaDB زي باقي الهجرات)،
-- ومالهاش أثر على جدول فيه الأعمدة دي فعلًا. التعريفات منقولة حرفيًا من 000007b.
-- ============================================================
ALTER TABLE `activity_logs` ADD COLUMN IF NOT EXISTS `agency_id` INT(11) DEFAULT NULL;
ALTER TABLE `activity_logs` ADD COLUMN IF NOT EXISTS `module` VARCHAR(50) NOT NULL DEFAULT 'system' COMMENT 'seo, social, creative_studio, white_label, marketing_assistant, billing, system';
ALTER TABLE `activity_logs` ADD COLUMN IF NOT EXISTS `action` VARCHAR(100) NOT NULL DEFAULT '' COMMENT 'article.published, post.scheduled, media.generated...';
ALTER TABLE `activity_logs` ADD COLUMN IF NOT EXISTS `subject_type` VARCHAR(100) DEFAULT NULL;
ALTER TABLE `activity_logs` ADD COLUMN IF NOT EXISTS `subject_id` INT(11) DEFAULT NULL;
ALTER TABLE `activity_logs` ADD COLUMN IF NOT EXISTS `meta` JSON DEFAULT NULL;
ALTER TABLE `activity_logs` ADD INDEX IF NOT EXISTS `idx_module` (`module`);
ALTER TABLE `activity_logs` ADD INDEX IF NOT EXISTS `idx_action` (`action`);
