-- ============================================================
-- Tourfecto - Onboarding Wizard v3 (Professional Upgrades) - 2026-08-16
--
-- 1) onboarding_drafts: حفظ المسودة على السيرفر (استئناف عبر الأجهزة -
--    لو المستخدم سجّل الدخول من جهاز تاني، بيانات الـWizard بترجع له).
--    كمان بنخزن فيه "أقصى خطوة وصلها" عشان لوحة الفونيل الإدارية تحسب
--    معدل التسرب (drop-off) لكل خطوة بدل تخمين من الأحداث المتناثرة.
-- ============================================================

CREATE TABLE IF NOT EXISTS `onboarding_drafts` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `user_id` INT(11) NOT NULL,
    `draft` JSON DEFAULT NULL COMMENT 'بيانات نموذج الـWizard (business_name, main_url, industry, ...)',
    `step` TINYINT(4) NOT NULL DEFAULT 1 COMMENT 'أقصى خطوة وصلها المستخدم - للتتبع في لوحة الفونيل',
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_draft_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='مسودات Onboarding على السيرفر + تتبع أقصى خطوة (فونيل)';
