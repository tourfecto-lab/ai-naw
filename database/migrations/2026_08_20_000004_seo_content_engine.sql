-- ============================================================
-- Tourfecto - Migration: SEO Content Engine (Phase 24)
-- محرك محتوى SEO تلقائي: تحويل الفرص (كلمات مفتاحية/استعلامات GSC)
-- إلى مقالات مولّدة ومفهرسة ومختبَرة A/B، مع قياس CTR - حلقة مغلقة.
-- ============================================================
-- الجداول دي بتكمل (مش بتعدّل) ai_articles الموجود: الحملة (campaign)
-- بتربط مجموعة مواضيع، وكل item بيشاور لمقال مولّد في ai_articles
-- وبيمسك حالة خط الأنابيب: queued -> generated -> indexed -> testing.
-- ============================================================

CREATE TABLE IF NOT EXISTS `seo_content_campaigns` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `user_id` INT(11) NOT NULL,
    `website_id` INT(11) NOT NULL,
    `name` VARCHAR(255) NOT NULL,
    `topic_source` ENUM('manual','gsc','keywords','competitors') NOT NULL DEFAULT 'manual',
    `status` ENUM('draft','generating','ready','completed') NOT NULL DEFAULT 'draft',
    `total_items` INT(11) NOT NULL DEFAULT 0,
    `generated_items` INT(11) NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL,
    `updated_at` DATETIME NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_scc_website` (`website_id`),
    KEY `idx_scc_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='حملات محتوى SEO التلقائي - Phase 24';

CREATE TABLE IF NOT EXISTS `seo_content_items` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `campaign_id` INT(11) NOT NULL,
    `article_id` INT(11) NULL DEFAULT NULL COMMENT 'معرف المقال المولّد في ai_articles',
    `topic` VARCHAR(500) NOT NULL,
    `keyword` VARCHAR(255) NULL DEFAULT NULL,
    `status` ENUM('queued','generated','indexed','published','testing','failed') NOT NULL DEFAULT 'queued',
    `title` VARCHAR(500) NULL DEFAULT NULL,
    `slug` VARCHAR(255) NULL DEFAULT NULL,
    `published_url` VARCHAR(500) NULL DEFAULT NULL,
    `ab_test_id` INT(11) NULL DEFAULT NULL,
    `indexnow_code` SMALLINT NULL DEFAULT NULL COMMENT 'كود استجابة IndexNow (202=نجاح)',
    `error_message` TEXT NULL DEFAULT NULL,
    `created_at` DATETIME NOT NULL,
    `updated_at` DATETIME NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_sci_campaign` (`campaign_id`),
    KEY `idx_sci_status` (`status`),
    KEY `idx_sci_article` (`article_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='عناصر حملة محتوى SEO (موضوع لكل عنصر) - Phase 24';
