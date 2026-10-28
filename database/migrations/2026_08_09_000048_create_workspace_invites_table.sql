-- ============================================================
-- Tourfecto - Migration: دعوات الفريق (Workspace Invites)
-- (Settings Center - Phase 8)
-- @version 1.0.0  @date 2026-08-09
-- ============================================================

CREATE TABLE IF NOT EXISTS `workspace_invites` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `owner_user_id` INT(11) NOT NULL COMMENT 'صاحب الـ Workspace اللي بيدعو',
    `invited_by` INT(11) NOT NULL COMMENT 'مين بعت الدعوة فعليًا (قد يكون Admin تاني مش المالك نفسه)',

    `email` VARCHAR(190) NOT NULL,
    `role` ENUM('admin', 'manager', 'sales', 'support', 'viewer') NOT NULL DEFAULT 'viewer',

    `token` VARCHAR(64) NOT NULL COMMENT 'توكن عشوائي فريد لرابط قبول الدعوة',
    `status` ENUM('pending', 'accepted', 'revoked', 'expired') NOT NULL DEFAULT 'pending',

    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `accepted_at` TIMESTAMP NULL DEFAULT NULL,
    `expires_at` TIMESTAMP NOT NULL,

    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_token` (`token`),
    KEY `idx_owner_user_id` (`owner_user_id`),
    KEY `idx_email` (`email`),

    CONSTRAINT `fk_workspace_invites_owner` FOREIGN KEY (`owner_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='دعوات معلّقة/سابقة للانضمام لـ Workspace - لو صاحب الحساب اتحذف، دعواته المعلّقة تتحذف معاه منطقيًا (CASCADE هنا آمن لأنه جدول دعوات فقط، مفيهوش بيانات مستخدم فعلية)';
