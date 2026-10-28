-- ============================================================
-- Tourfecto - Migration: Competitor Intelligence scoring (Phase 7)
-- إضافي بالكامل. بيسد الفجوة اللي الكود نفسه موثّقها من قبل: عمود
-- last_analyzed_at كان مفقود من جدول competitors أصلًا (تعليق موجود في
-- CompetitorAnalysisService.php يوضح كده).
-- ============================================================

SET @__fx_col_1_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'competitors' AND COLUMN_NAME = 'competitor_score'
);
SET @__fx_col_1_sql := IF(@__fx_col_1_exists = 0,
    'ALTER TABLE `competitors` ADD COLUMN `competitor_score` TINYINT UNSIGNED NULL DEFAULT NULL
        COMMENT ''درجة تقديرية 0-100 لقوة الحضور الرقمي للمنافس بناءً على آخر تحليل'' AFTER `is_active`',
    'SELECT 1');
PREPARE __fx_col_1_stmt FROM @__fx_col_1_sql;
EXECUTE __fx_col_1_stmt;
DEALLOCATE PREPARE __fx_col_1_stmt;

SET @__fx_col_2_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'competitors' AND COLUMN_NAME = 'my_score'
);
SET @__fx_col_2_sql := IF(@__fx_col_2_exists = 0,
    'ALTER TABLE `competitors` ADD COLUMN `my_score` TINYINT UNSIGNED NULL DEFAULT NULL
        COMMENT ''نفس الدرجة لموقعي وقت نفس التحليل - للمقارنة المباشرة'' AFTER `competitor_score`',
    'SELECT 1');
PREPARE __fx_col_2_stmt FROM @__fx_col_2_sql;
EXECUTE __fx_col_2_stmt;
DEALLOCATE PREPARE __fx_col_2_stmt;

SET @__fx_col_3_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'competitors' AND COLUMN_NAME = 'last_analyzed_at'
);
SET @__fx_col_3_sql := IF(@__fx_col_3_exists = 0,
    'ALTER TABLE `competitors` ADD COLUMN `last_analyzed_at` TIMESTAMP NULL DEFAULT NULL
        COMMENT ''آخر مرة اتعمل فيها تحليل فعلي للمنافس ده'' AFTER `my_score`',
    'SELECT 1');
PREPARE __fx_col_3_stmt FROM @__fx_col_3_sql;
EXECUTE __fx_col_3_stmt;
DEALLOCATE PREPARE __fx_col_3_stmt;
