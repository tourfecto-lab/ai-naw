-- ============================================================
-- Tourfecto - Migration: Ad Groups (البند 6 من طلب Ads Frontend)
-- @version 1.0.0  @date 2026-08-11
--
-- ملحوظة صراحة عن النطاق: الجدول ده تنظيمي محلي داخل Tourfecto بس - مش
-- مزامنة ثنائية الاتجاه مع Ad Set (Meta) أو Ad Group (Google Ads) الفعليين
-- على المنصات. المزامنة الحالية (syncMetaCampaigns/syncGoogleAdsCampaigns)
-- بتسحب بيانات على مستوى الحملة بس، مش على مستوى Ad Set/Ad Group. العميل
-- بيقدر ينظّم كلماته/إعلاناته محليًا هنا لغرض العرض والإدارة، لكن ده مش
-- Ad Group حقيقي موجود على حساب Meta/Google بتاعه. توضيح ده صراحة في UI.
-- ============================================================

CREATE TABLE IF NOT EXISTS `ad_ad_groups` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `campaign_id` INT(11) NOT NULL,
    `name` VARCHAR(255) NOT NULL,
    `status` ENUM('active','paused') NOT NULL DEFAULT 'active',
    `budget_allocation_pct` DECIMAL(5,2) DEFAULT NULL COMMENT 'نسبة تقديرية من ميزانية الحملة، لغرض التنظيم المحلي بس',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    FOREIGN KEY (`campaign_id`) REFERENCES `ad_campaigns`(`id`) ON DELETE CASCADE,
    INDEX `idx_campaign_id` (`campaign_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='مجموعات إعلانية تنظيمية محلية داخل حملة - راجع الملاحظة فوق عن النطاق';

-- إضافة اختيارية على ad_copies وad_keywords - NULL يعني "على مستوى
-- الحملة مباشرة" (السلوك القديم زي ما هو تمامًا، بدون أي كسر) - العمود
-- ده بس يسمح بربط اختياري لمجموعة إعلانية لو العميل حب ينظّم كده.
ALTER TABLE `ad_copies`
    ADD COLUMN `ad_group_id` INT(11) DEFAULT NULL AFTER `campaign_id`,
    ADD FOREIGN KEY (`ad_group_id`) REFERENCES `ad_ad_groups`(`id`) ON DELETE SET NULL;

ALTER TABLE `ad_keywords`
    ADD COLUMN `ad_group_id` INT(11) DEFAULT NULL AFTER `campaign_id`,
    ADD FOREIGN KEY (`ad_group_id`) REFERENCES `ad_ad_groups`(`id`) ON DELETE SET NULL;

-- ============================================================
-- إضافة: Soft Delete للحملات (بند 3 من طلب Ads Frontend - "Delete
-- إذا الـBackend يسمح"). ملحوظة صراحة عن النطاق: Meta Marketing API
-- وGoogle Ads API **مفيهمش حذف نهائي حقيقي للحملة** - أقصى حاجة ممكنة
-- هي تغيير الحالة لـPAUSED/REMOVED. فـ"الحذف" هنا معناه: إخفاء الحملة من
-- قوائم Tourfecto (Soft Delete، مع الحفاظ الكامل على كل بيانات الأداء/
-- السجل/الـAudit Trail التاريخية - بدون فقدان بيانات)، بالإضافة لإيقاف
-- الحملة فعليًا على المنصة الحقيقية أولًا لو كانت شغّالة (لأمان إضافي قبل
-- إخفائها من واجهة العميل).
-- ============================================================
ALTER TABLE `ad_campaigns`
    ADD COLUMN `deleted_at` TIMESTAMP NULL DEFAULT NULL COMMENT 'Soft delete - الحملة مخفية من القوائم لكن بياناتها التاريخية محفوظة بالكامل' AFTER `status`;
