-- ============================================================
-- Tourfecto - Migration: Ad/Variant Performance Reporting (بند 3)
-- @version 1.0.0  @date 2026-08-28
--
-- إضافة عمود تاريخ الأداء `recorded_on` إلى ad_creative_variants عشان
-- تقارير الفترة (weekly/monthly) على مستوى الإعلان/الـ variant تقدر
-- تقطع البيانات الحقيقية لنافذة زمنية فعلية بدل عرض كل الأوقات.
--
-- Additive فقط: عمود NULL جديد + backfill بتاريخ الإنشاء للصفوف القديمة
-- (بيانات موجودة مش بتتغير). Idempotent على MariaDB 10.11 عبر
-- ADD COLUMN IF NOT EXISTS.
-- ============================================================

SET @__fx_col_1_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ad_creative_variants' AND COLUMN_NAME = 'recorded_on'
);
SET @__fx_col_1_sql := IF(@__fx_col_1_exists = 0,
    'ALTER TABLE `ad_creative_variants` ADD COLUMN `recorded_on` DATE NULL DEFAULT NULL
        COMMENT ''تاريخ الأداء - فترة التقارير على مستوى الإعلان/الـ variant (بند 3)''
        AFTER `is_control`',
    'SELECT 1');
PREPARE __fx_col_1_stmt FROM @__fx_col_1_sql;
EXECUTE __fx_col_1_stmt;
DEALLOCATE PREPARE __fx_col_1_stmt;

-- Backfill للصفوف القائمة: التاريخ = تاريخ الإنشاء (بيانات فعلية بلا اختراع)
UPDATE `ad_creative_variants` SET `recorded_on` = DATE(`created_at`) WHERE `recorded_on` IS NULL;

SET @__fx_idx_1_exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ad_creative_variants' AND INDEX_NAME = 'idx_variant_recorded_on'
);
SET @__fx_idx_1_sql := IF(@__fx_idx_1_exists = 0,
    'ALTER TABLE `ad_creative_variants` ADD INDEX `idx_variant_recorded_on` (`user_id`, `recorded_on`)',
    'SELECT 1');
PREPARE __fx_idx_1_stmt FROM @__fx_idx_1_sql;
EXECUTE __fx_idx_1_stmt;
DEALLOCATE PREPARE __fx_idx_1_stmt;
