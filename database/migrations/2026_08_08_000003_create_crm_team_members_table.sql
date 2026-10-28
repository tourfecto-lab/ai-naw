-- ============================================================
-- Tourfecto - Migration: CRM Team & Roles (المرحلة 5 - بند 30)
-- @version 1.0.0  @date 2026-08-08
-- ============================================================

CREATE TABLE IF NOT EXISTS `crm_team_members` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `tenant_user_id` INT(11) NOT NULL COMMENT 'صاحب الحساب الأصلي (Tenant) - نفس id المستخدم الحالي كـ"Tenant" في كل جداول CRM',
    `member_user_id` INT(11) NOT NULL COMMENT 'المستخدم المُضاف كعضو فريق - لازم يكون له حساب Tourfecto فعلي بالفعل',
    `role` ENUM('admin', 'manager', 'sales', 'support', 'viewer') NOT NULL DEFAULT 'viewer',
    `added_by_user_id` INT(11) DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_member_single_tenant` (`member_user_id`) COMMENT 'قيد مبسّط متعمّد: كل مستخدم عضو في فريق CRM واحد بس حاليًا - راجع CHANGELOG',
    INDEX `idx_crm_team_tenant` (`tenant_user_id`),
    CONSTRAINT `fk_crm_team_tenant` FOREIGN KEY (`tenant_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_crm_team_member` FOREIGN KEY (`member_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='فريق CRM لكل حساب (بند 30: Roles & Permissions)';
