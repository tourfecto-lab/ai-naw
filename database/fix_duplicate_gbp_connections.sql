-- تنضيف ربط Google Business المتكرر (إعادة الربط كانت بتعمل صف جديد كل مرة بدل ما تحدّث القديم).
-- بيسيب أحدث ربط لكل موقع، وبينقل المنشورات المجدولة والصور وسجل المزامنة عليه قبل حذف النسخ القديمة
-- (الجداول دي مربوطة بـ ON DELETE CASCADE، فمن غير النقل كانت هتتمسح).
-- شغّله في phpMyAdmin بعد ما تختار قاعدة البيانات (aa). اعمل نسخة احتياطية من platform_connections الأول.

-- 1) مراجعة قبل أي تغيير: النسخ المتكررة
SELECT website_id, COUNT(*) AS copies, MAX(id) AS keep_id, GROUP_CONCAT(id ORDER BY id) AS all_ids
FROM platform_connections
WHERE platform = 'google_business'
GROUP BY website_id
HAVING COUNT(*) > 1;

-- 2) خريطة: كل نسخة قديمة => أحدث نسخة لنفس الموقع
DROP TEMPORARY TABLE IF EXISTS tf_gbp_dupes;
CREATE TEMPORARY TABLE tf_gbp_dupes AS
SELECT pc.id AS old_id, k.keep_id
FROM platform_connections pc
JOIN (SELECT website_id, MAX(id) AS keep_id FROM platform_connections WHERE platform = 'google_business' GROUP BY website_id) k
  ON k.website_id = pc.website_id
WHERE pc.platform = 'google_business' AND pc.id <> k.keep_id;

-- 3) نقل البيانات المرتبطة على النسخة اللي هتفضل
UPDATE gbp_scheduled_posts t JOIN tf_gbp_dupes d ON d.old_id = t.platform_connection_id SET t.platform_connection_id = d.keep_id;
UPDATE gbp_sync_logs t JOIN tf_gbp_dupes d ON d.old_id = t.connection_id SET t.connection_id = d.keep_id;
UPDATE gbp_photos t JOIN tf_gbp_dupes d ON d.old_id = t.connection_id SET t.connection_id = d.keep_id;

-- 4) حذف النسخ القديمة
DELETE pc FROM platform_connections pc JOIN tf_gbp_dupes d ON d.old_id = pc.id;

DROP TEMPORARY TABLE IF EXISTS tf_gbp_dupes;

-- 5) تأكيد: لازم يرجع صف واحد لكل موقع
SELECT website_id, COUNT(*) AS copies FROM platform_connections WHERE platform = 'google_business' GROUP BY website_id;
