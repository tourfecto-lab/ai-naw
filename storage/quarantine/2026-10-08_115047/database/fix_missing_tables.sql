-- ============================================
-- Tourfecto - إصلاح: إضافة جدول rate_limit_blocks الناقص فقط
-- ============================================
-- هذا الكود آمن للتشغيل على قاعدة البيانات الحالية (لا يحذف أي جدول أو بيانات)

CREATE TABLE IF NOT EXISTS `rate_limit_blocks` (
    `id` INT(11) NOT NULL AUTO_INCREMENT COMMENT 'المعرف الفريد للسجل',
    `identifier` VARCHAR(255) NOT NULL COMMENT 'المعرف (IP, API Key, إلخ)',
    `reason` TEXT DEFAULT NULL COMMENT 'سبب الحظر',
    `expires_at` TIMESTAMP NOT NULL COMMENT 'تاريخ الانتهاء',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'تاريخ الإنشاء',
    PRIMARY KEY (`id`),
    INDEX `idx_identifier` (`identifier`),
    INDEX `idx_expires_at` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول حظر معدل الطلبات';