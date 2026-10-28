-- ============================================================================
-- Tourfecto - إصلاح فوري: "تعذر إكمال الإعداد" عند الضغط على "ابدأ التحليل" في الـ onboarding
-- السبب: ملف TOURFECTO_SCHEMA_COMPLETE.sql (إصدارات v4 لحد v5) ضاف عمود websites.industry
-- كعمود "محسوب" (GENERATED ... VIRTUAL). الـ onboarding بيحاول يكتب فيه فتترفض بالخطأ 1906.
-- الحل: حذف العمود ده بس لو كان محسوب. أمان: عمود بدون بيانات (قيمته بتتحسب من industry_niche)،
-- والكود الجديد بقى بيقرا industry_niche مباشرة. لو العمود عادي (مش محسوب) أو مش موجود: مفيش أي تغيير.
-- ============================================================================
SET @gen = (SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = 'websites' AND column_name = 'industry'
               AND (extra LIKE '%GENERATED%' OR extra LIKE '%VIRTUAL%' OR extra LIKE '%PERSISTENT%'));
SET @sql = IF(@gen > 0, 'ALTER TABLE `websites` DROP COLUMN `industry`', 'SELECT ''لا يوجد عمود industry محسوب - مفيش حاجة تتغيّر'' AS result');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
