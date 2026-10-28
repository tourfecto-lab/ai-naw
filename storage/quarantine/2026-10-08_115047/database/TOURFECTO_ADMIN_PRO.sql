-- ============================================================================
-- Tourfecto - Admin Pro (الفريق والصلاحيات + ملاحظات العملاء + فهارس التدقيق)
-- additive + idempotent: مفيش حذف، وممكن يتشغّل أكتر من مرة.
-- بدون تشغيله: لوحة الأدمن شغّالة بشكل طبيعي، وكل أدمن حالي يفضل بصلاحيات "مدير"
-- (super_admin = مالك)؛ بس صفحة الفريق هتطلب منك تشغيله قبل إضافة/تعديل الأعضاء.
-- ============================================================================
CREATE TABLE IF NOT EXISTS `admin_staff` (
  `user_id` BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  `staff_role` VARCHAR(20) NOT NULL DEFAULT 'admin',
  `extra_permissions` TEXT NULL,
  `previous_role` VARCHAR(30) NULL,
  `created_by` BIGINT UNSIGNED NULL,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `admin_notes` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `author_id` BIGINT UNSIGNED NOT NULL,
  `note` TEXT NOT NULL,
  `is_pinned` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_notes_user` (`user_id`, `is_pinned`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `audit_logs`
  ADD INDEX IF NOT EXISTS `idx_audit_created` (`created_at`),
  ADD INDEX IF NOT EXISTS `idx_audit_user` (`user_id`, `created_at`),
  ADD INDEX IF NOT EXISTS `idx_audit_object` (`object_type`, `object_id`);
ALTER TABLE `login_history` ADD INDEX IF NOT EXISTS `idx_lh_status_ip` (`status`, `created_at`, `ip_address`);
