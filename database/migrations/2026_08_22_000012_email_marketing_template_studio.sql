-- Tourfecto - Email Marketing Template Studio (المرحلة 2)
-- إضافة دعم معرض القوالب ومحرر البلوكات (drag-and-drop) والمشاركة
-- Idempotent: بتشغيل مرة واحدة فقط، والأعمدة بتتضاف لو مش موجودة.

SET NAMES utf8mb4;

-- 1) عمود blocks: JSON للبلوكات المرئية (محرر drag-and-drop)
SET @exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'email_templates'
      AND COLUMN_NAME = 'blocks'
);
SET @sql := IF(@exists = 0,
    'ALTER TABLE email_templates ADD COLUMN blocks MEDIUMTEXT NULL COMMENT ''JSON بلوكات المحرر المرئي'' AFTER html_body',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 2) عمود category: تصنيف القوالب (welcome/newsletter/promo/...)
SET @exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'email_templates'
      AND COLUMN_NAME = 'category'
);
SET @sql := IF(@exists = 0,
    'ALTER TABLE email_templates ADD COLUMN category VARCHAR(64) DEFAULT NULL AFTER name',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 3) عمود share_token: مشاركة القالب برابط عام
SET @exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'email_templates'
      AND COLUMN_NAME = 'share_token'
);
SET @sql := IF(@exists = 0,
    'ALTER TABLE email_templates ADD COLUMN share_token VARCHAR(64) DEFAULT NULL AFTER category',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 4) عمود is_system: قوالب النظام/المعرض (لا تُعدّل من قبل المستخدم مباشرة)
SET @exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'email_templates'
      AND COLUMN_NAME = 'is_system'
);
SET @sql := IF(@exists = 0,
    'ALTER TABLE email_templates ADD COLUMN is_system TINYINT(1) NOT NULL DEFAULT 0 AFTER share_token',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- فهرس على share_token للبحث السريع في روابط المشاركة
SET @exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'email_templates'
      AND INDEX_NAME = 'idx_email_templates_share_token'
);
SET @sql := IF(@exists = 0,
    'ALTER TABLE email_templates ADD INDEX idx_email_templates_share_token (share_token)',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
