-- ============================================================
-- Tourfecto - Migration: دعم كامل لإدارة الحملة بعد النشر
-- (إيقاف/تشغيل، تعديل ميزانية، إلغاء) - محتاجين نحفظ محليًا معرّفات
-- فرعية (Ad Set على Meta، Campaign Budget resource على Google) عشان
-- نقدر نعدّل عليها بعد كده من غير ما نعيد إنشاء الحملة من الصفر.
-- @version 1.0.0  @date 2026-08-10
-- ============================================================

-- 🐛 باگ حقيقي: كان ترتيب الجملة هنا COMMENT بعد AFTER، وده syntax
-- غلط في MySQL (لازم COMMENT '...' يجي قبل AFTER col_name) - الميجريشن
-- دي كانت هتفشل بالكامل بـ"You have an error in your SQL syntax" على
-- أي سيرفر MySQL حقيقي. الترتيب الصحيح لتعريف العمود هو:
-- <type> ... [COMMENT '...'] [AFTER col_name].
SET @__fx_col_1_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ad_campaigns' AND COLUMN_NAME = 'external_adset_id'
);
SET @__fx_col_1_sql := IF(@__fx_col_1_exists = 0,
    'ALTER TABLE `ad_campaigns` ADD COLUMN `external_adset_id` VARCHAR(64) DEFAULT NULL COMMENT ''Meta Ad Set ID - لازم لتعديل الميزانية بعد النشر'' AFTER `external_campaign_id`',
    'SELECT 1');
PREPARE __fx_col_1_stmt FROM @__fx_col_1_sql;
EXECUTE __fx_col_1_stmt;
DEALLOCATE PREPARE __fx_col_1_stmt;

SET @__fx_col_2_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ad_campaigns' AND COLUMN_NAME = 'external_budget_resource'
);
SET @__fx_col_2_sql := IF(@__fx_col_2_exists = 0,
    'ALTER TABLE `ad_campaigns` ADD COLUMN `external_budget_resource` VARCHAR(255) DEFAULT NULL COMMENT ''Google Ads Campaign Budget resource name - لازم لتعديل الميزانية بعد النشر'' AFTER `external_adset_id`',
    'SELECT 1');
PREPARE __fx_col_2_stmt FROM @__fx_col_2_sql;
EXECUTE __fx_col_2_stmt;
DEALLOCATE PREPARE __fx_col_2_stmt;
