-- ============================================================
-- Tourfecto Business Control Center - Migration: business_services
-- Phase 4: Business Services
-- @version 1.0.0  @date 2026-08-14
--
-- إضافية بالكامل: CREATE TABLE IF NOT EXISTS فقط.
--
-- category مخزّنة VARCHAR مش ENUM لنفس سبب business_type (Extensibility -
-- راجع businesses migration) - القائمة المقترحة موجودة في الكود
-- (BusinessService::suggestedCategories()) لكن مش قيد صارم على مستوى
-- الداتابيز، عشان "لا تربطها بشكل Hard-coded فقط" زي ما اتطلب صراحة.
--
-- slug: فريد على مستوى الـBusiness نفسه بس (UNIQUE مركّب مع business_id)
-- مش عالميًا - شركتين مختلفتين ممكن يستخدموا نفس الـslug "nile-cruises"
-- من غير تعارض، لأن كل واحدة سياقها منفصل تمامًا.
-- ============================================================

CREATE TABLE IF NOT EXISTS `business_services` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `business_id` INT(11) NOT NULL,

    `name` VARCHAR(255) NOT NULL,
    `slug` VARCHAR(255) NOT NULL,
    `description` TEXT DEFAULT NULL,
    `category` VARCHAR(100) DEFAULT NULL COMMENT 'قيمة حرة، قائمة مقترحة في الكود مش قيد DB',
    `active` TINYINT(1) NOT NULL DEFAULT 1,
    `target_markets` JSON DEFAULT NULL COMMENT 'مصفوفة ISO 3166-1 country codes',
    `target_languages` JSON DEFAULT NULL COMMENT 'مصفوفة ISO 639-1 language codes',

    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_business_service_slug` (`business_id`, `slug`),
    INDEX `idx_service_business` (`business_id`),
    INDEX `idx_service_active` (`business_id`, `active`),
    INDEX `idx_service_category` (`category`),
    CONSTRAINT `fk_service_business` FOREIGN KEY (`business_id`) REFERENCES `businesses` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='خدمات الشركة السياحية (Business Control Center - Phase 4)';
