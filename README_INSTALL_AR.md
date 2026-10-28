# Tourfecto Production Repair Pack

هذه الحزمة مخصصة للنسخة المرفوعة حاليًا، ومبنية على SQL dump الذي تم فحصه مع الكود الحالي.

## 1) قبل الرفع
- خذ Backup كامل للكود وقاعدة البيانات.
- لا ترفع `.env` الموجود داخل النسخة الأصلية مرة أخرى. الحزمة لا تحتوي `.env`.
- غيّر مفاتيح API/Secrets التي كانت موجودة في النسخة الأصلية المكشوفة.

## 2) رفع الملفات
ارفع محتويات `TOURFECTO_DROPIN/` فوق جذر المشروع مع **استبدال الملفات عند السؤال**.

أهم الملفات المضافة/المعدلة:
- `composer.json` — يثبت `ext-pdo_mysql`.
- `app/Config/runtime_requirements.php` — فحص متطلبات PHP + fallback autoloader.
- `app/Config/database.php` — يمنع خطأ `PDO::MYSQL_ATTR_*` الغامض عند غياب pdo_mysql.
- `public_html/index.php` — guard قبل تشغيل التطبيق + fallback autoloader.
- `cron/bootstrap.php` — نفس الحماية للـCron.
- `database/repair_production.php` — فحص pdo_mysql قبل الـmigrations.
- `tools/production_preflight.php` — فحص سريع قبل التشغيل.

## 3) قاعدة البيانات
استورد `TOURFECTO_SCHEMA_REPAIR.sql` **بعد أخذ backup**.

هذا الملف غير هدّام: لا يحتوي DROP/TRUNCATE/DELETE، ويضيف الجداول الناقصة وأعمدة ناقصة بشكل شرطي حتى لا يعيد إنشاء الموجود.

## 4) ربط Business Center
بعد نجاح schema patch، راجع أولًا النتائج. يوجد ملف اختياري اسمه `TOURFECTO_BUSINESS_BACKFILL_OPTIONAL.sql` لربط المواقع الحالية بجدول Businesses. هذا الملف لا يحذف بيانات، لكنه يضيف Business records بناءً على بيانات `websites`؛ شغله فقط عندما تكون هذه العلاقة مناسبة لبياناتك الحالية.

## 5) بعد الرفع
شغّل:

`php tools/production_preflight.php`

ثم:

`php database/repair_production.php --dry-run`

وعلى سيرفر الإنتاج، بعد backup:

`php database/repair_production.php --apply`

الـrepair runner موجود كمسار أقوى لتطبيق أي migrations لاحقة لم يشملها ملف SQL اليدوي.

## 6) Cron
تأكد أن `cron/process_queue.php` يعمل كل دقيقة تقريبًا. بدون Queue worker ستظل بعض عمليات النشر والمزامنة والرفع في pending.

## 7) ملاحظة مهمة
لم يتم تشغيل SQL ضد MySQL حقيقي داخل هذه البيئة، لذلك الحزمة تصلح الفجوات التي أثبتها مقارنة dump/migrations، لكن الاختبار النهائي يجب أن يكون على نسخة Staging/Production بعد backup.

Generated missing tables: 109
Generated missing existing-table columns: 59
