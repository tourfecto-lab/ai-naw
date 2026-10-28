-- ============================================================
-- Tourfecto - Migration: CRM Report Builder (المرحلة 15 - G13)
-- @version 1.0.0  @date 2026-08-16
--
-- تقارير مخصصة قابلة للحفظ (Report Builder) - سد فجوة 2.9
-- "تقارير قابلة للتخصيص: 🔶 (ثابتة، بدون Builder)".
--
-- Additive: جدول واحد جديد فقط. التقرير يُعرَّف كـJSON
-- (كيان + حقول + فلاتر + تجميع + ترتيب) ثم تُنفَّذ عليه
-- استعلامات آمنة من Service (بدون SQL حر من المستخدم).
-- ============================================================

CREATE TABLE IF NOT EXISTS `crm_saved_reports` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `user_id` INT(11) NOT NULL COMMENT 'صاحب الحساب (Tenant)',
    `name` VARCHAR(150) NOT NULL,
    `entity` VARCHAR(30) NOT NULL COMMENT 'contacts / leads / deals / activities / products',
    `config` TEXT NOT NULL COMMENT 'JSON: {"fields":[...],"filters":[...],"group_by":"...","order_by":"...","limit":100}',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_crm_saved_reports_user` (`user_id`),
    CONSTRAINT `fk_crm_saved_reports_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='تقارير مخصصة محفوظة - المرحلة 15 (G13)';
