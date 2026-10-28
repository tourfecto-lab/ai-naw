-- ============================================================
-- Tourfecto - Migration: Competitor Intelligence - Keyword Alert Rules
-- @version 1.4.0  @date 2026-08-10
--
-- إضافة غير هدّامة: قائمة كلمات مفتاحية اختيارية لكل عنصر Watchlist.
-- لو موجودة، أي تغيير محتواه (before/after) فيه إحدى الكلمات دي بيولّد
-- تنبيه فورًا بغض النظر عن الحد الأدنى لخطورة التنبيه المُعتاد - بند
-- "Custom Alert Rules" في المراجعة العالمية.
-- ============================================================

ALTER TABLE `ci_watchlist`
    ADD COLUMN `keyword_filters` JSON DEFAULT NULL COMMENT 'مثال: ["AI","free trial"] - أي تغيير يحتوي إحدى الكلمات دي يولّد تنبيه فورًا بغض النظر عن alert_min_severity' AFTER `alert_channels`;
