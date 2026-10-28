-- ============================================================
-- Tourfecto - Migration: Competitor Intelligence - Sitemap Monitoring
-- @version 1.0.1  @date 2026-08-09
--
-- إضافة صغيرة وغير هدّامة فوق migration 2026_08_08_000042: توسيع
-- ENUM(page_type) في ci_snapshots و ci_changes بقيمة 'sitemap' جديدة،
-- عشان SitemapMonitor (اكتشاف صفحات جديدة/محذوفة فعليًا عبر sitemap.xml
-- - أقرب تنفيذ حقيقي وآمن لبند "New Pages / Removed Pages" في الأمر
-- الأصلي، بدل عمل crawl عشوائي غير آمن للموقع). كل القيم القديمة
-- بتفضل شغالة زي ما هي.
-- ============================================================

ALTER TABLE `ci_snapshots`
    MODIFY COLUMN `page_type` ENUM('homepage','pricing','products','services','landing','blog','contact','offers','sitemap') NOT NULL;

ALTER TABLE `ci_changes`
    MODIFY COLUMN `page_type` ENUM('homepage','pricing','products','services','landing','blog','contact','offers','sitemap') NOT NULL;
