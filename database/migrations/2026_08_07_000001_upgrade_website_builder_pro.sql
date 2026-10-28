-- ============================================================
-- Tourfecto - Migration: ترقية منشئ المواقع لنسخة احترافية
-- مكتبة تصميمات متعددة حسب المجال السياحي + لوحة تحكم مستقلة
-- لكل موقع + تقييمات وحجوزات (خطوة نحو تجربة زي TripAdvisor)
-- @version 2.0.0  @date 2026-08-07
-- ============================================================

-- 1) مكتبة التصميمات: كل مجال سياحي ليه أكتر من تصميم
CREATE TABLE IF NOT EXISTS `website_templates` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `niche_key` VARCHAR(40) NOT NULL COMMENT 'hotels, nile_cruises, desert_safari, diving, religious_tourism, travel_agency, city_tours, camping, boat_trips, car_rental, general',
    `name_ar` VARCHAR(120) NOT NULL,
    `name_en` VARCHAR(120) DEFAULT NULL,
    `description_ar` TEXT DEFAULT NULL,
    `layout_key` VARCHAR(60) NOT NULL COMMENT 'يطابق دالة العرض في الكود مثل render_hotel_classic, render_hotel_boutique',
    `preview_image` VARCHAR(255) DEFAULT NULL,
    `allowed_theme_colors` JSON DEFAULT NULL COMMENT '["gold","blue","green","red","teal","sand"]',
    `is_premium` TINYINT(1) NOT NULL DEFAULT 0,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `sort_order` INT(11) NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_niche` (`niche_key`),
    KEY `idx_active_sort` (`is_active`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='مكتبة تصميمات منشئ المواقع';

-- بذور أولية: مجالات سياحية شائعة، تصميمين على الأقل لكل مجال
INSERT INTO `website_templates` (`niche_key`, `name_ar`, `layout_key`, `is_premium`, `sort_order`) VALUES
('hotels', 'فندق كلاسيك', 'render_hotel_classic', 0, 1),
('hotels', 'فندق بوتيك فاخر', 'render_hotel_boutique', 1, 2),
('nile_cruises', 'رحلات نيلية تقليدية', 'render_nile_classic', 0, 1),
('nile_cruises', 'رحلات نيلية فاخرة', 'render_nile_luxury', 1, 2),
('desert_safari', 'سفاري صحراوي مغامرة', 'render_safari_adventure', 0, 1),
('desert_safari', 'سفاري صحراوي فاخر', 'render_safari_luxury', 1, 2),
('diving', 'غوص والبحر الأحمر', 'render_diving_classic', 0, 1),
('religious_tourism', 'سياحة دينية وعمرة', 'render_religious_classic', 0, 1),
('travel_agency', 'مكتب سياحة وسفر عام', 'render_agency_classic', 0, 1),
('travel_agency', 'وكالة سفر احترافية', 'render_agency_pro', 1, 2),
('city_tours', 'جولات سياحية داخل المدن', 'render_citytours_classic', 0, 1),
('camping', 'تخييم ورحلات برية', 'render_camping_classic', 0, 1),
('boat_trips', 'رحلات بحرية ويخوت', 'render_boat_classic', 0, 1),
('car_rental', 'تأجير سيارات سياحية', 'render_carrental_classic', 0, 1)
ON DUPLICATE KEY UPDATE `niche_key` = `niche_key`;

-- 2) ترقية جدول المواقع: يبقى مرتبط بتصميم من المكتبة + بيانات لوحة التحكم
ALTER TABLE `generated_websites`
    ADD COLUMN `template_id` INT(11) DEFAULT NULL AFTER `theme_color`,
    ADD COLUMN `niche_key` VARCHAR(40) DEFAULT NULL AFTER `template_id`,
    ADD COLUMN `logo_url` VARCHAR(255) DEFAULT NULL AFTER `content_json`,
    ADD COLUMN `favicon_url` VARCHAR(255) DEFAULT NULL AFTER `logo_url`,
    ADD COLUMN `seo_title` VARCHAR(160) DEFAULT NULL AFTER `favicon_url`,
    ADD COLUMN `seo_description` VARCHAR(320) DEFAULT NULL AFTER `seo_title`,
    ADD COLUMN `views_count` INT(11) NOT NULL DEFAULT 0 AFTER `seo_description`,
    ADD COLUMN `last_published_at` TIMESTAMP NULL DEFAULT NULL AFTER `views_count`,
    ADD KEY `idx_template_id` (`template_id`),
    ADD KEY `idx_niche_key` (`niche_key`);

-- 3) تقييمات الزوار (أساس تجربة زي TripAdvisor)
CREATE TABLE IF NOT EXISTS `website_reviews` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `website_id` INT(11) NOT NULL,
    `item_id` VARCHAR(80) DEFAULT NULL COMMENT 'رقم/مفتاح الرحلة أو الغرفة لو التقييم على عنصر معين',
    `visitor_name` VARCHAR(120) NOT NULL,
    `rating` TINYINT(1) NOT NULL COMMENT '1-5',
    `comment` TEXT DEFAULT NULL,
    `status` ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_website_id` (`website_id`),
    KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='تقييمات ومراجعات زوار المواقع المولّدة';

-- 4) حجوزات/استفسارات الزوار من الموقع المنشور
CREATE TABLE IF NOT EXISTS `website_leads` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `website_id` INT(11) NOT NULL,
    `item_id` VARCHAR(80) DEFAULT NULL,
    `visitor_name` VARCHAR(120) NOT NULL,
    `phone` VARCHAR(40) DEFAULT NULL,
    `email` VARCHAR(160) DEFAULT NULL,
    `message` TEXT DEFAULT NULL,
    `status` ENUM('new','contacted','closed') NOT NULL DEFAULT 'new',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_website_id` (`website_id`),
    KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='طلبات حجز واستفسارات زوار المواقع';

INSERT INTO `feature_flags` (`feature_key`, `label`, `is_enabled`) VALUES
    ('website_builder_templates', 'مكتبة تصميمات منشئ المواقع', 1),
    ('website_builder_dashboard', 'لوحة تحكم مستقلة لكل موقع', 1),
    ('website_builder_reviews', 'تقييمات الزوار على المواقع المولّدة', 1)
ON DUPLICATE KEY UPDATE `feature_key` = `feature_key`;
