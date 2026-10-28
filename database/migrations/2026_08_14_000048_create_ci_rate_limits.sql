-- ============================================================
-- Tourfecto - Migration: Competitor Intelligence - Rate Limits
-- @version 1.5.0  @date 2026-08-14
--
-- جدول غير هدّام لدعم خوارزمية fixed-window rate limiting على
-- الـ endpoints المكلفة (AI calls + discovery خارجي + توليد تقارير).
-- الـ scope_key بيميز scope + actor (مثال: ai_ask:user:12) والكاونتر
-- لكل نافذة زمنية. الصفوف القديمة بتتنضف تلقائيًا من الكود.
-- ============================================================

CREATE TABLE IF NOT EXISTS `ci_rate_limits` (
    `scope_key` VARCHAR(120) NOT NULL COMMENT 'scope:actor - مثال: ai_ask:user:12',
    `window_start` INT UNSIGNED NOT NULL COMMENT 'بداية نافذة fixed-window بالثواني',
    `hits` INT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'عدد المحاولات في النافذة دي',
    PRIMARY KEY (`scope_key`, `window_start`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
