-- Tourfecto - باقات السوشيال ميديا: اشتراك سوشيال منفصل من المحفظة، إضافات 30 يوم، وعدّاد الاستخدام الشهري.
-- آمن للتشغيل أكتر من مرة، وكله جداول جديدة (مفيش تعديل على جداول موجودة).
-- الأسعار والحدود نفسها في system_settings (social_packages_config) وبتتعدل من /admin/plans-studio.

CREATE TABLE IF NOT EXISTS `social_subscriptions` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `user_id` INT(11) NOT NULL COMMENT 'صاحب الحساب',
    `tier` VARCHAR(16) NOT NULL COMMENT 'basic|pro|agency|custom',
    `cycle` VARCHAR(10) NOT NULL DEFAULT 'monthly' COMMENT 'monthly|yearly',
    `price_paid` DECIMAL(10,2) NOT NULL DEFAULT 0 COMMENT 'سعر الدورة الحالية بالدولار (لحساب فرق الترقية)',
    `status` VARCHAR(16) NOT NULL DEFAULT 'active' COMMENT 'active|expired',
    `auto_renew` TINYINT(1) NOT NULL DEFAULT 1,
    `pending_tier` VARCHAR(16) DEFAULT NULL COMMENT 'تخفيض/تغيير بيتطبق عند التجديد',
    `pending_cycle` VARCHAR(10) DEFAULT NULL,
    `period_start` DATETIME NOT NULL COMMENT 'UTC',
    `period_end` DATETIME NOT NULL COMMENT 'UTC',
    `last_ref` VARCHAR(80) DEFAULT NULL COMMENT 'مفتاح آخر عملية (منع التكرار)',
    `reminded_at` DATETIME DEFAULT NULL,
    `custom_json` TEXT DEFAULT NULL COMMENT 'مواصفات «فصّل باقتك» (tier = custom)',
    `pending_custom_json` TEXT DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_user` (`user_id`),
    KEY `idx_due` (`status`, `period_end`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- لو الجدول اتعمل من نسخة أقدم من غير أعمدة الباقة المفصّلة
SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'social_subscriptions' AND COLUMN_NAME = 'custom_json');
SET @q := IF(@has = 0, 'ALTER TABLE `social_subscriptions` ADD COLUMN `custom_json` TEXT DEFAULT NULL, ADD COLUMN `pending_custom_json` TEXT DEFAULT NULL', 'SELECT 1');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

CREATE TABLE IF NOT EXISTS `social_addons` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `user_id` INT(11) NOT NULL,
    `addon_key` VARCHAR(30) NOT NULL,
    `kind` VARCHAR(20) NOT NULL COMMENT 'accounts|posts|competitors|ai_captions',
    `qty` INT(11) NOT NULL,
    `price` DECIMAL(10,2) NOT NULL DEFAULT 0,
    `ref` VARCHAR(80) NOT NULL,
    `expires_at` DATETIME NOT NULL COMMENT 'UTC',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_ref` (`ref`),
    KEY `idx_user` (`user_id`, `expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `social_usage_monthly` (
    `user_id` INT(11) NOT NULL,
    `month_key` CHAR(7) NOT NULL COMMENT 'YYYY-MM (UTC)',
    `posts` INT(11) NOT NULL DEFAULT 0 COMMENT 'كل منصة = منشور',
    `ai_captions` INT(11) NOT NULL DEFAULT 0 COMMENT 'كابشن من حصة باقة السوشيال',
    `monthly_plans` INT(11) NOT NULL DEFAULT 0,
    PRIMARY KEY (`user_id`, `month_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
