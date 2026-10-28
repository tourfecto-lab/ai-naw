-- ============================================
-- Tourfecto - AI Revenue Intelligence v1.5.0
-- Migration: Biz Subscriptions + Sales Teams/Reps + Benchmarks
-- Created: 2026-08-16
--
-- ملاحظات:
--   1. إضافي بالكامل: لا يعدّل أي جدول موجود (عدا إضافة حقل rep للـcrm_deals).
--   2. هذا يميز صراحةً بين اشتراك "مستخدم المنصة نفسه في Tourfecto" (جدول
--      subscriptions الحالي) واشتراكات "عملاء أعمال العميل" (biz_subscriptions).
--      الأول غير مفيد لحساب NRR/GRR، والثاني هو أساسها الحقيقي.
--   3. شغّل هذا الملف مرة واحدة بعد نسخة احتياطية من قاعدة البيانات.
-- ============================================

SET FOREIGN_KEY_CHECKS = 0;

-- ============================================
-- 1) اشتراكات عملاء العميل (Biz Subscriptions)
--    جدول اشتراكات فعلية لعملاء أعمال العميل (مثلاً اشتراكات عملاء العميل
--    في خدمته). أساس MRR/ARR الحقيقي وNRR/GRR الحرفيين (Baremetrics-style).
--    IMPORTANT: هذا غير جدول `subscriptions` (خطة المستخدم نفسه في Tourfecto).
-- ============================================
CREATE TABLE IF NOT EXISTS `biz_subscriptions` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `user_id` INT(11) NOT NULL COMMENT 'صاحب الحساب (Tenant Isolation)',
    `contact_id` INT(11) DEFAULT NULL COMMENT 'ربط اختياري بجهة اتصال CRM (لو متاحة)',
    `customer_name` VARCHAR(255) NOT NULL COMMENT 'اسم العميل المشترك',
    `plan_name` VARCHAR(255) NOT NULL COMMENT 'اسم الباقة (Basic/Pro/...)',
    `status` ENUM('active', 'trialing', 'past_due', 'cancelled', 'expired') NOT NULL DEFAULT 'active',
    `billing_cycle` ENUM('monthly', 'quarterly', 'yearly') NOT NULL DEFAULT 'monthly',
    `mrr` DECIMAL(14,2) NOT NULL DEFAULT 0 COMMENT 'قيمة شهرية مكافئة للاشتراك (Monthly Recurring Revenue)',
    `currency` VARCHAR(10) NOT NULL DEFAULT 'USD',
    `started_at` DATE NOT NULL,
    `current_period_end` DATE DEFAULT NULL,
    `cancelled_at` DATE DEFAULT NULL,
    `churn_reason` VARCHAR(255) DEFAULT NULL COMMENT 'سبب انسحاب العميل (لو مسجل)',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_biz_sub_user_status` (`user_id`, `status`),
    INDEX `idx_biz_sub_contact` (`contact_id`),
    INDEX `idx_biz_sub_started` (`user_id`, `started_at`),
    CONSTRAINT `fk_biz_sub_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='اشتراكات عملاء العميل (أساس MRR/ARR/NRR/GRR الحقيقية)';

-- ============================================
-- 2) أحداث تغيير الاشتراكات (Subscription Events)
--    سجل لكل حدث يغيّر MRR: اشتراك جديد / توسعة / انكماش / انسحاب.
--    أساس MRR Breakdown الشهري (New/Expansion/Contraction/Churn) وNRR/GRR
--    الحقيقيين دون تخمين — نقرأ من الأحداث الحقيقية المسجلة.
-- ============================================
CREATE TABLE IF NOT EXISTS `biz_subscription_events` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `user_id` INT(11) NOT NULL COMMENT 'صاحب الحساب (Tenant Isolation)',
    `subscription_id` INT(11) NOT NULL,
    `event_type` ENUM('new', 'expansion', 'contraction', 'churn') NOT NULL,
    `mrr_delta` DECIMAL(14,2) NOT NULL DEFAULT 0 COMMENT 'تغيّر MRR الناتج عن الحدث (موجب/سالب)',
    `mrr_after` DECIMAL(14,2) NOT NULL DEFAULT 0 COMMENT 'إجمالي MRR الشهري للاشتراك بعد الحدث',
    `occurred_at` DATE NOT NULL,
    `notes` VARCHAR(500) DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_biz_evt_user_date` (`user_id`, `occurred_at`),
    INDEX `idx_biz_evt_sub` (`subscription_id`),
    INDEX `idx_biz_evt_type` (`event_type`),
    CONSTRAINT `fk_biz_evt_sub` FOREIGN KEY (`subscription_id`) REFERENCES `biz_subscriptions`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='أحداث تغيير اشتراكات عملاء العميل (MRR Breakdown + NRR/GRR)';

-- ============================================
-- 3) فرق البيع (Sales Teams) + مندوبي البيع (Sales Reps)
--    أساس Sales Attribution (Clari-style): توزيع الإيراد/الخط على المندوب
--    والفريق - بدل توقع إجمالي بلا نسب.
-- ============================================
CREATE TABLE IF NOT EXISTS `sales_teams` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `user_id` INT(11) NOT NULL COMMENT 'صاحب الحساب (Tenant Isolation)',
    `name` VARCHAR(255) NOT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_sales_team_user` (`user_id`),
    CONSTRAINT `fk_sales_team_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='فرق البيع';

CREATE TABLE IF NOT EXISTS `sales_reps` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `user_id` INT(11) NOT NULL COMMENT 'صاحب الحساب (Tenant Isolation)',
    `team_id` INT(11) DEFAULT NULL,
    `name` VARCHAR(255) NOT NULL,
    `email` VARCHAR(255) DEFAULT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_sales_rep_user` (`user_id`),
    INDEX `idx_sales_rep_team` (`team_id`),
    CONSTRAINT `fk_sales_rep_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_sales_rep_team` FOREIGN KEY (`team_id`) REFERENCES `sales_teams`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='مندوبي البيع';

-- حقل إسناد الصفقة لمندوب البيع (إضافة فقط - لا يمس أي عمود موجود)
ALTER TABLE `crm_deals`
    ADD COLUMN `assigned_rep_id` INT(11) DEFAULT NULL COMMENT 'مندوب البيع المسؤول عن الصفقة (Sales Attribution)' AFTER `owner_user_id`,
    ADD KEY `idx_crm_deals_rep` (`assigned_rep_id`);

-- ============================================
-- 4) مرجع Benchmarks مشتقة من بيانات المنصة الحقيقية
--    يُعبَّأ بواسطة Cron تجميعي (revai_benchmarks_rebuild) من مجاميع
--    حقيقية مجهولة الهوية عبر كل الحسابات - لا أرقام مخترعة، وإلا
--    "Not enough data" (نفس قاعدة الموديول).
-- ============================================
CREATE TABLE IF NOT EXISTS `revai_benchmarks` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `metric_key` VARCHAR(50) NOT NULL COMMENT 'e.g. growth_percent_monthly / cohort_retention_m1',
    `metric_label` VARCHAR(255) NOT NULL,
    `p25` DECIMAL(14,4) DEFAULT NULL COMMENT 'الربيع الأدنى',
    `p50` DECIMAL(14,4) DEFAULT NULL COMMENT 'الوسيط',
    `p75` DECIMAL(14,4) DEFAULT NULL COMMENT 'الربيع الأعلى',
    `basis` VARCHAR(50) NOT NULL DEFAULT 'platform' COMMENT 'مصدر الرقم: platform = مجاميع حقيقية من بيانات المنصة',
    `sample_size` INT(11) NOT NULL DEFAULT 0 COMMENT 'عدد الحسابات الداخلة في الحساب',
    `as_of_date` DATE NOT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_metric_asof` (`metric_key`, `as_of_date`),
    INDEX `idx_bench_asof` (`as_of_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Benchmarks مشتقة من بيانات المنصة الحقيقية (لا أرقام مخترعة)';

SET FOREIGN_KEY_CHECKS = 1;
