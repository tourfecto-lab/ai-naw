-- ============================================
-- Tourfecto - Migration: GBP Automated Reply Rules
-- قواعد الرد التلقائي على المراجعات (نفس فكرة Birdeye BirdAI / Podium
-- Automation Rules): رد تلقائي على تقييمات معينة (5 نجوم مثلًا) أو مشاعر
-- معينة، وتصعيد السلبي للإنذار/الإشعار. جدول جديد بس.
-- @version 1.0.0
-- @date 2026-08-15 (Reputation Intelligence Tier 2)
-- ============================================

CREATE TABLE IF NOT EXISTS `gbp_reply_rules` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `website_id` INT(11) NOT NULL COMMENT 'الموقع اللي بتتنفذ عليه القاعدة',
    `user_id` INT(11) NOT NULL COMMENT 'مالك القاعدة',
    `name` VARCHAR(150) NOT NULL COMMENT 'اسم القاعدة (شوف الرد التلقائي على الـ 5 نجوم)',
    `trigger_type` ENUM('rating_range', 'sentiment') NOT NULL DEFAULT 'rating_range' COMMENT 'نوع الشرط',
    `rating_min` DECIMAL(2,1) DEFAULT NULL COMMENT 'الحد الأدنى للتقييم (لو rating_range)',
    `rating_max` DECIMAL(2,1) DEFAULT NULL COMMENT 'الحد الأقصى للتقييم (لو rating_range)',
    `sentiment_label` ENUM('positive', 'neutral', 'negative', 'mixed') DEFAULT NULL COMMENT 'المشاعر (لو sentiment)',
    `action` ENUM('auto_reply', 'notify', 'auto_reply_and_notify') NOT NULL DEFAULT 'auto_reply' COMMENT 'الإجراء',
    `reply_mode` ENUM('ai', 'custom') NOT NULL DEFAULT 'ai' COMMENT 'الرد AI أو نص مخصص',
    `custom_reply` TEXT DEFAULT NULL COMMENT 'نص الرد المخصص (لو reply_mode=custom)',
    `priority` INT(11) NOT NULL DEFAULT 100 COMMENT 'الأولوية (الأصغر أولًا)',
    `enabled` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_reply_rules_website` (`website_id`),
    KEY `idx_reply_rules_user` (`user_id`),
    KEY `idx_reply_rules_enabled` (`enabled`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='قواعد الرد التلقائي على مراجعات GBP';
