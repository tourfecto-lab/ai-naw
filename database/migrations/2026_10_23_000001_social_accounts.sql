-- Tourfecto - السوشيال: صورة/اسم/متابعين كل صفحة مربوطة + اختيار الصفحات المستخدمة. آمن للتشغيل أكتر من مرة، جدول جديد بس.
CREATE TABLE IF NOT EXISTS `social_account_profiles` (
    `platform_connection_id` INT(11) NOT NULL,
    `avatar_url` VARCHAR(1000) DEFAULT NULL COMMENT 'صورة الصفحة (بتتحدث يوميًا - روابط Meta بتنتهي)',
    `username` VARCHAR(190) DEFAULT NULL,
    `display_name` VARCHAR(190) DEFAULT NULL,
    `followers` INT(11) DEFAULT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '0 = العميل وقّفها: مش بتظهر في النشر ومش بتتحسب في الباقة',
    `fetched_at` DATETIME DEFAULT NULL,
    `last_error` VARCHAR(300) DEFAULT NULL,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`platform_connection_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
