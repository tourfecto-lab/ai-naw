-- ============================================================
-- Tourfecto - Migration: صلاحيات مفاتيح API (API Key Scopes)
-- Settings Center Competitive Upgrade - Phase 16A.
--
-- يضيف عمود `scopes` لمفاتيح المستخدم الشخصية بحيث كل مفتاح يقدر
-- يُقيَّد بمجموعة صلاحيات محددة (زي GitHub Fine-grained PAT):
-- `NULL` = وصول كامل لكل شيء (التوافق الخلفي مع المفاتيح القديمة)؛
-- قيمة JSON = قائمة الصلاحيات الفعلية للمفتاح.
-- @version 1.0.0  @date 2026-08-16
-- ============================================================

ALTER TABLE `user_api_keys`
    ADD COLUMN `scopes` TEXT NULL DEFAULT NULL COMMENT 'JSON: قائمة الصلاحيات (NULL = كل الصلاحيات)' AFTER `expires_at`;
