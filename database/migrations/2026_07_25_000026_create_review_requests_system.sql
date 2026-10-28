-- ============================================================
-- Tourfecto - Migration: طلب مراجعات تلقائي بعد انتهاء الخدمة
-- العميل يضيف ضيف (اسم + واتساب + تاريخ انتهاء الخدمة)، والنظام يبعتله
-- رسالة واتساب تلقائية بعد فترة محددة تطلب منه يقيّم على Google/TripAdvisor،
-- مع تذكير واحد لطيف لو مردّش.
-- @version 1.0.0  @date 2026-07-25
-- ============================================================

CREATE TABLE IF NOT EXISTS `review_requests` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `user_id` INT(11) NOT NULL,
    `website_id` INT(11) NOT NULL,
    `guest_name` VARCHAR(150) NOT NULL,
    `guest_phone` VARCHAR(30) NOT NULL COMMENT 'رقم واتساب بصيغة دولية (بدون +)',
    `service_end_date` DATETIME NOT NULL COMMENT 'تاريخ انتهاء الإقامة/الرحلة/الخدمة',
    `delay_hours` INT(11) NOT NULL DEFAULT 4 COMMENT 'كام ساعة بعد الانتهاء نبعت الطلب',
    `review_link` VARCHAR(500) NOT NULL COMMENT 'رابط تقييم Google/TripAdvisor المباشر',
    `status` ENUM('scheduled', 'sent', 'reminded', 'reviewed', 'opted_out', 'failed') NOT NULL DEFAULT 'scheduled',
    `scheduled_send_at` DATETIME NOT NULL COMMENT 'محسوبة تلقائيًا = service_end_date + delay_hours',
    `sent_at` DATETIME NULL DEFAULT NULL,
    `reminded_at` DATETIME NULL DEFAULT NULL,
    `error_message` VARCHAR(255) DEFAULT NULL,
    `source` VARCHAR(20) NOT NULL DEFAULT 'manual' COMMENT 'manual, crm (من CRM Deals)',
    `crm_deal_id` INT(11) DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_user_id` (`user_id`),
    KEY `idx_website_id` (`website_id`),
    KEY `idx_status_scheduled` (`status`, `scheduled_send_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='طلبات المراجعات التلقائية بعد انتهاء الخدمة';

-- إعدادات الحملة لكل موقع (رسالة الطلب، رسالة التذكير، تفعيل التذكير)
CREATE TABLE IF NOT EXISTS `review_request_settings` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `website_id` INT(11) NOT NULL,
    `is_enabled` TINYINT(1) NOT NULL DEFAULT 1,
    `default_delay_hours` INT(11) NOT NULL DEFAULT 4,
    `message_template` TEXT NOT NULL COMMENT 'يدعم {name} و {review_link}',
    `reminder_enabled` TINYINT(1) NOT NULL DEFAULT 1,
    `reminder_after_hours` INT(11) NOT NULL DEFAULT 48,
    `reminder_template` TEXT NOT NULL,
    `default_review_link` VARCHAR(500) DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_website_id` (`website_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='إعدادات حملة طلب المراجعات لكل موقع';
