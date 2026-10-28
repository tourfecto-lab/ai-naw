-- ============================================================
-- Tourfecto Business Control Center - Migration: business_target_markets
-- Phase 5: Target Markets
-- @version 1.0.0  @date 2026-08-14
--
-- إضافية بالكامل: CREATE TABLE IF NOT EXISTS فقط.
--
-- قرار تصميم: صف واحد بس لكل Business (1:1)، مش جدول متعدد الصفوف زي
-- Locations/Services - البيانات دي إعدادات مستوى الشركة كلها (مين
-- بتستهدف، مش عناصر منفصلة لها دورة حياة خاصة بيها). UNIQUE على
-- business_id يفرض الـ1:1 على مستوى الداتابيز نفسها.
--
-- customer_type مخزّن VARCHAR (b2b/b2c/both) - قائمة صغيرة ومستقرة
-- فعليًا (خلاف business_type/category)، لكن لسه مش DB ENUM عمدًا لنفس
-- سياسة الـExtensibility المتبعة في كل الجداول دي.
--
-- الجدول ده هو الـSingle Source of Truth للـTarget Markets، وPhase 6
-- (AI Business Context) هيقرأ منه مباشرة مش هيكرر نفس البيانات - نفس
-- التعليمات الصريحة في الطلب الأصلي.
-- ============================================================

CREATE TABLE IF NOT EXISTS `business_target_markets` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `business_id` INT(11) NOT NULL,

    `target_countries` JSON DEFAULT NULL COMMENT 'مصفوفة ISO 3166-1 alpha-2 country codes',
    `target_cities` JSON DEFAULT NULL COMMENT 'مصفوفة نصوص حرة (مدن كبرى مستهدفة)',
    `target_languages` JSON DEFAULT NULL COMMENT 'مصفوفة ISO 639-1 language codes',
    `customer_type` VARCHAR(10) DEFAULT NULL COMMENT 'b2b, b2c, both',
    `customer_segments` JSON DEFAULT NULL COMMENT 'مصفوفة نصوص حرة (luxury travelers, backpackers...)',

    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_target_markets_business` (`business_id`),
    CONSTRAINT `fk_target_markets_business` FOREIGN KEY (`business_id`) REFERENCES `businesses` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='الأسواق المستهدفة للشركة - Single Source of Truth لـPhase 6 AI Context (Business Control Center - Phase 5)';
