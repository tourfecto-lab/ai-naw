-- ============================================================
-- Tourfecto - Migration: التحكم في الميزات من لوحة الأدمن
-- تفعيل/تعطيل أي ميزة (صفحة) للموقع كله، أو استثناء عميل معيّن بالذات
-- (يشوف ميزة موقوفة عن الكل، أو العكس - يتمنع من ميزة متاحة للكل).
-- @version 1.0.0  @date 2026-07-26
-- ============================================================

CREATE TABLE IF NOT EXISTS `feature_flags` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `feature_key` VARCHAR(50) NOT NULL COMMENT 'نفس مفتاح القائمة الجانبية (ai_analyze, chat, crm...)',
    `label` VARCHAR(150) NOT NULL,
    `is_enabled` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'مفعّلة للكل بشكل افتراضي',
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_feature_key` (`feature_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='تفعيل/تعطيل الميزات للموقع كله - قابل للتعديل من لوحة الأدمن';

CREATE TABLE IF NOT EXISTS `user_feature_overrides` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `user_id` INT(11) NOT NULL,
    `feature_key` VARCHAR(50) NOT NULL,
    `is_enabled` TINYINT(1) NOT NULL COMMENT '1 = يشوفها حتى لو موقوفة للكل، 0 = يتمنع منها حتى لو متاحة للكل',
    `note` VARCHAR(255) DEFAULT NULL COMMENT 'ملاحظة الأدمن ليه الاستثناء ده',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_user_feature` (`user_id`, `feature_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='استثناءات الميزات لعميل معيّن - أعلى أولوية من الإعداد العام';

-- تعبئة كل الميزات الحالية بحالة "مفعّلة" (مفيش تغيير فعلي، بس تسجيلهم كلهم)
INSERT INTO `feature_flags` (`feature_key`, `label`, `is_enabled`) VALUES
    ('ai_analyze', 'تحليل SEO/AEO/GEO', 1),
    ('ai_reports', 'تقارير AI', 1),
    ('ai_articles', 'المقالات التسويقية', 1),
    ('ai_competitors', 'تحليل المنافسين (AI)', 1),
    ('ai_keywords', 'الكلمات المفتاحية', 1),
    ('social', 'السوشيال ميديا', 1),
    ('ads', 'الإعلانات', 1),
    ('marketing_assistant', 'المساعد التسويقي', 1),
    ('creative_studio', 'Creative Studio', 1),
    ('crm', 'إدارة العملاء (CRM)', 1),
    ('chat', 'الشات', 1),
    ('reputation', 'المراجعات', 1),
    ('reputation_overview', 'نظرة عامة على السمعة', 1),
    ('reputation_stats', 'إحصائيات السمعة', 1),
    ('gbp_content', 'محتوى Google Business', 1),
    ('review_requests', 'طلب المراجعات', 1),
    ('revenue', 'ذكاء الإيرادات', 1),
    ('website_optimizer', 'محسّن الموقع', 1),
    ('competitor_monitoring', 'مراقبة المنافسين', 1),
    ('agency', 'الوكالة (White Label)', 1)
ON DUPLICATE KEY UPDATE `feature_key` = `feature_key`;
