-- ============================================================
-- Tourfecto Business Control Center - Migration: business_locations
-- Phase 3: Business Locations
-- @version 1.0.0  @date 2026-08-14
--
-- إضافية بالكامل: CREATE TABLE IF NOT EXISTS فقط.
--
-- ON DELETE CASCADE من businesses عمدًا هنا (مختلف عن businesses<->websites
-- اللي كانت SET NULL): الـLocation معناها الوحيد مرتبط بالـBusiness نفسه -
-- مفيش سيناريو منطقي لـLocation "يتيمة" بلا Business، عكس Website اللي
-- ليها بيانات تحليلية مستقلة تستاهل تفضل موجودة.
-- ============================================================

CREATE TABLE IF NOT EXISTS `business_locations` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `business_id` INT(11) NOT NULL,

    `name` VARCHAR(255) DEFAULT NULL COMMENT 'اسم الفرع/الموقع (مثلاً: Cairo HQ, Luxor Branch)',
    `country_code` VARCHAR(2) DEFAULT NULL COMMENT 'ISO 3166-1 alpha-2',
    `city` VARCHAR(150) DEFAULT NULL,
    `address` VARCHAR(500) DEFAULT NULL,
    `postal_code` VARCHAR(20) DEFAULT NULL,
    `latitude` DECIMAL(10,7) DEFAULT NULL,
    `longitude` DECIMAL(10,7) DEFAULT NULL,
    `phone` VARCHAR(50) DEFAULT NULL,
    `email` VARCHAR(255) DEFAULT NULL,
    `is_primary` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'مقر رئيسي واحد بس لكل Business - يُفرض في الكود (Service layer) مش DB constraint',
    `opening_hours` JSON DEFAULT NULL COMMENT 'مصفوفة أيام/ساعات، اختياري بالكامل',

    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    INDEX `idx_location_business` (`business_id`),
    INDEX `idx_location_primary` (`business_id`, `is_primary`),
    CONSTRAINT `fk_location_business` FOREIGN KEY (`business_id`) REFERENCES `businesses` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='مواقع الشركة الفعلية (Business Control Center - Phase 3)';
