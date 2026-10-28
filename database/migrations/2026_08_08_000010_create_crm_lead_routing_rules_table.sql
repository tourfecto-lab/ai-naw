-- ============================================================
-- Tourfecto - Migration: CRM Lead Routing Rules (المرحلة 13 - G5)
-- @version 1.0.0  @date 2026-08-15
--
-- توجيه تلقائي للـLeads: قواعد تختار المالك (Sales Rep) عند إنشاء
-- Lead جديد حسب المصدر/الدولة/القيمة - ميزة يملكها Freshsales/Pipedrive.
-- Additive بالكامل: جدول جديد فقط، ولا تعديل على CrmController::createLead
-- الأصلي - يُنادى عليه من نقاط نهاية CrmApiController الجديدة بعد إنشاء
-- الـLead (اختياري للعميل)، أو يدويًا عبر نقاط نهاية إعادة التوجيه.
--
-- assignment_mode: 'fixed' = يحيل لمستخدم محدد (assignee_user_id)،
-- 'round_robin' = يوزّع بالتناوب بين فريق الحساب (التاجر + الأعضاء) عبر
-- عمود rotation_index المحدَّث من الخدمة.
-- ============================================================

CREATE TABLE IF NOT EXISTS `crm_lead_routing_rules` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `user_id` INT(11) NOT NULL COMMENT 'صاحب الحساب (Tenant)',
    `name` VARCHAR(255) NOT NULL COMMENT 'اسم القاعدة (ظاهر للمستخدم)',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `match_source` VARCHAR(50) DEFAULT NULL COMMENT 'تطابق على مصدر الـLead (website/whatsapp...) - NULL = أي مصدر',
    `match_country` VARCHAR(100) DEFAULT NULL COMMENT 'تطابق على دولة جهة الاتصال - NULL = أي دولة',
    `match_min_value` DECIMAL(14,2) DEFAULT NULL COMMENT 'حد أدنى لقيمة الفرصة - NULL = بلا حد',
    `match_max_value` DECIMAL(14,2) DEFAULT NULL COMMENT 'حد أقصى لقيمة الفرصة - NULL = بلا حد',
    `assignment_mode` ENUM('fixed', 'round_robin') NOT NULL DEFAULT 'fixed',
    `assignee_user_id` INT(11) DEFAULT NULL COMMENT 'المستخدم المستهدف في وضع fixed',
    `rotation_index` INT(11) NOT NULL DEFAULT 0 COMMENT 'عداد التناوب في وضع round_robin (يديره الـService)',
    `sort_order` INT(11) NOT NULL DEFAULT 0 COMMENT 'ترتيب التطبيق - أول قاعدة مطابقة تفوز',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_crm_lrr_user` (`user_id`),
    INDEX `idx_crm_lrr_active` (`user_id`, `is_active`),
    CONSTRAINT `fk_crm_lrr_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_crm_lrr_assignee` FOREIGN KEY (`assignee_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='قواعد التوجيه التلقائي للـLeads - المرحلة 13 (G5)';
