-- Tourfecto - باقات السوشيال: سجل تعديلات الأدمن، استخدام أكواد الخصم، وعلامة «الدورة دي بخصم».
-- آمن للتشغيل أكتر من مرة. مفيش تعديل على بيانات موجودة (أعمدة جديدة NULL بس).

CREATE TABLE IF NOT EXISTS `social_packages_audit` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `actor_id` INT(11) DEFAULT NULL COMMENT 'الأدمن اللي عدّل',
    `changes_json` MEDIUMTEXT NOT NULL COMMENT '{path: {old, new}}',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `social_promo_redemptions` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `promo_code` VARCHAR(24) NOT NULL,
    `user_id` INT(11) NOT NULL,
    `ref` VARCHAR(80) NOT NULL COMMENT 'نفس مفتاح الاشتراك (منع التكرار)',
    `discount` DECIMAL(10,2) NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_ref` (`ref`),
    KEY `idx_code` (`promo_code`),
    KEY `idx_user_code` (`user_id`, `promo_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'social_subscriptions' AND COLUMN_NAME = 'promo_code');
SET @q := IF(@has = 0, 'ALTER TABLE `social_subscriptions` ADD COLUMN `promo_code` VARCHAR(24) DEFAULT NULL', 'SELECT 1');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
