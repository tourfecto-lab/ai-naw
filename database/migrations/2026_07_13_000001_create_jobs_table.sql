-- ============================================
-- Tourfecto - Migration: Create Jobs Table
-- جدول طابور المهام (Queue) - بديل DB-backed لـ Redis/RabbitMQ
-- مناسب لاستضافة مشتركة بدون worker دائم؛ يُعالَج بواسطة
-- cron/process_queue.php المُستدعى من كرون cPanel حقيقي.
-- @version 1.0.0
-- ============================================

CREATE TABLE IF NOT EXISTS `jobs` (
    `id` INT(11) NOT NULL AUTO_INCREMENT COMMENT 'معرف المهمة',
    `queue` VARCHAR(100) NOT NULL DEFAULT 'default' COMMENT 'اسم الطابور (للأولويات)',
    `job_class` VARCHAR(255) NOT NULL COMMENT 'اسم الكلاس المنفذ (implements QueueJobInterface)',
    `payload` JSON DEFAULT NULL COMMENT 'بيانات المهمة',
    `status` ENUM('pending','processing','completed','failed') NOT NULL DEFAULT 'pending',
    `attempts` INT(11) NOT NULL DEFAULT 0 COMMENT 'عدد محاولات التنفيذ',
    `last_error` TEXT DEFAULT NULL COMMENT 'آخر رسالة خطأ عند الفشل',
    `available_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'متاحة للتنفيذ من هذا الوقت (تأخير/إعادة محاولة)',
    `reserved_at` TIMESTAMP NULL DEFAULT NULL COMMENT 'وقت بداية التنفيذ الحالي',
    `completed_at` TIMESTAMP NULL DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_status_available` (`status`, `available_at`),
    INDEX `idx_queue` (`queue`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='طابور المهام الخلفية';
