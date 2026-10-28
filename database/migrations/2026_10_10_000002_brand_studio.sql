-- Tourfecto - Brand Studio (هوية العلامة + خطة المحتوى + التعلّم من التقييم) - آمن للتشغيل أكتر من مرة

CREATE TABLE IF NOT EXISTS `brand_profiles` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `user_id` INT(11) NOT NULL,
    `website_id` INT(11) DEFAULT NULL,
    `source_url` VARCHAR(500) NOT NULL,
    `brand_name` VARCHAR(255) DEFAULT NULL,
    `logo_path` VARCHAR(500) DEFAULT NULL COMMENT 'مسار اللوجو المحفوظ محليًا (/uploads/branding/...)',
    `palette_json` TEXT DEFAULT NULL COMMENT '{"primary","secondary","accent","background","text"}',
    `profile_json` LONGTEXT DEFAULT NULL COMMENT 'هوية العلامة: النشاط، الجمهور، النبرة، الأسلوب البصري، أعمدة المحتوى',
    `status` VARCHAR(20) NOT NULL DEFAULT 'ready',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_brand_user_url` (`user_id`, `source_url`(190)),
    INDEX `idx_brand_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `creative_plans` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `user_id` INT(11) NOT NULL,
    `brand_profile_id` INT(11) NOT NULL,
    `title` VARCHAR(255) DEFAULT NULL,
    `plan_json` LONGTEXT DEFAULT NULL COMMENT 'قائمة أفكار: المنصة، الفكرة، العنوان، الكابشن، وصف الصورة، النوع',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_plan_user` (`user_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- تتبّع كل صورة: الهوية المستخدمة، البرومبت النهائي، المزود، وتقييم العميل (للتعلّم)
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'media_items' AND COLUMN_NAME = 'brand_profile_id');
SET @q := IF(@c = 0, 'ALTER TABLE `media_items` ADD COLUMN `brand_profile_id` INT(11) NULL, ADD COLUMN `final_prompt` TEXT NULL, ADD COLUMN `provider` VARCHAR(20) NULL, ADD COLUMN `rating` TINYINT NULL COMMENT ''1 = حلوة, -1 = مش حلوة'', ADD COLUMN `feedback_note` VARCHAR(300) NULL, ADD COLUMN `headline` VARCHAR(200) NULL', 'SELECT 1');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

-- دروس مستخلصة من تقييمات العميل، بتتحقن في أي برومبت جديد لنفس الهوية
CREATE TABLE IF NOT EXISTS `brand_learnings` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `brand_profile_id` INT(11) NOT NULL,
    `kind` ENUM('do','avoid') NOT NULL,
    `lesson` VARCHAR(400) NOT NULL,
    `weight` INT(11) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_learn_brand` (`brand_profile_id`, `kind`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
