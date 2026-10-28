-- ============================================================
-- Tourfecto - Migration: لقطات يومية لمقاييس الفوترة (billing_metrics_snapshots)
--
-- جدول جديد بالكامل - بيخزّن قيمة MRR/ARR/الاشتراكات الفعّالة مرة واحدة
-- في اليوم، عشان نقدر نرسم Trend حقيقي عبر الزمن بدل ما يبقى الرقم
-- لحظي بس (Phase 8). مفيش أي قيمة مخترعة - كل صف بيتسجّل من نفس
-- استعلامات getBillingAnalytics() الحقيقية.
--
-- مفيش cron job في المشروع نقدر نعتمد عليه، فبدل ما نخترع نظام جدولة
-- جديد، اللقطة بتتسجّل "بشكل كسول" (lazy) أول مرة حد يفتح صفحة إحصائيات
-- الفوترة في اليوم ده - لو مفيش لقطة اليوم، بتتسجّل، ولو موجودة بيتم
-- تجاهلها (INSERT ... ON DUPLICATE KEY UPDATE). ده معناه إن التاريخ
-- هيتكوّن تدريجيًا من الاستخدام الفعلي، مش أرقام وهمية أو تاريخ مُلفّق.
-- @version 1.0.0  @date 2026-08-10
-- ============================================================

CREATE TABLE IF NOT EXISTS `billing_metrics_snapshots` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `snapshot_date` DATE NOT NULL,
    `mrr` DECIMAL(12,2) NOT NULL DEFAULT 0,
    `arr` DECIMAL(12,2) NOT NULL DEFAULT 0,
    `active_subscriptions` INT(11) NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_billing_metrics_snapshot_date` (`snapshot_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='لقطة يومية لمقاييس الفوترة - لرسم Trend حقيقي عبر الزمن';
