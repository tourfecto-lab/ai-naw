-- ============================================================
-- Tourfecto - Migration: Backlink/Outreach Agent (Phase 10)
-- جداول جديدة بالكامل - أول Phase في الجلسة دي مفيهاش أي كود/جداول
-- موجودة من قبل نُبني عليها (تأكيد Phase 1 Audit: "مفيش تكامل حالي خالص").
-- ============================================================

CREATE TABLE IF NOT EXISTS `outreach_prospects` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT NOT NULL,
    `website_id` BIGINT UNSIGNED NOT NULL COMMENT 'موقع العميل بتاعنا اللي بيدور له على Backlinks',
    `domain` VARCHAR(255) NOT NULL COMMENT 'دومين الموقع المرشّح (المحتمل نتواصل معاه)',
    `contact_name` VARCHAR(255) NULL,
    `contact_email` VARCHAR(255) NULL,
    `business_type` VARCHAR(100) NULL COMMENT 'نوع النشاط (مدونة سفر، موقع سياحي، دليل محلي...)',
    `relevant_page` VARCHAR(500) NULL COMMENT 'صفحة معينة على موقعهم ذات صلة (سبب التواصل)',
    `collaboration_idea` TEXT NULL COMMENT 'فكرة التعاون المقترحة (مقال ضيف، تبادل روابط، ذكر في دليل...)',
    `status` ENUM('prospect','researched','contacted','replied','negotiating','link_acquired','declined') NOT NULL DEFAULT 'prospect',
    `link_url` VARCHAR(500) NULL COMMENT 'رابط الصفحة اللي فيها الباك لينك بعد الحصول عليه فعليًا (status=link_acquired)',
    `notes` TEXT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_op_user` (`user_id`),
    INDEX `idx_op_website` (`website_id`),
    INDEX `idx_op_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='مرشّحو الباك لينكس/التعاون (Outreach Prospects)';

CREATE TABLE IF NOT EXISTS `outreach_emails` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `prospect_id` BIGINT UNSIGNED NOT NULL,
    `sequence_number` TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '0 = الرسالة الأولى، 1/2/3 = متابعة',
    `subject` VARCHAR(255) NOT NULL,
    `body` MEDIUMTEXT NOT NULL,
    `status` ENUM('draft','approved','sent','failed') NOT NULL DEFAULT 'draft',
    `sent_at` TIMESTAMP NULL DEFAULT NULL,
    `error_message` TEXT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_oe_prospect` (`prospect_id`),
    CONSTRAINT `fk_oe_prospect` FOREIGN KEY (`prospect_id`) REFERENCES `outreach_prospects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='رسائل التواصل (مسودة/موافق عليها/مُرسَلة) لكل Prospect';
