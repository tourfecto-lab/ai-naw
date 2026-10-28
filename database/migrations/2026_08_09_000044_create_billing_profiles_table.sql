-- ============================================================
-- Tourfecto - Migration: بيانات الفوترة الرسمية (billing_profiles)
--
-- جدول جديد بالكامل (لا يمس جدول users أو أي جدول فوترة موجود) -
-- بيانات اختيارية يقدر العميل يعبّيها لو محتاج فواتير رسمية تحتوي
-- اسمه القانوني/اسم شركته وعنوانه ورقم تسجيله الضريبي (VAT/Tax ID).
--
-- مفيش أي حساب أو تحقق ضريبي تلقائي هنا - بيانات نصية بيدخلها العميل
-- بنفسه وتتعرض على الفاتورة/بروفايل الفوترة زي ما هي، مفيش قواعد
-- ضريبية مفترضة لأي دولة (زي ما طلب في التعليمات الأصلية).
-- @version 1.0.0  @date 2026-08-09
-- ============================================================

CREATE TABLE IF NOT EXISTS `billing_profiles` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `user_id` INT(11) NOT NULL,
    `legal_name` VARCHAR(255) DEFAULT NULL COMMENT 'الاسم القانوني/اسم الشركة الرسمي على الفاتورة',
    `billing_email` VARCHAR(255) DEFAULT NULL COMMENT 'إيميل استلام الفواتير (لو مختلف عن إيميل الحساب)',
    `address_line1` VARCHAR(255) DEFAULT NULL,
    `address_line2` VARCHAR(255) DEFAULT NULL,
    `city` VARCHAR(100) DEFAULT NULL,
    `country` VARCHAR(100) DEFAULT NULL,
    `tax_id` VARCHAR(100) DEFAULT NULL COMMENT 'الرقم الضريبي / VAT ID - نص حر، بدون أي تحقق قواعد دولة معيّنة',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_billing_profiles_user_id` (`user_id`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='بيانات الفوترة الرسمية الاختيارية لكل عميل';
