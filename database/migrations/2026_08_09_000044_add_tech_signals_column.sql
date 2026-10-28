-- ============================================================
-- Tourfecto - Migration: Competitor Intelligence - Technology Signals
-- @version 1.0.2  @date 2026-08-09
--
-- إضافة صغيرة غير هدّامة فوق الميجريشنز السابقة: عمود tech_signals
-- JSON في ci_snapshots لتخزين إشارات تقنية حقيقية مُلاحَظة فعليًا وقت
-- الفحص (Server/X-Powered-By headers + meta generator tag) - بند
-- "Technology Signals" في Competitor Profile (بند 4 بالأمر الأصلي).
-- كل قيمة هنا HTTP header أو meta tag حقيقي اتقرا وقت الطلب، مش تخمين.
-- ============================================================

ALTER TABLE `ci_snapshots`
    ADD COLUMN `tech_signals` JSON DEFAULT NULL COMMENT 'إشارات تقنية حقيقية (Server header, X-Powered-By, meta generator) - Not Available لو محصلش أي إشارة' AFTER `structured_data_hash`;
