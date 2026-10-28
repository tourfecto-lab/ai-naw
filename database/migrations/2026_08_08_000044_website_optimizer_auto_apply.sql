-- ============================================================
-- Tourfecto - Migration: ربط الـ Website Optimizer بمواقع الـ Website
-- Builder، عشان Auto-Apply حقيقي يبقى ممكن (Phase 5)
--
-- إضافي بالكامل (Additive) - مفيش أي عمود أو جدول أو بيانات اتحذفت.
-- ============================================================

SET @__fx_col_1_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'wo_audits' AND COLUMN_NAME = 'generated_website_id'
);
SET @__fx_col_1_sql := IF(@__fx_col_1_exists = 0,
    'ALTER TABLE `wo_audits` ADD COLUMN `generated_website_id` BIGINT UNSIGNED NULL DEFAULT NULL
        COMMENT ''لو الرابط اللي اتفحص بيتبع موقع اتعمل بالـ Website Builder بتاعنا - عشان Auto-Apply يبقى ممكن فعليًا (عندنا صلاحية كتابة)''
        AFTER `website_id`',
    'SELECT 1');
PREPARE __fx_col_1_stmt FROM @__fx_col_1_sql;
EXECUTE __fx_col_1_stmt;
DEALLOCATE PREPARE __fx_col_1_stmt;

SET @__fx_idx_2_exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'wo_audits' AND INDEX_NAME = 'idx_wo_audits_generated_website'
);
SET @__fx_idx_2_sql := IF(@__fx_idx_2_exists = 0,
    'ALTER TABLE `wo_audits` ADD INDEX `idx_wo_audits_generated_website` (`generated_website_id`)',
    'SELECT 1');
PREPARE __fx_idx_2_stmt FROM @__fx_idx_2_sql;
EXECUTE __fx_idx_2_stmt;
DEALLOCATE PREPARE __fx_idx_2_stmt;

SET @__fx_col_1_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'wo_fixes' AND COLUMN_NAME = 'applied_by'
);
SET @__fx_col_1_sql := IF(@__fx_col_1_exists = 0,
    'ALTER TABLE `wo_fixes` ADD COLUMN `applied_by` VARCHAR(20) NULL DEFAULT NULL
        COMMENT ''user = العميل علّم الإصلاح applied يدويًا، auto_pilot = النظام طبّقه تلقائيًا فعليًا''
        AFTER `status`',
    'SELECT 1');
PREPARE __fx_col_1_stmt FROM @__fx_col_1_sql;
EXECUTE __fx_col_1_stmt;
DEALLOCATE PREPARE __fx_col_1_stmt;

SET @__fx_col_2_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'wo_fixes' AND COLUMN_NAME = 'suggested_value'
);
SET @__fx_col_2_sql := IF(@__fx_col_2_exists = 0,
    'ALTER TABLE `wo_fixes` ADD COLUMN `suggested_value` TEXT NULL DEFAULT NULL
        COMMENT ''القيمة الجاهزة للتطبيق التلقائي (مثلاً نص الـ title/description المقترح) - Nullable، مبني بس لأنواع الإصلاحات القابلة للتطبيق الآلي''',
    'SELECT 1');
PREPARE __fx_col_2_stmt FROM @__fx_col_2_sql;
EXECUTE __fx_col_2_stmt;
DEALLOCATE PREPARE __fx_col_2_stmt;

SET @__fx_col_3_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'wo_fixes' AND COLUMN_NAME = 'check_key'
);
SET @__fx_col_3_sql := IF(@__fx_col_3_exists = 0,
    'ALTER TABLE `wo_fixes` ADD COLUMN `check_key` VARCHAR(50) NULL DEFAULT NULL
        COMMENT ''مفتاح نوع الإصلاح (title_tag/meta_description/...) - كان غير مخزّن أصلًا على wo_fixes (بس على wo_audit_findings)، لازم نعرفه هنا عشان Auto-Apply يعرف يتعامل مع أنهي عمود يكتب فيه''',
    'SELECT 1');
PREPARE __fx_col_3_stmt FROM @__fx_col_3_sql;
EXECUTE __fx_col_3_stmt;
DEALLOCATE PREPARE __fx_col_3_stmt;
