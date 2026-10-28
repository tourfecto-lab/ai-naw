-- ============================================================
-- Tourfecto - Migration: دمج القيمة الجديدة من ai-google-business-hub
-- @version 1.0.0  @date 2026-07-14
--
-- ملاحظة: OAuth واستيراد المراجعات لـ Google Business موجودان بالفعل
-- وشغّالان في ReputationController.php + platform_connections. القيمة
-- الجديدة الوحيدة غير الموجودة أصلًا هي: توليد محتوى منشورات Google
-- Business Profile بالذكاء الاصطناعي وجدولة نشرها تلقائيًا - فهذا فقط
-- ما تضيفه هذه الهجرة (بدل نسخ gbh_business_profiles/gbh_clients/
-- gbh_connections/gbh_reviews المكررة بالكامل مع platform_connections
-- وreviews الموجودين).
-- ============================================================

CREATE TABLE IF NOT EXISTS `gbp_content` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `user_id` INT(11) NOT NULL,
    `website_id` INT(11) NOT NULL,
    `type` ENUM('update','offer','event','product') NOT NULL DEFAULT 'update',
    `prompt` TEXT DEFAULT NULL,
    `generated_text` TEXT DEFAULT NULL,
    `media_item_id` INT(11) DEFAULT NULL COMMENT 'صورة مرتبطة من Creative Studio (اختياري)',
    `status` ENUM('draft','ready','failed') NOT NULL DEFAULT 'draft',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`website_id`) REFERENCES `websites`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`media_item_id`) REFERENCES `media_items`(`id`) ON DELETE SET NULL,
    INDEX `idx_website_id` (`website_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='محتوى منشورات Google Business Profile المولّد بالذكاء الاصطناعي';

CREATE TABLE IF NOT EXISTS `gbp_scheduled_posts` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `gbp_content_id` INT(11) NOT NULL,
    `platform_connection_id` INT(11) NOT NULL COMMENT 'يشير لـ platform_connections.id (platform=google_business)',
    `scheduled_at` TIMESTAMP NOT NULL,
    `published_at` TIMESTAMP NULL DEFAULT NULL,
    `google_post_id` VARCHAR(255) DEFAULT NULL,
    `status` ENUM('pending','processing','published','failed','cancelled') NOT NULL DEFAULT 'pending',
    `attempts` TINYINT(3) UNSIGNED NOT NULL DEFAULT 0,
    `error_message` VARCHAR(500) DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    FOREIGN KEY (`gbp_content_id`) REFERENCES `gbp_content`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`platform_connection_id`) REFERENCES `platform_connections`(`id`) ON DELETE CASCADE,
    INDEX `idx_due` (`status`, `scheduled_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدولة نشر منشورات Google Business Profile';
