-- ============================================================
-- Tourfecto - Revenue Intelligence PRO
-- @version 2.0.0  @date 2026-10-21
--
-- إضافة فقط (Additive) - لا يحذف ولا يغيّر أي عمود قائم. آمن للتشغيل
-- أكثر من مرة (CREATE IF NOT EXISTS + فحص information_schema قبل أي ALTER).
--
--   1) revai_settings         : العملة الأساسية + قنوات التنبيه + عمولات القنوات لكل حساب
--   2) revai_fx_rates         : أسعار الصرف (عامة user_id=0 أو يدوية لكل حساب)
--   3) revai_product_costs    : تكاليف المنتجات/الرحلات لحساب الربحية الصافية
--   4) revai_alert_deliveries : سجل إرسال التنبيهات الخارجية (منع التكرار + Audit)
--   5) فهارس أداء على rev_revenue_records
-- ============================================================

CREATE TABLE IF NOT EXISTS `revai_settings` (
    `user_id` INT(11) NOT NULL PRIMARY KEY,
    `base_currency` CHAR(3) NULL COMMENT 'عملة التقارير الموحّدة - NULL = عملة النشاط الافتراضية',
    `alert_min_severity` ENUM('high','medium') NOT NULL DEFAULT 'high',
    `alert_email` TINYINT(1) NOT NULL DEFAULT 0,
    `slack_webhook_enc` TEXT NULL COMMENT 'Slack Incoming Webhook (مشفّر)',
    `alert_webhook_url_enc` TEXT NULL COMMENT 'Webhook عام JSON (مشفّر)',
    `alert_webhook_secret_enc` TEXT NULL COMMENT 'سر توقيع HMAC للـ Webhook العام (مشفّر)',
    `channel_commissions` JSON NULL COMMENT '{"ota:booking":15,"ota:viator":20,...} نسب عمولة القنوات %',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='إعدادات ذكاء الإيرادات لكل حساب';

CREATE TABLE IF NOT EXISTS `revai_fx_rates` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT(11) NOT NULL DEFAULT 0 COMMENT '0 = سعر عام من مزود أسعار، غير ذلك = سعر يدوي للحساب',
    `currency` CHAR(3) NOT NULL,
    `units_per_usd` DECIMAL(20,8) NOT NULL COMMENT 'كم وحدة من العملة = 1 دولار',
    `rate_date` DATE NOT NULL,
    `source` VARCHAR(40) NOT NULL DEFAULT 'manual',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_revai_fx_scope_cur_date` (`user_id`, `currency`, `rate_date`),
    KEY `idx_revai_fx_cur_date` (`currency`, `rate_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='أسعار الصرف لتوحيد الإيرادات متعددة العملات';

CREATE TABLE IF NOT EXISTS `revai_product_costs` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT(11) NOT NULL,
    `product_id` INT(11) NULL COMMENT 'crm_products.id - NULL مع product_name للمنتجات اليدوية',
    `product_name` VARCHAR(255) NULL,
    `label` VARCHAR(120) NOT NULL DEFAULT 'cost' COMMENT 'مثال: فندق/مرشد/مواصلات/تذاكر',
    `cost_type` ENUM('per_booking','per_traveler','percent_of_revenue') NOT NULL DEFAULT 'per_traveler',
    `amount` DECIMAL(12,2) NOT NULL,
    `currency` CHAR(3) NULL COMMENT 'لـ per_booking/per_traveler فقط',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY `idx_revai_costs_user_product` (`user_id`, `product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='تكاليف المنتجات لحساب هامش الربح الحقيقي';

CREATE TABLE IF NOT EXISTS `revai_alert_deliveries` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT(11) NOT NULL,
    `channel` VARCHAR(20) NOT NULL COMMENT 'slack/webhook/email',
    `dedup_key` VARCHAR(190) NOT NULL,
    `status` ENUM('queued','sent','failed') NOT NULL DEFAULT 'queued',
    `error` VARCHAR(500) NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_revai_alert_dedup` (`user_id`, `channel`, `dedup_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='سجل التنبيهات الخارجية';

-- فهرس للبحث بالمرجع (Idempotency الحجوزات/Stripe/OTA)
SET @tf_sql=(SELECT IF(COUNT(*)=0,'ALTER TABLE rev_revenue_records ADD INDEX idx_rev_user_source_ref (user_id, source, reference_id)','SELECT 1') FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='rev_revenue_records' AND index_name='idx_rev_user_source_ref');
PREPARE tf_stmt FROM @tf_sql; EXECUTE tf_stmt; DEALLOCATE PREPARE tf_stmt;

-- فهرس إيراد العميل الحقيقي (customer_id اتضاف في 2026_10_11_service_repairs)
SET @tf_sql=(SELECT IF(COUNT(*)=0,'ALTER TABLE rev_revenue_records ADD INDEX idx_rev_user_customer (user_id, customer_id)','SELECT 1') FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='rev_revenue_records' AND index_name='idx_rev_user_customer');
PREPARE tf_stmt FROM @tf_sql; EXECUTE tf_stmt; DEALLOCATE PREPARE tf_stmt;

-- حالة past_due للاشتراكات (فشل الدفع من Stripe) - موجودة في الـ enum الأصلي؛ نتأكد فقط
-- من وجود عمود failed_payment_at لتتبع آخر فشل دفع.
SET @tf_sql=(SELECT IF(COUNT(*)=0,'ALTER TABLE biz_subscriptions ADD COLUMN last_payment_failed_at DATETIME NULL','SELECT 1') FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='biz_subscriptions' AND column_name='last_payment_failed_at');
PREPARE tf_stmt FROM @tf_sql; EXECUTE tf_stmt; DEALLOCATE PREPARE tf_stmt;
