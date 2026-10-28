-- ============================================================
-- Tourfecto - Migration: دعوات عملاء الوكالات (White-Label)
--
-- تدفّق دعوة العملاء بالرمز/الرابط: الوكيل ينشئ دعوة لعميل حقيقي
-- (بريد مسجّل بالفعل في تورفكتو) مع رمز فريد، والعميل (بعد تسجيل
-- الدخول بنفس البريد) يقبل الدعوة فيتحوّل لعميل فعلي في
-- agency_clients تلقائيًا. الجدول idempotent (IF NOT EXISTS)
-- مثل باقي ميجريشنس الوكالات.
--
-- status: pending (بانتظار القبول) / accepted (قُبِلت واتحولت
-- لعميل فعلي) / revoked (ألغاها الوكيل قبل القبول).
-- @version 1.0.0  @date 2026-08-31
-- ============================================================

CREATE TABLE IF NOT EXISTS `agency_invitations` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `agency_id` INT(11) NOT NULL COMMENT 'يشير إلى agencies.id',
    `email` VARCHAR(255) NOT NULL COMMENT 'بريد العميل المدعو (لازم يكون مستخدمًا حقيقيًا في users)',
    `token` VARCHAR(64) NOT NULL COMMENT 'رمز فريد لرابط القبول',
    `commission_rate` DECIMAL(5,2) NOT NULL DEFAULT 10.00 COMMENT 'نسبة عمولة الوكالة من حجوزات هذا العميل (%) تُطبَّق عند القبول',
    `invited_by` INT(11) NULL COMMENT 'يشير إلى users.id - الوكيل اللي أنشأ الدعوة',
    `status` ENUM('pending','accepted','revoked') NOT NULL DEFAULT 'pending',
    `expires_at` DATETIME NULL COMMENT 'موعد انتهاء صلاحية الدعوة',
    `accepted_at` DATETIME NULL DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_agency_invitations_token` (`token`),
    KEY `idx_agency_invitations_agency` (`agency_id`),
    KEY `idx_agency_invitations_email` (`email`),
    KEY `idx_agency_invitations_status` (`status`),
    CONSTRAINT `fk_agency_invitations_agency` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_agency_invitations_invited_by` FOREIGN KEY (`invited_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='دعوات عملاء الوكالات White-Label';
