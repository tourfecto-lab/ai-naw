-- ============================================================
-- Tourfecto Business Control Center - Migration: business_ai_context
-- Phase 6: AI Business Context - Single Source of Truth
-- @version 1.0.0  @date 2026-08-14
--
-- إضافية بالكامل: CREATE TABLE IF NOT EXISTS فقط.
--
-- 1:1 مع businesses زي business_target_markets. البيانات هنا مكمّلة
-- لـbusiness_target_markets مش مكررة ليها - target_audience هنا وصف
-- نصي حر (للـAI Prompting)، بينما target_markets الجدول المنفصل فيه
-- البيانات المُهيكلة (country codes, languages) المستخدمة في الفلترة/
-- الاستعلامات الفعلية. الاثنين بيكملوا بعض مش بيتكرروا.
-- ============================================================

CREATE TABLE IF NOT EXISTS `business_ai_context` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `business_id` INT(11) NOT NULL,

    `business_summary` TEXT DEFAULT NULL COMMENT 'نبذة عامة قصيرة عن الشركة - يُستخدم كنقطة بداية لأي محتوى AI',
    `brand_description` TEXT DEFAULT NULL,
    `target_audience` TEXT DEFAULT NULL COMMENT 'وصف نصي حر (يكمّل business_target_markets المُهيكل، مش بديل له)',
    `unique_selling_points` JSON DEFAULT NULL COMMENT 'مصفوفة نصوص - إيه اللي بيميز الشركة',
    `brand_voice` VARCHAR(50) DEFAULT NULL COMMENT 'professional, friendly, luxury, adventure, family, corporate, custom - القائمة الكاملة في Phase 7 Brand Settings',
    `preferred_tone` VARCHAR(100) DEFAULT NULL,
    `forbidden_claims` JSON DEFAULT NULL COMMENT 'مصفوفة نصوص - ادعاءات ممنوع الـAI يكتبها (قانونية/تسويقية)',
    `preferred_keywords` JSON DEFAULT NULL COMMENT 'مصفوفة كلمات مفتاحية مفضّلة لـSEO',
    `business_goals` JSON DEFAULT NULL COMMENT 'مصفوفة نصوص',
    `seo_goals` JSON DEFAULT NULL COMMENT 'مصفوفة نصوص',
    `content_goals` JSON DEFAULT NULL COMMENT 'مصفوفة نصوص',
    `competitors` JSON DEFAULT NULL COMMENT 'مصفوفة {name, url} objects',
    `important_notes` TEXT DEFAULT NULL COMMENT 'أي حاجة تانية المستخدم عايز الـAI يعرفها ومفيهاش عمود مخصص',

    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_ai_context_business` (`business_id`),
    CONSTRAINT `fk_ai_context_business` FOREIGN KEY (`business_id`) REFERENCES `businesses` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='AI Business Context (Business Control Center - Phase 6) - المكوّن الاستراتيجي/الصوتي، يُجمّع مع باقي الجداول عبر BusinessContextService';
