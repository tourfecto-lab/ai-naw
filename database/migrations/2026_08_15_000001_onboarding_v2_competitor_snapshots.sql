-- ============================================================
-- Tourfecto - Migration: Onboarding Wizard v2 (Competitor Snapshots)
-- @version 1.0.0  @date 2026-08-15
--
-- إضافي بالكامل - جدول جديد فقط، لا يلمس أي جدول/بيانات موجودة.
--
-- وقت الـOnboarding بيتم تسجيل منافسي العميل فعليًا في جدول `competitors`
-- (موجود أصلًا). الجدول ده بيخزّن "لقطة لحظية" للصفحة الرئيسية لكل
-- منافس (العنوان + meta description + إشارات تقنية زي نوع الـCMS) مبنية
-- على جلب حقيقي SSRF-protected من نفس الـ WebsiteSnapshotFetcher المستخدم
-- في موديول Competitor Intelligence - عشان الواجهة تعرض "ماذا وجدنا عن
-- منافسيك" فورًا بعد الإعداد بدل ما تنتظر الـcron بتاع التحليل الدوري.
--
-- البيانات هنا للعرض الفوري فقط (تُعاد كتابتها مع كل Onboarding جديد)،
-- التحليل العميق المستمر بيتم في جدول ci_snapshots/ci_changes بتاع
-- Competitor Intelligence.
-- ============================================================

CREATE TABLE IF NOT EXISTS `onboarding_competitor_snapshots` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `website_id` INT(11) NOT NULL,
    `user_id` INT(11) NOT NULL,
    `competitor_id` BIGINT UNSIGNED DEFAULT NULL COMMENT 'id من جدول competitors لو تم التسجيل فيه بنجاح',
    `domain` VARCHAR(500) NOT NULL,
    `title` VARCHAR(500) DEFAULT NULL,
    `meta_description` VARCHAR(1000) DEFAULT NULL,
    `tech_signals` JSON DEFAULT NULL COMMENT 'إشارات تقنية حقيقية (مثلاً: cms_hint) من استجابة الـHTTP الفعلية',
    `http_status` INT(11) DEFAULT NULL,
    `error` VARCHAR(255) DEFAULT NULL,
    `fetched_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_onsnap_website` (`website_id`),
    KEY `idx_onsnap_user` (`user_id`),
    KEY `idx_onsnap_competitor` (`competitor_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='لقطات لحظية للصفحات الرئيسية للمنافسين وقت الـOnboarding - عرض فوري فقط';
