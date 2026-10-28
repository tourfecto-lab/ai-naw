-- ============================================================
-- Tourfecto - Migration: قواعد الضرائب (tax_rules)
--
-- جدول جديد بالكامل - بنية قابلة للتوسع لتحديد نسبة ضريبة حسب الدولة
-- ونوع الضريبة (VAT, GST, Sales Tax...). فاضي تمامًا وقت الإنشاء - لو
-- مفيش صف لدولة العميل، الضريبة = "Not Configured" (0 فعليًا، موضّح
-- بوضوح في الواجهة أنه مش مُفعّل، مش نسبة حقيقية مطبّقة).
--
-- مفيش أي نسبة افتراضية واحدة لكل العملاء (متطلب صريح: "لا تفترض نسبة
-- ضريبة واحدة لكل العملاء").
-- @version 1.0.0  @date 2026-08-12
-- ============================================================

CREATE TABLE IF NOT EXISTS `tax_rules` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `country_code` CHAR(2) NOT NULL COMMENT 'ISO 3166-1 alpha-2',
    `tax_type` VARCHAR(30) NOT NULL COMMENT 'VAT / GST / Sales Tax / ...',
    `tax_rate_percent` DECIMAL(5,2) NOT NULL COMMENT 'مثال: 14.00 يعني 14%',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_tax_rules_country_type` (`country_code`, `tax_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='قواعد الضريبة القابلة للتوسع حسب الدولة - فاضية افتراضيًا';
