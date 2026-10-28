-- ============================================================
-- Tourfecto - Migration: Ad Rule Alerts - Creative/Variant Level (بند 4)
-- @version 1.0.0  @date 2026-08-28
--
-- توسعة قواعد التنبيهات الاستباقية فوق AdAlertService القائم (بند 4):
-- إضافة أنواع قواعد على مستوى الأصل الإعلاني/التنويع/التجربة:
--   creative_underperforming  أفضل تنويع أدنى من % من CTR الحملة.
--   creative_stale            الأصل بلا أداء مُسجّل منذ N يوم.
--   variant_wasted_spend      إنفاق يتجاوز حدًا بلا أي تحويل.
--   ab_test_inconclusive      تجربة A/B جارية منذ N يوم بلا بيانات كافية.
--
-- كل قاعدة تُقيَّم من بيانات حقيقية فقط (ad_creative_variants /
-- ad_ab_tests من البنود 1-2) — مفيش أي رقم مُختلق.
--
-- Additive: توسعة ENUM فقط (MODIFY non-destructive وقابل لإعادة التشغيل)،
-- وبدون لمس جدول ad_alerts بخلاف توسعة نوع القاعدة.
-- ============================================================

ALTER TABLE `ad_alert_rules`
    MODIFY COLUMN `rule_type` ENUM(
        'budget_exhausted','cpc_spike','ctr_drop','landing_page_down','budget_pacing',
        'creative_underperforming','creative_stale','variant_wasted_spend','ab_test_inconclusive'
    ) NOT NULL COMMENT 'أنواع قواعد التنبيهات (بند 4 يضيف قواعد مستوى الأصل/التنويع/التجربة)';

ALTER TABLE `ad_alerts`
    MODIFY COLUMN `rule_type` ENUM(
        'budget_exhausted','cpc_spike','ctr_drop','landing_page_down','budget_pacing',
        'creative_underperforming','creative_stale','variant_wasted_spend','ab_test_inconclusive'
    ) NOT NULL;
