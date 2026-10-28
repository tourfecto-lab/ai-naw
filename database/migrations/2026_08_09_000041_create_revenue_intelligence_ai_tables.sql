-- ============================================================
-- Tourfecto - Migration: TOURFECTO AI REVENUE INTELLIGENCE
-- @version 1.0.0  @date 2026-08-09
--
-- بادئة `revai_` (Revenue AI) لكل جداول هذا الموديول، عمدًا مختلفة عن
-- بادئة `rev_` المستخدمة فعلاً في BATCH6 (rev_revenue_records،
-- rev_marketing_spend، rev_kpi_snapshots) - إحنا لا نعيد بناء هذه
-- الجداول ولا نكررها، هذا الموديول *يقرأ منها* عبر RevenueDataGateway
-- ويضيف فوقها طبقة ذكاء (Forecast/Insights/AI Assistant) فقط.
--
-- لا Destructive Migration هنا: كل الجداول IF NOT EXISTS، لا ALTER
-- ولا DROP على أي جدول موجود (rev_revenue_records, crm_deals,
-- crm_contacts, crm_leads, ad_campaigns... إلخ تبقى كما هي تمامًا).
-- ============================================================

-- ------------------------------------------------------------
-- 1) revai_forecasts
-- سجل تاريخي لكل عملية Forecast تتولّد (audit trail + Reports/section13
-- + يسمح لاحقًا بمقارنة "المتوقع" بـ"الفعلي" لتحسين الموديل).
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `revai_forecasts` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT(11) NOT NULL,
    `period_type` VARCHAR(20) NOT NULL COMMENT 'daily/weekly/monthly/quarterly/yearly',
    `period_start` DATE NOT NULL,
    `period_end` DATE NOT NULL,
    `expected_revenue` DECIMAL(14,2) NULL COMMENT 'NULL لو البيانات غير كافية',
    `low_estimate` DECIMAL(14,2) NULL,
    `high_estimate` DECIMAL(14,2) NULL,
    `confidence` VARCHAR(10) NULL COMMENT 'low/medium/high',
    `growth_trend` VARCHAR(20) NULL COMMENT 'up/down/flat',
    `method` VARCHAR(50) NOT NULL DEFAULT 'moving_average_trend',
    `data_points_used` INT UNSIGNED NOT NULL DEFAULT 0,
    `insufficient_data` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_revai_forecast_user` (`user_id`, `created_at`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='سجل تاريخي لتوقعات الإيرادات (AI Revenue Forecast)';

-- ------------------------------------------------------------
-- 2) revai_insights
-- جدول موحّد لكل "استنتاج" يولّده الموديول: Opportunity / Risk /
-- Anomaly / Next-Best-Action - نفس البنية (Finding/Evidence/Reasoning/
-- Confidence/Recommended Action) المطلوبة في section 15، ويُستخدم
-- كـ Audit Log (section 17) وكمصدر لصفحة Reports (section 13).
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `revai_insights` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT(11) NOT NULL,
    `type` ENUM('opportunity','risk','anomaly','action') NOT NULL,
    `category` VARCHAR(60) NOT NULL COMMENT 'مثال: high_value_customer, revenue_decline, sudden_drop, upsell',
    `title` VARCHAR(255) NOT NULL,
    `finding` TEXT NOT NULL,
    `evidence` TEXT NULL COMMENT 'JSON: الأرقام/الحقائق الداعمة',
    `reasoning_summary` TEXT NULL,
    `confidence` VARCHAR(10) NOT NULL DEFAULT 'medium' COMMENT 'low/medium/high',
    `severity` VARCHAR(10) NULL COMMENT 'low/medium/high/critical - للمخاطر والشذوذ فقط',
    `estimated_impact` DECIMAL(14,2) NULL COMMENT 'NULL لو غير قابل للحساب من بيانات حقيقية',
    `affected_area` VARCHAR(120) NULL,
    `recommended_action` VARCHAR(500) NOT NULL,
    `subject_type` VARCHAR(40) NULL COMMENT 'crm_contacts/crm_deals/rev_revenue_records/channel/...',
    `subject_id` VARCHAR(60) NULL,
    `status` ENUM('active','dismissed','actioned') NOT NULL DEFAULT 'active',
    `detected_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_revai_insight_user_type` (`user_id`, `type`, `detected_at`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Opportunities/Risks/Anomalies/Next-Best-Actions مولّدة من AI Revenue Intelligence';

-- ------------------------------------------------------------
-- 3) revai_ai_queries
-- سجل أسئلة/إجابات AI Revenue Assistant (section 10) - Audit Log +
-- يسمح لاحقًا بقياس أكثر الأسئلة تكرارًا.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `revai_ai_queries` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT(11) NOT NULL,
    `question` VARCHAR(500) NOT NULL,
    `matched_intent` VARCHAR(60) NULL,
    `answer_summary` TEXT NULL,
    `confidence` VARCHAR(10) NULL,
    `had_enough_data` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_revai_query_user` (`user_id`, `created_at`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='سجل أسئلة وأجوبة مساعد ذكاء الإيرادات';
