-- ============================================================
-- Tourfecto - Migration: Content Agent additions (Phase 8)
-- إضافي بالكامل - مفيش أي عمود أو بيانات موجودة اتحذفت.
-- ============================================================

ALTER TABLE `ai_articles`
    ADD COLUMN `faqs_json` TEXT NULL DEFAULT NULL
        COMMENT 'أسئلة شائعة مقترحة (JSON array من question/answer)' AFTER `suggested_keywords`,
    ADD COLUMN `schema_suggestion` TEXT NULL DEFAULT NULL
        COMMENT 'FAQPage JSON-LD جاهز للصق - NULL لو مفيش FAQs' AFTER `faqs_json`,
    ADD COLUMN `internal_link_suggestions_json` TEXT NULL DEFAULT NULL
        COMMENT 'اقتراحات روابط داخلية (JSON array)' AFTER `schema_suggestion`;
