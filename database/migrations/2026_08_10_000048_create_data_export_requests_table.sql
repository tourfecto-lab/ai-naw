-- ============================================================
-- Tourfecto - Migration: Data Export Requests (Phase 9)
-- بناء جديد بالكامل - مفيش أي Job أو جدول لطلبات تصدير البيانات كان
-- موجود قبل كده (تم التأكد بالفحص). النظام معتمد على البنية التحتية
-- الحقيقية الموجودة أصلًا لجدول `jobs` (Queue) بدل اختراع آلية جديدة.
-- @version 1.0.0  @date 2026-08-10
--
-- إضافية بالكامل (CREATE TABLE IF NOT EXISTS) - لا DROP ولا TRUNCATE.
-- ============================================================

CREATE TABLE IF NOT EXISTS `data_export_requests` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `user_id` INT(11) NOT NULL,
    `status` ENUM('requested', 'processing', 'ready', 'failed') NOT NULL DEFAULT 'requested',
    `file_path` VARCHAR(500) DEFAULT NULL COMMENT 'مسار الملف داخل TOURFECTO_STORAGE (خارج public_html تمامًا - مش رابط عام)',
    `error_message` VARCHAR(500) DEFAULT NULL,
    `requested_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `completed_at` TIMESTAMP NULL DEFAULT NULL,
    `expires_at` TIMESTAMP NULL DEFAULT NULL COMMENT 'الملف بيتحذف تلقائيًا بعد الموعد ده (7 أيام) - بيانات شخصية حساسة ملهاش داعي تفضل متخزنة للأبد',
    PRIMARY KEY (`id`),
    INDEX `idx_user_status` (`user_id`, `status`),
    CONSTRAINT `fk_export_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='طلبات تصدير بيانات المستخدم (GDPR-style Data Export)';
