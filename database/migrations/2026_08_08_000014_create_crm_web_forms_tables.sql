-- ============================================================
-- Tourfecto - Migration: CRM Web Forms (المرحلة 15 - G11)
-- @version 1.0.0  @date 2026-08-16
--
-- التقاط Leads عبر نماذج ويب عامة (Form Builder) - سد فجوة 2.1
-- "التقاط Leads (Web Form / API): 🔶 بدون Form Builder".
--
-- Additive: جدولان جديدان فقط - لا يمس أي جدول/منطق قائم.
-- النموذج العام يُنشر برابط عام (slug) والنموذج الجاهز
-- يسمح بحقول مخصصة (JSON) تُخزَّن كما هي.
-- ============================================================

CREATE TABLE IF NOT EXISTS `crm_web_forms` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `user_id` INT(11) NOT NULL COMMENT 'صاحب الحساب (Tenant)',
    `name` VARCHAR(150) NOT NULL,
    `slug` VARCHAR(80) NOT NULL COMMENT 'معرّف عام للرابط المباشر',
    `description` VARCHAR(500) DEFAULT NULL,
    `fields` TEXT DEFAULT NULL COMMENT 'JSON: [{"name":"email","label":"Email","type":"email","required":true}]',
    `success_message` VARCHAR(255) DEFAULT NULL,
    `redirect_url` VARCHAR(500) DEFAULT NULL,
    `owner_user_id` INT(11) DEFAULT NULL COMMENT 'المستخدم المستهدف للـLead الوارد (Routing)',
    `source` VARCHAR(50) DEFAULT 'web_form',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_crm_web_forms_slug` (`slug`),
    INDEX `idx_crm_web_forms_user` (`user_id`),
    CONSTRAINT `fk_crm_web_forms_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='نماذج ويب لالتقاط Leads - المرحلة 15 (G11)';

CREATE TABLE IF NOT EXISTS `crm_web_form_submissions` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `user_id` INT(11) NOT NULL COMMENT 'صاحب الحساب (Tenant)',
    `web_form_id` INT(11) NOT NULL,
    `contact_id` INT(11) DEFAULT NULL COMMENT 'الـContact المُنشأ من الإرسال',
    `lead_id` INT(11) DEFAULT NULL COMMENT 'الـLead المُنشأ من الإرسال',
    `payload` TEXT DEFAULT NULL COMMENT 'JSON: البيانات المُرسلة',
    `ip_address` VARCHAR(45) DEFAULT NULL,
    `user_agent` VARCHAR(255) DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_crm_wfs_user` (`user_id`),
    INDEX `idx_crm_wfs_form` (`web_form_id`),
    CONSTRAINT `fk_crm_wfs_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_crm_wfs_form` FOREIGN KEY (`web_form_id`) REFERENCES `crm_web_forms` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='إرسالات نماذج الويب - المرحلة 15 (G11)';
