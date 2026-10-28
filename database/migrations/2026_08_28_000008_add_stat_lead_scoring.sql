-- ============================================================
-- Tourfecto - Migration: Statistical AI Lead Scoring (بند 6)
-- @version 1.0.0  @date 2026-08-28
--
-- طبقة إحصائية شفافة فوق التقييم Rule-based القائم (CrmLeadScoringService):
-- تحسب احتمال تحويل كل Lead من معدل التحويل التجريبي الحقيقي لمصدره
-- (من سجل الحساب نفسه - leads وصلت لقرار نهائي أو صفقة مغلقة)، مع فاصل
-- ثقة Wilson وحجم عينة، وتخزّن النتيجة في أعمدة جديدة Additive فقط
-- (لا تُعدّل score/priority/score_reason الخاصة بالتقييم القديم).
--
-- Idempotent على MariaDB 10.11 عبر ADD COLUMN IF NOT EXISTS.
-- ============================================================

SET @__fx_col_1_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'crm_leads' AND COLUMN_NAME = 'conv_probability'
);
SET @__fx_col_1_sql := IF(@__fx_col_1_exists = 0,
    'ALTER TABLE `crm_leads` ADD COLUMN `conv_probability` DECIMAL(5,4) NULL DEFAULT NULL
        COMMENT ''احتمال التحويل الإحصائي (0-1) من معدل تحويل مصدر الـLead في بيانات الحساب (بند 6)''
        AFTER `score_reason`',
    'SELECT 1');
PREPARE __fx_col_1_stmt FROM @__fx_col_1_sql;
EXECUTE __fx_col_1_stmt;
DEALLOCATE PREPARE __fx_col_1_stmt;

SET @__fx_col_1_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'crm_leads' AND COLUMN_NAME = 'score_confidence'
);
SET @__fx_col_1_sql := IF(@__fx_col_1_exists = 0,
    'ALTER TABLE `crm_leads` ADD COLUMN `score_confidence` ENUM(''low'',''moderate'',''high'') NULL DEFAULT NULL
        COMMENT ''ثقة النتيجة الإحصائية من حجم العينة (بند 6)''
        AFTER `conv_probability`',
    'SELECT 1');
PREPARE __fx_col_1_stmt FROM @__fx_col_1_sql;
EXECUTE __fx_col_1_stmt;
DEALLOCATE PREPARE __fx_col_1_stmt;

SET @__fx_col_1_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'crm_leads' AND COLUMN_NAME = 'score_signals_json'
);
SET @__fx_col_1_sql := IF(@__fx_col_1_exists = 0,
    'ALTER TABLE `crm_leads` ADD COLUMN `score_signals_json` JSON NULL DEFAULT NULL
        COMMENT ''إشارات إحصائية مخزنة فقط (عينة/تحويلات/فاصل Wilson) - ليست توصيات كحقيقة (بند 6)''
        AFTER `score_confidence`',
    'SELECT 1');
PREPARE __fx_col_1_stmt FROM @__fx_col_1_sql;
EXECUTE __fx_col_1_stmt;
DEALLOCATE PREPARE __fx_col_1_stmt;
