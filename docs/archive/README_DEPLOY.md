# Competitor Intelligence — دليل الرفع

## 1) الملفات (سحب وإفلات)
فك ضغط الملف ده وارفعه فوق مجلد المشروع على السيرفر (نفس البنية بالظبط:
app/, cron/, public_html/, database/, tests/) - كل ملف هيحل محل نظيره،
ومفيش أي ملف تاني في مشروعك هيتلمس أو يتمسح.

الملفات دي **معمول لها دمج (merge)** مش استبدال كامل - يعني لو كان عندك
تعديلات تانية في نفس الملفات دي (زي إصلاح /chat اللي عملناه قبل كده في
public_html/index.php)، هي متضمّنة هنا برضه، مش هتتمسح.

## 2) قاعدة البيانات (لازم تتعمل يدوي - مش جزء من السحب والإفلات)
افتح phpMyAdmin وشغّل الـ 4 ملفات دي **بالترتيب ده بالظبط** (كل واحد فيهم
IF NOT EXISTS / إضافي بالكامل - مفيش خطر على بياناتك الحالية):

1. `database/migrations/2026_08_08_000042_create_competitor_intelligence_tables.sql`
2. `database/migrations/2026_08_09_000043_add_sitemap_page_type.sql`
3. `database/migrations/2026_08_09_000044_add_tech_signals_column.sql`
4. `database/migrations/2026_08_09_000045_create_ci_user_preferences.sql`

## 3) Cron Job اختياري (للمراقبة التلقائية الدورية)
لو عايز المنافسين يتراقبوا تلقائيًا (مش بس عند الضغط على "افحص الآن")،
ضيف Cron Job في cPanel/hPanel بيشتغل كل 30 دقيقة:

```
php /home/USERNAME/domains/YOURSITE.com/cron/monitor_competitors.php >> /home/USERNAME/domains/YOURSITE.com/storage/logs/ci_scheduler.log 2>&1
```

غيّر USERNAME وYOURSITE.com حسب بياناتك الحقيقية.

## اللي اتعدل بالظبط (ملخص فني)
- `public_html/index.php` + `cron/bootstrap.php`: تسجيل كل كلاسات الموديول
  الجديدة يدويًا (نفس حل مشكلة /chat اللي حلّيناها قبل كده) - في المكانين
  الاتنين، عشان الموديول يشتغل من المتصفح ومن الـ Cron مع بعض.
- `app/routes/web.php` + `app/routes/api.php`: إضافة مسارات الصفحة والـ API
  الجديدة بس - كل المسارات القديمة (خصوصًا AI Chat Platform اللي مكنش موجود
  في نسخة الموديول الأصلية) اتحافظ عليها زي ما هي.
- `app/Core/Controller.php`: عنصر جديد في القائمة الجانبية.
- `app/Controllers/AdminController.php`: حقل Google Maps API Key في إعدادات
  النظام (نفس المفتاح بيُستخدم لخريطة GBP واكتشاف منافسين حقيقيين).
- `app/Services/System/SystemSettingsService.php`: تسجيل المفتاح ده.
- `app/Models/Competitor.php`: توسعة الأعمدة المسموح تعديلها لتشمل أعمدة
  الموديول الجديدة.
- `app/Lang/en.php` / `ar.php`: كل نصوص الموديول (إضافية بالكامل).
