-- ============================================================
-- Tourfecto - Migration: ربط تلقائي بين CRM وطلب المراجعات
-- لما صفقة CRM تتحوّل لـ "مكسوبة" (Won)، ننشئ طلب مراجعة تلقائيًا من
-- غير ما العميل يدخل يكتب حاجة يدوي.
-- @version 1.0.0  @date 2026-07-25
-- ============================================================

ALTER TABLE `review_request_settings`
    ADD COLUMN `auto_from_crm_won` TINYINT(1) NOT NULL DEFAULT 0
    COMMENT 'لو 1، أي صفقة CRM تتقفل "مكسوبة" هتعمل طلب مراجعة تلقائي من غير تدخل يدوي'
    AFTER `default_review_link`;
