-- ============================================================
-- Tourfecto - Migration: Ad Next-Best-Action Recommendations (بند 5)
-- @version 1.0.0  @date 2026-08-28
--
-- توصيات "الخطوة التالية" لكل حملة إعلانية — محسوبة من ترند إحصائي
-- حقيقي (انحدار خطي بأقل المربعات على بيانات ad_performance_reports)
-- مع حفظ التاريخ/السجل (audit trail) بحيث يقدر المستخدم يعرّف كل توصية
-- pending/applied/dismissed.
--
-- المبدأ: التوصية اقتراح فقط (أبدًا تنفيذ تلقائي)، والرقم في الـ reason
-- حقيقي من المزامنة، ولو البيانات غير كافية نقول ذلك صراحة (action=wait).
-- Additive و idempotent (جدول جديد فقط).
-- ============================================================

CREATE TABLE IF NOT EXISTS `ad_recommendations` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `user_id` INT(11) NOT NULL,
    `campaign_id` INT(11) NOT NULL,
    `action` ENUM('increase_budget','decrease_budget','pause_campaign','rotate_creative','start_ab_test','review_targeting','wait') NOT NULL,
    `basis` VARCHAR(20) NOT NULL DEFAULT 'statistical' COMMENT 'statistical|rule - تصنيف صريح لنوع الأساس',
    `confidence` ENUM('low','moderate','high') DEFAULT NULL COMMENT 'بناءً على كفاية أيام البيانات',
    `reason` TEXT NOT NULL COMMENT 'الأسباب بأرقام حقيقية من المزامنة',
    `signals` JSON DEFAULT NULL COMMENT 'الإشارات الإحصائية المحسوبة (trends/KPIs)',
    `status` ENUM('pending','applied','dismissed') NOT NULL DEFAULT 'pending',
    `recommendation_date` DATE NOT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_user_campaign_date` (`user_id`, `campaign_id`, `recommendation_date`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`campaign_id`) REFERENCES `ad_campaigns`(`id`) ON DELETE CASCADE,
    INDEX `idx_user_status` (`user_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='توصيات الخطوة التالية للحملات - بند 5 (اقتراحات فقط، لا تنفيذ تلقائي)';
