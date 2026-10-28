-- ============================================================
-- Tourfecto Business Control Center - Migration: businesses table
-- Phase 2: فصل User Profile عن Business Profile
-- @version 1.0.0  @date 2026-08-14
--
-- إضافية بالكامل: CREATE TABLE IF NOT EXISTS + ALTER TABLE ADD COLUMN
-- فقط. لا DROP ولا تعديل على أي عمود موجود في `websites` أو `users`.
--
-- قرار تصميم مهم (موثّق هنا صراحة):
-- الأعمدة الحالية في `websites` (company_name, industry, target_language,
-- target_country, competitor_1/2/3_url) بتفترض فعليًا Website واحد =
-- شركة واحدة (كل Website له بياناته الخاصة، مش مجمّعين تحت كيان أكبر).
-- عشان كده العلاقة المبدئية هنا: business واحد لكل website (1:1)،
-- مش عدة websites تحت business واحد - ده الافتراض الآمن الوحيد اللي
-- منقدرش نخالفه من غير معرفة فعلية هل يوزرز عندهم أكتر من website
-- بيمثلوا نفس الشركة الحقيقية ولا لأ. الجدول والعلاقة مصممين بشكل يسمح
-- لاحقًا بربط أكتر من website بنفس business لو الحاجة دي تأكدت مستقبلًا،
-- من غير أي Migration هدّامة.
--
-- owner_user_id: المستخدم الأساسي/المالك. العلاقة many-to-many الحقيقية
-- لأعضاء الفريق (Phase 10 - Team Management) هتُبنى في جدول منفصل
-- business_members لاحقًا، مش هنا - businesses.owner_user_id مجرد مرجع
-- سريع لصاحب الحساب الأصلي، مش قائمة الأعضاء الكاملة.
-- ============================================================

CREATE TABLE IF NOT EXISTS `businesses` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `owner_user_id` INT(11) NOT NULL,

    -- Identity
    `legal_name` VARCHAR(255) DEFAULT NULL COMMENT 'الاسم القانوني/التجاري المسجل',
    `trade_name` VARCHAR(255) DEFAULT NULL COMMENT 'الاسم التجاري المستخدم فعليًا',
    `logo_url` VARCHAR(500) DEFAULT NULL,
    `description` TEXT DEFAULT NULL,

    -- Contact
    `website_url` VARCHAR(500) DEFAULT NULL,
    `business_email` VARCHAR(255) DEFAULT NULL,
    `business_phone` VARCHAR(50) DEFAULT NULL,
    `whatsapp_number` VARCHAR(50) DEFAULT NULL,

    -- Address (Primary/HQ - locations إضافية في جدول business_locations منفصل، Phase 3)
    `country_code` VARCHAR(2) DEFAULT NULL COMMENT 'ISO 3166-1 alpha-2',
    `city` VARCHAR(150) DEFAULT NULL,
    `address` VARCHAR(500) DEFAULT NULL,
    `postal_code` VARCHAR(20) DEFAULT NULL,

    -- Legal/Tourism specific
    `tourism_license_number` VARCHAR(100) DEFAULT NULL,
    `tax_number` VARCHAR(100) DEFAULT NULL,

    -- Business type: VARCHAR مش DB ENUM عمدًا (تعليمات صريحة بعدم استخدام
    -- ENUM لو المشروع محتاج Extensibility) - القيم المسموحة تتحقق في
    -- الكود (Validator) مش على مستوى الداتابيز، عشان إضافة نوع جديد
    -- (زي "Adventure Tourism" مستقبلًا) متطلبش Migration جديدة لتعديل ENUM.
    `business_type` VARCHAR(50) DEFAULT NULL COMMENT 'travel_agency, tour_operator, dmc, hotel, cruise, transportation, dmo, travel_consultant, other - يُتحقق منها في الكود',

    `year_established` SMALLINT DEFAULT NULL,
    `primary_language` VARCHAR(10) DEFAULT NULL COMMENT 'ISO 639-1',
    `supported_languages` JSON DEFAULT NULL COMMENT 'مصفوفة ISO 639-1 codes',
    `default_currency` VARCHAR(3) DEFAULT NULL COMMENT 'ISO 4217',
    `timezone` VARCHAR(64) DEFAULT NULL COMMENT 'IANA timezone',

    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    INDEX `idx_owner_user` (`owner_user_id`),
    INDEX `idx_business_type` (`business_type`),
    CONSTRAINT `fk_business_owner` FOREIGN KEY (`owner_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Business Profile - منفصل عن User Profile (Tourfecto Business Control Center)';

-- ربط websites بـbusinesses. Nullable و ON DELETE SET NULL عمدًا: حذف
-- Business (لو حصل مستقبلًا) لازم ميمسحش الـwebsite نفسه ولا بياناته
-- التحليلية المرتبطة بيه (last_analysis_at, competitor URLs...) - دول
-- بيانات منفصلة عن هوية الشركة نفسها.
ALTER TABLE `websites`
    ADD COLUMN `business_id` INT(11) DEFAULT NULL AFTER `user_id`,
    ADD INDEX `idx_website_business` (`business_id`),
    ADD CONSTRAINT `fk_website_business` FOREIGN KEY (`business_id`) REFERENCES `businesses` (`id`) ON DELETE SET NULL;
