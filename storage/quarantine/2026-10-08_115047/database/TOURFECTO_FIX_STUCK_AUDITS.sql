-- ============================================================================
-- Tourfecto - فك التدقيقات العالقة "running" (بتخلّي الـ onboarding يفضل مستني)
-- بيعلّم أي تدقيق "running" بقاله أكتر من 10 دقايق كـ failed. مفيش حذف، ويتشغّل أكتر من مرة.
-- (في داتابيزك فيه 5 تدقيقات عالقة من 7 أغسطس 2026.)
-- ============================================================================
SELECT id, website_id, started_at, TIMESTAMPDIFF(MINUTE, started_at, NOW()) AS stuck_minutes
  FROM wo_audits WHERE status = 'running' AND started_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE);

UPDATE wo_audits SET status = 'failed', completed_at = NOW()
 WHERE status = 'running' AND started_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE);
