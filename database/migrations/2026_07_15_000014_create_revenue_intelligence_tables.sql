-- ============================================================
-- BATCH6 - Revenue Intelligence
-- بادئة rev_ لكل الجداول عشان نتفادى أي تصادم اسم مع جداول موجودة
-- (زي مشكلة `competitors`/`reviews` اللي اكتشفناها من غير migration).
-- تعمّدنا نتجاهل جدولي leads/customers الأصليين في الموديول لأن
-- عندك crm_leads/crm_contacts بنفس الغرض بالظبط من دفعة سابقة.
--
-- إصلاح 2026-08-29: user_id هنا كان BIGINT UNSIGNED مع FK على
-- users(id) اللي هو INT(11) في السيرفر الحي => FK بيفشل (errno 150)
-- والجداول لم تُنشأ في أي بيئة فعلية. عُدّل النوع لـ INT(11) ليطابق
-- users.id ويعمل الـ CASCADE فعليًا.
-- ============================================================

CREATE TABLE IF NOT EXISTS `rev_revenue_records` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT(11) NOT NULL,
    `source` VARCHAR(100) NOT NULL COMMENT 'booking/order/subscription/manual',
    `reference_id` VARCHAR(100) NULL COMMENT 'معرف الطلب/الحجز المرجعي لو موجود',
    `amount` DECIMAL(12,2) NOT NULL,
    `currency` VARCHAR(10) NOT NULL DEFAULT 'USD',
    `recorded_at` DATETIME NOT NULL,
    `notes` VARCHAR(500) NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_rev_user_date` (`user_id`, `recorded_at`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='سجل إيرادات فعلي - أول مصدر بيانات حقيقي للإيرادات في المنصة';

CREATE TABLE IF NOT EXISTS `rev_marketing_spend` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT(11) NOT NULL,
    `channel` VARCHAR(100) NOT NULL COMMENT 'google_ads/meta_ads/other',
    `amount` DECIMAL(12,2) NOT NULL,
    `spend_date` DATE NOT NULL,
    `notes` VARCHAR(500) NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_rev_spend_user_date` (`user_id`, `spend_date`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='إنفاق تسويقي يدوي - مكمّل لـ ad_campaigns.spend الموجود لو المستخدم مش رابط حساباته فعليًا';

CREATE TABLE IF NOT EXISTS `rev_kpi_snapshots` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT(11) NOT NULL,
    `snapshot_date` DATE NOT NULL,
    `revenue_total` DECIMAL(12,2) NOT NULL DEFAULT 0,
    `spend_total` DECIMAL(12,2) NOT NULL DEFAULT 0,
    `new_leads` INT UNSIGNED NOT NULL DEFAULT 0,
    `cac` DECIMAL(10,2) NULL COMMENT 'تكلفة اكتساب العميل = spend_total / new_leads',
    `roas` DECIMAL(6,2) NULL COMMENT 'العائد على الإنفاق الإعلاني = revenue_total / spend_total',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uniq_rev_user_date` (`user_id`, `snapshot_date`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='لقطات يومية محسوبة، بتتحدث من cron يومي';
