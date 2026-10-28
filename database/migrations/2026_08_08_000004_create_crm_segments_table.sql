-- ============================================================
-- Tourfecto - Migration: CRM Segments (المرحلة 8 - بند 19)
-- @version 1.0.0  @date 2026-08-08
-- ============================================================

CREATE TABLE IF NOT EXISTS `crm_segments` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `user_id` INT(11) DEFAULT NULL COMMENT 'NULL = قطاع افتراضي عام (Seed) - يظهر لكل الحسابات، مش قابل للحذف من الواجهة',
    `name` VARCHAR(150) NOT NULL,
    `filters` TEXT NOT NULL COMMENT 'JSON - نفس شكل $filters في CrmContactService::search()',
    `is_system` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = قطاع افتراضي مبني على بيانات حقيقية فقط (راجع Seed أسفل)',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_crm_segments_user` (`user_id`),
    CONSTRAINT `fk_crm_segments_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='قطاعات عملاء محفوظة (Customer Segmentation - بند 19)';

-- ============================================================
-- Seed: قطاعات افتراضية مبنية على بيانات حقيقية موجودة فعليًا في السكيما
-- فقط (بند 39: لا تخترع بيانات) - "High Value" و"Returning Customer" لم
-- تُضاف لأنهما يحتاجان بيانات مشتريات/فواتير غير موجودة في هذا الموديول
-- (راجع CHANGELOG - Purchases خارج نطاق CRM Core الحالي).
-- ============================================================

INSERT INTO `crm_segments` (`user_id`, `name`, `filters`, `is_system`)
SELECT NULL, seg.name, seg.filters, 1
FROM (
    SELECT 'عملاء نشطون' AS name, '{"status":"active"}' AS filters
    UNION ALL SELECT 'غير نشطين (بدون تفاعل 60 يوم)', '{"last_activity_before_days":60}'
    UNION ALL SELECT 'من الموقع الإلكتروني', '{"source":"website"}'
    UNION ALL SELECT 'من واتساب', '{"source":"whatsapp"}'
    UNION ALL SELECT 'من توصية', '{"source":"referral"}'
    UNION ALL SELECT 'Hot Lead (تقييم AI مرتفع)', '{"min_lead_score":70}'
    UNION ALL SELECT 'Cold Lead (تقييم AI منخفض)', '{"max_lead_score":39}'
    UNION ALL SELECT 'صفقة مفتوحة حاليًا', '{"has_open_deal":1}'
    UNION ALL SELECT 'VIP (صفقة بقيمة 5000+)', '{"min_deal_value":5000}'
) AS seg
WHERE NOT EXISTS (SELECT 1 FROM `crm_segments` WHERE `user_id` IS NULL AND `is_system` = 1);
