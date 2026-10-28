-- ============================================================================
-- Tourfecto - تشخيص الطابور (للقراءة فقط ما عدا الجزء الاختياري الأخير)
-- شغّله في phpMyAdmin > SQL وابعتلي النتايج لو الـ onboarding لسه عالق.
-- ============================================================================

-- 1) آخر 10 مهام: الحالة، المحاولات، وآخر خطأ
SELECT id, SUBSTRING_INDEX(job_class, '\\', -1) AS job, status, attempts, created_at, reserved_at, completed_at, LEFT(IFNULL(last_error, ''), 120) AS last_error
  FROM jobs ORDER BY id DESC LIMIT 10;

-- 2) هل الـ cron شغّال؟ لو "دقايق منذ آخر مهمة اتنفّذت" كبيرة (أكتر من 10) وفي مهام منتظرة = الـ cron واقف
SELECT status, COUNT(*) AS jobs FROM jobs GROUP BY status;
SELECT MAX(COALESCE(completed_at, reserved_at)) AS last_job_activity,
       TIMESTAMPDIFF(MINUTE, MAX(COALESCE(completed_at, reserved_at)), NOW()) AS minutes_since_last_activity
  FROM jobs;
SELECT COUNT(*) AS pending_now, MIN(created_at) AS oldest_pending,
       TIMESTAMPDIFF(MINUTE, MIN(created_at), NOW()) AS oldest_pending_minutes
  FROM jobs WHERE status = 'pending' AND available_at <= NOW();

-- 3) مهام "قيد التنفيذ" عالقة أكتر من 5 دقايق (العامل وقع في النص)
SELECT id, SUBSTRING_INDEX(job_class, '\\', -1) AS job, attempts, reserved_at,
       TIMESTAMPDIFF(MINUTE, reserved_at, NOW()) AS stuck_minutes
  FROM jobs WHERE status = 'processing' AND reserved_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE);

-- ----------------------------------------------------------------------------
-- (اختياري) فك المهام العالقة: بيرجّع "processing" القديمة لـ pending عشان تتنفّذ تاني. آمن وبيتشغّل أكتر من مرة.
-- شيل علامة -- من الأمر اللي تحته لو عايز تنفّذه:
-- UPDATE jobs SET status = 'pending', reserved_at = NULL WHERE status = 'processing' AND reserved_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE);
