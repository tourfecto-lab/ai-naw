-- ============================================================
-- Tourfecto - Migration: CRM Sales Goals (المرحلة 12 - G4)
-- @version 1.0.0  @date 2026-08-15
--
-- أهداف مبيعات شهرية لكل صاحب حساب (إيراد مستهدف)، مع تقرير
-- Win/Loss يحسب الإيراد الفعلي وسبب الخسارة - ميزات يملكها
-- كل المنافسين (HubSpot Goals / Freshsales Sales Goals / Zoho).
-- ============================================================

CREATE TABLE IF NOT EXISTS `crm_sales_goals` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `user_id` INT(11) NOT NULL COMMENT 'صاحب الحساب (Tenant)',
    `period` VARCHAR(7) NOT NULL COMMENT 'الشهر بصيغة YYYY-MM (مثال: 2026-08)',
    `target_value` DECIMAL(15,2) NOT NULL DEFAULT 0 COMMENT 'الإيراد المستهدف للشهر',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_crm_sales_goal_user_period` (`user_id`, `period`),
    CONSTRAINT `fk_crm_sales_goal_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='أهداف مبيعات شهرية لكل حساب - المرحلة 12 (G4)';
