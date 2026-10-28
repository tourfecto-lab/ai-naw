-- ============================================================================
-- Tourfecto - فكّ الموقع فورًا من خطأ:
--   "Tourfecto proxy: origin unreachable (Operation timed out after 20002 milliseconds)"
-- السبب: موقع عميل مربوط بخدمة Auto SEO (is_connected = 1) ورابطه هو دومين المنصة نفسها
-- (tourfecto.pro). الـ proxy بيمسك كل طلب على الدومين ده ويحوّله لنفسه فيدور في حلقة.
-- الحل الفوري: فكّ الربط للمواقع اللي رابطها دومين المنصة. آمن: بيغيّر عمودين بس (is_connected, auto_pilot_mode) في الصفوف دي
-- (مفيش حذف)، ويتشغّل أكتر من مرة. غيّر 'tourfecto.pro' لو دومين منصتك مختلف.
-- ============================================================================

-- 1) شوف الأول مين المتأثر (اختياري)
SELECT id, user_id, main_url, is_connected FROM websites
 WHERE is_connected = 1
   AND main_url REGEXP '^https?://([a-z0-9-]+\\.)*tourfecto\\.pro(:[0-9]+)?(/|$)';

-- 2) فكّ الربط
UPDATE websites
   SET is_connected = 0, auto_pilot_mode = 'off'
 WHERE is_connected = 1
   AND main_url REGEXP '^https?://([a-z0-9-]+\\.)*tourfecto\\.pro(:[0-9]+)?(/|$)';
