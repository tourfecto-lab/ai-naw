-- ============================================================
-- Tourfecto Business Control Center - Migration: business_brand_settings
-- Phase 7: Brand Settings
-- @version 1.0.0  @date 2026-08-14
--
-- إضافية بالكامل: CREATE TABLE IF NOT EXISTS فقط.
--
-- قرار مهم (Single Source of Truth - نفس المبدأ من Phase 6): الطلب
-- الأصلي لـPhase 7 بيتضمن "logo" و"tone of voice" - الاتنين دول
-- **موجودين بالفعل** (`businesses.logo_url` من Phase 2،
-- `business_ai_context.brand_voice` و`preferred_tone` من Phase 6).
-- ما بنعملش أعمدة تانية بنفس المعنى - ده بالظبط النوع اللي التعليمات
-- الأصلية حذّرت منه صراحة ("لا تجعل كل Module يخزن نسخة مختلفة من
-- معلومات الشركة"). الجدول ده بيحتوي بس الحقول الجديدة فعليًا.
-- ============================================================

CREATE TABLE IF NOT EXISTS `business_brand_settings` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `business_id` INT(11) NOT NULL,

    `favicon_url` VARCHAR(500) DEFAULT NULL,
    `brand_colors` JSON DEFAULT NULL COMMENT '{"primary": "#RRGGBB", "secondary": "#RRGGBB", "accent": "#RRGGBB"}',
    `font_preference` VARCHAR(100) DEFAULT NULL,
    `writing_style` TEXT DEFAULT NULL COMMENT 'وصف حر لأسلوب الكتابة - ده اللي بيُستخدم لو brand_voice = custom (راجع BusinessAiContext::allowedBrandVoicePresets)',
    `preferred_terminology` JSON DEFAULT NULL COMMENT 'مصفوفة {term, use_instead} أو نصوص حرة - مصطلحات مفضّلة',
    `prohibited_terminology` JSON DEFAULT NULL COMMENT 'مصطلحات/كلمات ممنوع استخدامها - مختلفة عن business_ai_context.forbidden_claims (دي كلمات/أسلوب، مش ادعاءات تسويقية)',

    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_brand_settings_business` (`business_id`),
    CONSTRAINT `fk_brand_settings_business` FOREIGN KEY (`business_id`) REFERENCES `businesses` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='إعدادات العلامة التجارية - حقول جديدة فقط، بدون تكرار logo_url/brand_voice الموجودين (Business Control Center - Phase 7)';
