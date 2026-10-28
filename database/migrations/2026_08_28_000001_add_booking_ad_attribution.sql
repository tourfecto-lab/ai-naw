-- ============================================================
-- Tourfecto - Migration: Booking Ad Attribution (CAPI)
-- @version 1.0.0  @date 2026-08-28
--
-- ربط الحجوزات برابط UTM الإعلاني اللي اتعمله كليك قبل الحجز
-- (نافذة إسناد 30 يوم). العمود بيخزّن id من جدول ad_utm_links
-- فقط (مش أي بيانات شخصية)، وبيعتمد عليه:
--   1) حساب ROAS الحقيقي من الحجوزات المرتبطة.
--   2) إرسال حدث تحويل CAPI غير متزامن عند تأكيد الحجز.
--
-- Idempotent على MariaDB 10.11: ADD COLUMN/INDEX IF NOT EXISTS،
-- وقيود FK محمية بفحص information_schema (لا يوجد ADD CONSTRAINT
-- IF NOT EXISTS في MariaDB).
-- ============================================================

SET @__fx_col_1_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bookings' AND COLUMN_NAME = 'attributed_utm_link_id'
);
SET @__fx_col_1_sql := IF(@__fx_col_1_exists = 0,
    'ALTER TABLE `bookings` ADD COLUMN `attributed_utm_link_id` INT(11) NULL DEFAULT NULL
        COMMENT ''ad_utm_links.id لرابط الإعلان اللي اتعمله كليك قبل الحجز (نافذة 30 يوم)''
        AFTER `source`',
    'SELECT 1');
PREPARE __fx_col_1_stmt FROM @__fx_col_1_sql;
EXECUTE __fx_col_1_stmt;
DEALLOCATE PREPARE __fx_col_1_stmt;

SET @__fx_idx_2_exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bookings' AND INDEX_NAME = 'idx_bookings_attributed_utm'
);
SET @__fx_idx_2_sql := IF(@__fx_idx_2_exists = 0,
    'ALTER TABLE `bookings` ADD INDEX `idx_bookings_attributed_utm` (`attributed_utm_link_id`)',
    'SELECT 1');
PREPARE __fx_idx_2_stmt FROM @__fx_idx_2_sql;
EXECUTE __fx_idx_2_stmt;
DEALLOCATE PREPARE __fx_idx_2_stmt;

SET @fk_exists := (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME = 'bookings'
      AND CONSTRAINT_NAME = 'fk_bookings_attributed_utm_link'
      AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);

SET @fk_sql := IF(@fk_exists = 0,
    'ALTER TABLE `bookings` ADD CONSTRAINT `fk_bookings_attributed_utm_link`
     FOREIGN KEY (`attributed_utm_link_id`) REFERENCES `ad_utm_links` (`id`) ON DELETE SET NULL',
    'SELECT 1');

PREPARE fk_stmt FROM @fk_sql;
EXECUTE fk_stmt;
DEALLOCATE PREPARE fk_stmt;
