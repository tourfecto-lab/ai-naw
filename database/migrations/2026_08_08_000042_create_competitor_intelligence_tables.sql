-- ============================================================
-- Tourfecto - Migration: Competitor Intelligence Module
-- @version 1.0.0  @date 2026-08-08
--
-- يبني فوق جدول `competitors` الموجود بالفعل (BATCH3) بدون حذفه أو
-- تكراره، ويضيف الجداول الجديدة اللازمة لمحرك المراقبة/كشف التغييرات/
-- التنبيهات/الـ Scorecards/الـ Insights/التقارير. لا يمس أو يحذف أي
-- جدول قديم (cm_google_rankings / cm_pricing / cm_offers /
-- cm_content_updates / cm_alerts / competitor_recommendations) - تلك
-- تبقى شغالة كما هي لصفحة /competitor-monitoring القديمة (تتبع سعر/عرض
-- يدوي بسيط)، بينما هذه الجداول تخدم موديول "Competitor Intelligence"
-- الجديد الموحّد (اكتشاف + مراقبة تلقائية + كشف تغييرات + Benchmarking +
-- AI Insights + تنبيهات متقدمة + تقارير).
--
-- كل الجداول IF NOT EXISTS / ADD COLUMN غير هدّامة - آمنة على قاعدة
-- بيانات حية بها بيانات فعلية.
-- ============================================================

-- ------------------------------------------------------------
-- 1) توسيع جدول competitors الموجود بأعمدة اختيارية جديدة فقط
-- ------------------------------------------------------------
ALTER TABLE `competitors`
    ADD COLUMN `industry` VARCHAR(150) DEFAULT NULL COMMENT 'قطاع نشاط المنافس (لو معروف)' AFTER `competitor_tripadvisor_url`,
    ADD COLUMN `country` VARCHAR(100) DEFAULT NULL COMMENT 'دولة المنافس (لو معروفة)' AFTER `industry`,
    ADD COLUMN `market_segment` VARCHAR(150) DEFAULT NULL COMMENT 'شريحة السوق المستهدفة (لو معروفة)' AFTER `country`,
    ADD COLUMN `category` ENUM('direct','indirect','emerging','potential') NOT NULL DEFAULT 'direct' COMMENT 'تصنيف تنافسي' AFTER `market_segment`,
    ADD COLUMN `source` VARCHAR(100) NOT NULL DEFAULT 'manual' COMMENT 'مصدر الإضافة: manual / discovery' AFTER `category`,
    ADD COLUMN `discovery_confidence` ENUM('high','medium','low') DEFAULT NULL COMMENT 'ثقة الاكتشاف لو أُضيف عبر Discovery' AFTER `source`,
    ADD COLUMN `monitoring_frequency` ENUM('daily','weekly','custom') NOT NULL DEFAULT 'weekly' COMMENT 'تكرار المراقبة الآلية' AFTER `discovery_confidence`,
    ADD COLUMN `monitoring_interval_hours` INT UNSIGNED DEFAULT NULL COMMENT 'مستخدم فقط لو monitoring_frequency = custom' AFTER `monitoring_frequency`,
    ADD COLUMN `monitoring_paused` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'هل المراقبة الآلية متوقفة مؤقتًا' AFTER `monitoring_interval_hours`,
    ADD COLUMN `last_monitored_at` TIMESTAMP NULL DEFAULT NULL COMMENT 'آخر مرة نجحت فيها دورة مراقبة' AFTER `monitoring_paused`,
    ADD COLUMN `last_monitoring_error` VARCHAR(500) DEFAULT NULL COMMENT 'آخر رسالة فشل مراقبة (لو حصل)' AFTER `last_monitored_at`,
    ADD COLUMN `last_change_at` TIMESTAMP NULL DEFAULT NULL COMMENT 'آخر مرة اتسجل فيها تغيير فعلي' AFTER `last_monitoring_error`;

ALTER TABLE `competitors`
    ADD INDEX `idx_ci_user_category` (`user_id`, `category`),
    ADD INDEX `idx_ci_monitoring_due` (`monitoring_paused`, `last_monitored_at`);

-- ------------------------------------------------------------
-- 2) اكتشاف منافسين محتملين (Competitor Discovery) - مرشّحون قبل
--    موافقة المستخدم على إضافتهم فعليًا لجدول competitors.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ci_discovery_candidates` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT(11) NOT NULL,
    `website_id` INT(11) NOT NULL,
    `competitor_name` VARCHAR(255) NOT NULL,
    `website` VARCHAR(500) DEFAULT NULL,
    `industry` VARCHAR(150) DEFAULT NULL,
    `country` VARCHAR(100) DEFAULT NULL,
    `market_segment` VARCHAR(150) DEFAULT NULL,
    `source` VARCHAR(100) NOT NULL COMMENT 'مصدر الاكتشاف: مثال website_metadata / manual_hint / integration:{name}',
    `category` ENUM('direct','indirect','emerging','potential') NOT NULL DEFAULT 'potential',
    `confidence` ENUM('high','medium','low') NOT NULL DEFAULT 'low',
    `status` ENUM('pending','added','dismissed') NOT NULL DEFAULT 'pending',
    `discovered_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_ci_disc_user` (`user_id`, `status`),
    KEY `idx_ci_disc_website` (`website_id`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`website_id`) REFERENCES `websites`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='مرشحو اكتشاف منافسين قبل الإضافة الفعلية';

-- ------------------------------------------------------------
-- 3) لقطات (Snapshots) للصفحات العامة المهمة لكل منافس - أساس
--    Change Detection (مقارنة hash / نص مستخرج بين لقطتين متتاليتين).
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ci_snapshots` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `competitor_id` INT(11) NOT NULL,
    `page_type` ENUM('homepage','pricing','products','services','landing','blog','contact','offers') NOT NULL,
    `url` VARCHAR(1000) NOT NULL,
    `http_status` SMALLINT UNSIGNED DEFAULT NULL,
    `content_hash` CHAR(64) DEFAULT NULL COMMENT 'SHA-256 للنص المستخرج المُطبَّع (بعد إزالة الضوضاء)',
    `title` VARCHAR(500) DEFAULT NULL,
    `meta_description` VARCHAR(1000) DEFAULT NULL,
    `normalized_excerpt` MEDIUMTEXT DEFAULT NULL COMMENT 'نص مستخرج مُطبَّع محدود الطول لاستخدامه في المقارنة والعرض before/after',
    `structured_data_hash` CHAR(64) DEFAULT NULL COMMENT 'هاش لأي JSON-LD / structured data موجودة بالصفحة',
    `fetch_status` ENUM('ok','failed') NOT NULL DEFAULT 'ok',
    `fetch_error` VARCHAR(500) DEFAULT NULL COMMENT 'مهم: فشل الجلب لازم يتسجل هنا صراحة ولا يُعتبر أبدًا Nothing Changed',
    `captured_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_ci_snap_competitor_page_date` (`competitor_id`, `page_type`, `captured_at`),
    FOREIGN KEY (`competitor_id`) REFERENCES `competitors`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='لقطات دورية للصفحات العامة لكل منافس';

-- ------------------------------------------------------------
-- 4) التغييرات المكتشفة فعليًا (نتيجة مقارنة لقطتين)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ci_changes` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `competitor_id` INT(11) NOT NULL,
    `user_id` INT(11) NOT NULL,
    `page_type` ENUM('homepage','pricing','products','services','landing','blog','contact','offers') NOT NULL,
    `change_type` ENUM('new_page','removed_page','headline_change','offer_change','pricing_change','new_product','content_change','announcement','other') NOT NULL,
    `severity` ENUM('low','medium','high','critical') NOT NULL DEFAULT 'low',
    `previous_value` MEDIUMTEXT DEFAULT NULL,
    `new_value` MEDIUMTEXT DEFAULT NULL,
    `source_url` VARCHAR(1000) DEFAULT NULL,
    `confidence` ENUM('high','medium','low') NOT NULL DEFAULT 'medium',
    `snapshot_before_id` BIGINT UNSIGNED DEFAULT NULL,
    `snapshot_after_id` BIGINT UNSIGNED DEFAULT NULL,
    `detected_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_ci_change_competitor_date` (`competitor_id`, `detected_at`),
    KEY `idx_ci_change_user_date` (`user_id`, `detected_at`),
    KEY `idx_ci_change_severity` (`severity`),
    FOREIGN KEY (`competitor_id`) REFERENCES `competitors`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`snapshot_before_id`) REFERENCES `ci_snapshots`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`snapshot_after_id`) REFERENCES `ci_snapshots`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='سجل تاريخي دائم للتغييرات المكتشفة - لا يُحذف أو يُستبدل';

-- ------------------------------------------------------------
-- 5) قائمة المتابعة (Watchlist) - إعدادات مراقبة/تنبيه لكل منافس/مستخدم
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ci_watchlist` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT(11) NOT NULL,
    `competitor_id` INT(11) NOT NULL,
    `priority` ENUM('low','medium','high') NOT NULL DEFAULT 'medium',
    `alert_min_severity` ENUM('info','low','medium','high','critical') NOT NULL DEFAULT 'medium',
    `alert_channels` JSON DEFAULT NULL COMMENT 'مثال: ["dashboard","email"]',
    `is_paused` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_ci_watchlist_user_competitor` (`user_id`, `competitor_id`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`competitor_id`) REFERENCES `competitors`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='قائمة المنافسين المهمين لكل مستخدم مع قواعد التنبيه';

-- ------------------------------------------------------------
-- 6) تنبيهات الموديول الجديد (أغنى من cm_alerts القديم: severity/channel/مصدر)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ci_alerts` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT(11) NOT NULL,
    `competitor_id` INT(11) NOT NULL,
    `change_id` BIGINT UNSIGNED DEFAULT NULL,
    `type` VARCHAR(60) NOT NULL COMMENT 'مثال: new_service / pricing_change / new_landing_page / activity_spike',
    `severity` ENUM('info','low','medium','high','critical') NOT NULL DEFAULT 'medium',
    `title` VARCHAR(255) NOT NULL,
    `message` VARCHAR(1000) NOT NULL,
    `channel` ENUM('dashboard','email','in_app') NOT NULL DEFAULT 'dashboard',
    `is_read` TINYINT(1) NOT NULL DEFAULT 0,
    `sent_at` TIMESTAMP NULL DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_ci_alert_user_read` (`user_id`, `is_read`),
    KEY `idx_ci_alert_competitor` (`competitor_id`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`competitor_id`) REFERENCES `competitors`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`change_id`) REFERENCES `ci_changes`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='تنبيهات Competitor Intelligence - كل تنبيه مربوط بتغيير مكتشف فعليًا';

-- ------------------------------------------------------------
-- 7) Scorecards - لقطة مقاييس دورية محسوبة لكل منافس (Benchmarking)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ci_scorecards` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `competitor_id` INT(11) NOT NULL,
    `period_start` DATE NOT NULL,
    `period_end` DATE NOT NULL,
    `visibility_score` DECIMAL(5,2) DEFAULT NULL,
    `content_activity_score` DECIMAL(5,2) DEFAULT NULL,
    `offer_activity_score` DECIMAL(5,2) DEFAULT NULL,
    `customer_signals_score` DECIMAL(5,2) DEFAULT NULL,
    `product_coverage_score` DECIMAL(5,2) DEFAULT NULL,
    `market_presence_score` DECIMAL(5,2) DEFAULT NULL,
    `basis` ENUM('data_backed','estimated') NOT NULL DEFAULT 'estimated',
    `raw_metrics` JSON DEFAULT NULL COMMENT 'المقاييس الخام اللي بُنيت عليها الدرجات (شفافية كاملة)',
    `computed_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_ci_scorecard_competitor_period` (`competitor_id`, `period_end`),
    FOREIGN KEY (`competitor_id`) REFERENCES `competitors`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='بطاقات تقييم دورية لكل منافس لأغراض Benchmarking';

-- ------------------------------------------------------------
-- 8) AI Insights / Threats / Opportunities / Recommendations
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ci_insights` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT(11) NOT NULL,
    `website_id` INT(11) NOT NULL,
    `competitor_id` INT(11) DEFAULT NULL COMMENT 'NULL = رؤية على مستوى السوق ككل (multiple competitors)',
    `type` ENUM('insight','threat','opportunity','recommendation') NOT NULL,
    `title` VARCHAR(255) NOT NULL,
    `description` TEXT NOT NULL,
    `evidence` TEXT DEFAULT NULL COMMENT 'إشارة صريحة للبيانات/التغييرات اللي بُني عليها',
    `confidence` ENUM('high','medium','low') NOT NULL DEFAULT 'medium',
    `threat_level` ENUM('low','medium','high') DEFAULT NULL,
    `recommended_action` TEXT DEFAULT NULL,
    `status` ENUM('new','reviewed','dismissed') NOT NULL DEFAULT 'new',
    `generated_by` ENUM('rules_engine','ai') NOT NULL DEFAULT 'rules_engine',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_ci_insight_user_type` (`user_id`, `type`, `created_at`),
    KEY `idx_ci_insight_competitor` (`competitor_id`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`website_id`) REFERENCES `websites`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`competitor_id`) REFERENCES `competitors`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='رؤى/تهديدات/فرص/توصيات تحليلية - غير مؤكدة كحقائق';

-- ------------------------------------------------------------
-- 9) التقارير (Weekly / Monthly / Profile / Threat / Opportunity / Change)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ci_reports` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT(11) NOT NULL,
    `website_id` INT(11) NOT NULL,
    `competitor_id` INT(11) DEFAULT NULL COMMENT 'محدد فقط لتقارير Profile الخاصة بمنافس واحد',
    `type` ENUM('weekly','monthly','profile','threat','opportunity','change') NOT NULL,
    `title` VARCHAR(255) NOT NULL,
    `period_start` DATE DEFAULT NULL,
    `period_end` DATE DEFAULT NULL,
    `content_json` LONGTEXT NOT NULL COMMENT 'محتوى التقرير الكامل بصيغة JSON - مصدر الحقيقة الواحد لأي Export',
    `generated_by` ENUM('rules_engine','ai') NOT NULL DEFAULT 'rules_engine',
    `generated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_ci_report_user_type_date` (`user_id`, `type`, `generated_at`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`website_id`) REFERENCES `websites`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`competitor_id`) REFERENCES `competitors`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='تقارير Competitor Intelligence المُولَّدة';
