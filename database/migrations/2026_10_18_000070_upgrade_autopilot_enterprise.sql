-- ============================================================
-- Tourfecto - Enterprise Autopilot & Alerts Schema Upgrade
-- @version 2.0.0
-- ============================================================

-- 1) توسيع حالات ad_pending_actions لتتطابق مع حالات التنفيذ الفعلي والأمان
ALTER TABLE `ad_pending_actions`
    MODIFY COLUMN `status` ENUM('pending','processing','approved','executed','rejected','failed','uncertain','expired') NOT NULL DEFAULT 'pending';

-- 2) إضافة عمود cooldown_hours في إعدادات Autopilot لمنع تدمير Learning Phase في Meta/Google
SET @__fx_cooldown_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ad_autopilot_settings' AND COLUMN_NAME = 'cooldown_hours'
);
SET @__fx_cooldown_sql := IF(@__fx_cooldown_exists = 0,
    'ALTER TABLE `ad_autopilot_settings` ADD COLUMN `cooldown_hours` INT(11) NOT NULL DEFAULT 24 COMMENT ''فترة الانتظار بالساعات بين كل تعديل ميزانية لنفس الحملة'' AFTER `max_changes_per_day`',
    'SELECT 1');
PREPARE __fx_cooldown_stmt FROM @__fx_cooldown_sql;
EXECUTE __fx_cooldown_stmt;
DEALLOCATE PREPARE __fx_cooldown_stmt;

-- 3) فهرس مركب لتسريع استعلامات الطيار الآلي والتنبيهات اليومية
SET @__idx_perf_camp_date := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ad_performance_reports' AND INDEX_NAME = 'idx_campaign_date_start'
);
SET @__idx_perf_sql := IF(@__idx_perf_camp_date = 0,
    'ALTER TABLE `ad_performance_reports` ADD INDEX `idx_campaign_date_start` (`campaign_id`, `date_start`)',
    'SELECT 1');
PREPARE __idx_perf_stmt FROM @__idx_perf_sql;
EXECUTE __idx_perf_stmt;
DEALLOCATE PREPARE __idx_perf_stmt;