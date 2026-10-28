-- ============================================================
-- Tourfecto - Migration: Keyword Intelligence (Phase 6)
-- إضافي بالكامل - mفيش أي عمود أو بيانات موجودة اتحذفت. الأعمدة كلها
-- Nullable عشان الكلمات المتابَعة حاليًا (اللي search_volume/difficulty
-- بتاعتها فاضية أصلًا - مفيش حد كان بيملاها) تفضل شغالة زي ما هي لحد ما
-- تتعمل لها Enrichment.
-- ============================================================

ALTER TABLE `tracked_keywords`
    ADD COLUMN `search_intent` VARCHAR(30) NULL DEFAULT NULL
        COMMENT 'نية البحث: informational/navigational/commercial/transactional' AFTER `difficulty`,
    ADD COLUMN `commercial_intent` VARCHAR(10) NULL DEFAULT NULL
        COMMENT 'low/medium/high - قد إيه الكلمة دي قريبة من قرار شراء/حجز' AFTER `search_intent`,
    ADD COLUMN `opportunity_score` TINYINT UNSIGNED NULL DEFAULT NULL
        COMMENT '0-100: فرصة حقيقية آخد ترتيب كويس عليها بأقل مجهود (حجم بحث معقول + منافسة مش عالية جدًا)' AFTER `commercial_intent`,
    ADD COLUMN `target_page` VARCHAR(255) NULL DEFAULT NULL
        COMMENT 'الصفحة المقترحة تستهدف الكلمة دي - مسار موجود أو اقتراح صفحة جديدة' AFTER `opportunity_score`,
    ADD COLUMN `priority` VARCHAR(10) NULL DEFAULT NULL
        COMMENT 'high/medium/low - أولوية العمل على الكلمة دي' AFTER `target_page`,
    ADD COLUMN `enriched_at` TIMESTAMP NULL DEFAULT NULL
        COMMENT 'آخر مرة اتحسبت فيها الأعمدة دي بالذكاء الاصطناعي' AFTER `priority`,
    ADD INDEX `idx_tk_priority` (`priority`),
    ADD INDEX `idx_tk_opportunity` (`opportunity_score`);
