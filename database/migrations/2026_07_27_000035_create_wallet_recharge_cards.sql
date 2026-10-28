-- ============================================================
-- Tourfecto - Migration: بطاقات شحن المحفظة (زي أمازون)
-- الأدمن يولّد دفعة بطاقات بكود فريد لكل واحدة وقيمة محددة، يبعتها
-- للعميل، والعميل يدخل الكود في حسابه فيتشحن رصيد محفظته تلقائيًا.
-- @version 1.0.0  @date 2026-07-27
-- ============================================================

CREATE TABLE IF NOT EXISTS `wallet_recharge_cards` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `code` VARCHAR(30) NOT NULL COMMENT 'كود البطاقة الفريد - يُدخله العميل عشان يشحن',
    `value` DECIMAL(10,2) NOT NULL,
    `status` ENUM('unused', 'used') NOT NULL DEFAULT 'unused',
    `batch_label` VARCHAR(100) DEFAULT NULL COMMENT 'اسم دفعة التوليد (لتنظيم البطاقات)',
    `used_by_user_id` INT(11) DEFAULT NULL,
    `used_at` TIMESTAMP NULL DEFAULT NULL,
    `created_by_admin_id` INT(11) DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_code` (`code`),
    KEY `idx_status` (`status`),
    KEY `idx_used_by` (`used_by_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='بطاقات شحن المحفظة';
