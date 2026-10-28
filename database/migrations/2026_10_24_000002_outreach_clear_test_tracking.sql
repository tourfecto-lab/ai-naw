-- ============================================================
-- Tourfecto - مسح فتحات/ضغطات إيميلات التجربة من الشركات الحقيقية  @date 2026-10-24
-- إيميل التجربة كان بيستخدم لينكات شركة حقيقية (عيّنة)، فضغطاتك اتحسبت عليها.
-- بيمسح التتبع بس للشركات اللي ماتبعتلهاش ولا رسالة لسه (يعني أكيد كانت تجربة).
-- ============================================================

-- للمراجعة الأول: الشركات اللي هيتمسح تتبعها
SELECT id, company, report_viewed_at, clicked_at, opened_at FROM platform_leads
WHERE (report_viewed_at IS NOT NULL OR clicked_at IS NOT NULL OR opened_at IS NOT NULL)
  AND id NOT IN (SELECT DISTINCT lead_id FROM platform_lead_messages);

UPDATE platform_leads SET report_viewed_at = NULL, clicked_at = NULL, opened_at = NULL
WHERE (report_viewed_at IS NOT NULL OR clicked_at IS NOT NULL OR opened_at IS NOT NULL)
  AND id NOT IN (SELECT lead_id FROM (SELECT DISTINCT lead_id FROM platform_lead_messages) m);
