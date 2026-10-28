-- ============================================================
-- Tourfecto - Migration: إعدادات النظام العامة (مفاتيح API والربط)
-- بدل ما تبقى كل مفاتيح API (Gemini، Google، Meta، SMTP...) محبوسة في
-- ملف .env محتاج SSH أو File Manager تعدّلها، بقت قابلة للتعديل مباشرة
-- من لوحة الأدمن. القيم دي بتتخزّن مشفّرة، وبتتفحص أول حاجة قبل ما
-- الكود يرجع لقيمة .env كـ احتياط آمن (يعني الموقع مش هيتوقف لو الجدول
-- فاضي أو الإعداد ده لسه معمول تعديل عليه).
-- @version 1.0.0  @date 2026-07-26
-- ============================================================

CREATE TABLE IF NOT EXISTS `system_settings` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `setting_key` VARCHAR(80) NOT NULL,
    `setting_value` TEXT DEFAULT NULL COMMENT 'مشفّر لو is_secret = 1',
    `is_secret` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'لو 1، القيمة متشفّرة ومتعرضش كاملة في الواجهة',
    `category` VARCHAR(40) NOT NULL DEFAULT 'general' COMMENT 'ai, google, meta, whatsapp, mail, general',
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_setting_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='إعدادات النظام العامة (API keys وبيانات الربط) - قابلة للتعديل من لوحة الأدمن';
