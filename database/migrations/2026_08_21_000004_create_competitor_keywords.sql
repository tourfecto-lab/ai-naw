-- ============================================================
-- Tourfecto - Migration: Competitor Keywords
-- @version 1.0.0  @date 2026-08-21
--
-- جدول جديد لتخزين الكلمات المفتاحية اللي المنافسين مترتبين عليها
-- (يُملأ عادة عن طريق أداة بحث خارجية / استيراد يدوي). بيُستخدم في
-- SeoStrategyService::fetchKeywordGaps() لحساب "فجوات الكلمات
-- المفتاحية" (كلمات المنافس اللي العميل لسه مترتبش عليها).
--
-- مبني على نفس نمط جدول `competitors` / `ci_snapshots` الموجودين
-- بالفعل (competitor_id FK + CASCADE). آمن على قاعدة بيانات حية.
-- ============================================================

CREATE TABLE IF NOT EXISTS `competitor_keywords` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `competitor_id` INT(11) NOT NULL,
    `keyword` VARCHAR(255) NOT NULL,
    `search_volume` INT(11) DEFAULT NULL,
    `difficulty` TINYINT(3) UNSIGNED DEFAULT NULL COMMENT '0-100',
    `source` VARCHAR(100) NOT NULL DEFAULT 'manual' COMMENT 'مصدر البيانات: manual / import / integration:{name}',
    `discovered_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_ck_competitor` (`competitor_id`),
    KEY `idx_ck_keyword` (`keyword`),
    FOREIGN KEY (`competitor_id`) REFERENCES `competitors`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='كلمات مفتاحية مرصودة للمنافسين (لاستخدامها في تحليل الفجوات)';
