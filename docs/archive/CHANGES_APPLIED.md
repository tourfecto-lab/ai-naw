# اللي اتعمل من tourfecto_gbp_final__3_.zip (Rounds 6-7 من موديول GBP)

## 📋 السياق
الزip ده هو **تراكم 7 جولات تطوير** على موديول Google Business Profile
(أول جولة منها كانت اللي دمجناها زمان في أول باتش بعتّه). قريت
`CHANGELOG.md` (237 سطر) و`PRODUCTION_READINESS_REPORT.md` بالكامل.
معظم Rounds 1-5 كانت أصلًا مطابقة حرفيًا لنسختك الحالية (لأنك دمجتها
قبل كده)، فالباتش ده بيركّز بس على **Round 6 (Posts Edit/Delete + Async
Photo Upload)** و**Round 7 (Production Finalization - إصلاح 5 باگات
حقيقية)**.

## 🐛 باگات حقيقية اتصلحت في Round 7 (موثّقة في `PRODUCTION_READINESS_REPORT.md`)
1. **Attributes API كانت ناقصة وغلط جزئيًا** — كانت بتفترض BOOL بس،
   وفيها خطأ في اسم الحقل (`attributeId` بدل `name` في الـwrite payload).
   اتصلحت بالكامل: بتكتشف الـAttributes الحقيقية المتاحة لتصنيف النشاط
   عن طريق `attributes.list` بدل التخمين.
2. **مفيش حماية من نشر مزدوج** في `PublishGbpPostJob` - لو الـQueue
   عمل retry بسبب lock قديم، ممكن نفس البوست ينشر مرتين. اتصلح بفحص
   status قبل أي شغل.
3. **مجلد رفع صور GBP مكانوش محمي** بـ`.htaccess` (زي باقي مجلدات الرفع
   في المشروع). **ملحوظة: الملف ده مكانش موجود فعليًا في الزip رغم إن
   التقرير بيقول إنه اتعمل** - جهزته بنفسي مطابق لنفس نمط
   `storage/uploads/.htaccess` الموجود عندك.
4. **ثغرة استهلاك AI credits من غير فحص رصيد** — `POST /api/gbp/content`
   و`GET /api/gbp/ai-insights` كانوا بيستخدموا Gemini من غير
   `SubscriptionMiddleware:require_ai_credits` (كل endpoints الـAI
   التانية في المشروع بتستخدمه). اتصلح.
5. **N+1 query** في `listContent()` - كان بيجيب آخر جدولة لكل بوست في
   loop منفصل (لحد 30 استعلام إضافي لكل صفحة). اتصلح بـquery واحد
   مجمّع.

## ✅ الميزات الجديدة (Round 6)
- **Posts Edit/Delete/Cancel**: تعديل نص بوست قبل ما يتجدول، إلغاء
  بوست مجدول (بيمسح صف الـjob المرتبط لو لسه pending)، حذف مسودة -
  كل عملية موثّقة بوضوح إنها محلية بس (مش بتحذف من Google لو اتنشر
  بالفعل، لأن Google Local Posts API مفهاش endpoint لده أصلًا)
- **رفع الصور بقى Async حقيقي**: بدل ما المستخدم يستنى رد Google API
  وهو واقف على الصفحة، الرفع بيرجع فورًا (HTTP 202) وبيتنفّذ في
  الخلفية عن طريق Queue، مع حالة "جارِ الرفع" و"فشل الرفع" واضحة

## ✅ الميزات الجديدة (Round 7)
- **`GbpAuditLogger`**: سجل تدقيق حقيقي لكل عمليات GBP المهمة (ربط،
  فصل، مزامنة، تعديل بروفايل، رفع/حذف صور، نشر بوستات، تحليل AI) -
  مع **blocklist صريح** بيشيل أي مفتاح فيه `token`/`secret`/`password`
  قبل ما يتسجّل، كحماية إضافية

## الملفات في الباتش ده (22 - 21 كود + .htaccess)

### دمج جراحي (مش استبدال)
- **`app/routes/api.php`** — 4 تعديلات حقيقية بس: إضافة AI credits gate
  لـ2 routes موجودين، + 3 routes جديدة للـEdit/Delete/Cancel. باقي
  الملف زي ما هو (نسخة الموديول كانت قديمة جدًا وناقصة كل التعديلات
  التانية اللي دمجناها في الجولات اللي فاتت)
- **`public_html/index.php`** — سطر classmap واحد بس
  (`GbpAuditLogger.php`)
- **`app/Controllers/GbpProfileController.php`** — تعديل صغير (رفع
  الصورة بقى Async)

### استبدال كامل (آمن - كلها ملفات GBP مستقلة، اتأكدت من كل الفروق)
- `GoogleBusinessAPI.php` (الإصلاحات الحرجة + Attributes API الكاملة -
  مع الحفاظ على `has_coordinates` اللي ضفناها في جولة سابقة)
- `ReputationController.php`, `GoogleBusinessContentController.php`
- `PublishGbpPostJob.php`, `GbpScheduledPost.php`
- `GbpAIInsightsService.php`, `GbpSyncService.php`,
  `GbpMediaUploadHandler.php`, `GbpPhotoService.php`,
  `GbpContentService.php`, `GbpProfileService.php`
- `cron/bootstrap.php`

### جديد كليًا
- `GbpAuditLogger.php`, `GbpPhotoUploadJob.php`
- `public_html/uploads/gbp_photos/.htaccess` (**مجهّز بنفسي** - كان
  مفقود من الزip رغم ذكره في التقرير)
- `tests/Integration/GbpModuleTest.php` (نسخة محدّثة)
- 4 migrations (تفاصيل تحت)

## Migrations (4 - بالترتيب ده بالظبط)
```
2026_08_09_000042_create_gbp_module_tables.sql        ← ⚠️ راجع تحت
2026_08_11_000043_add_queue_job_id_to_gbp_scheduled_posts.sql
2026_08_11_000044_add_async_upload_status_to_gbp_photos.sql
2026_08_14_000045_create_gbp_audit_log.sql
```
⚠️ **الأولى** مطابقة حرفيًا للـmigration اللي بعتّها في أول باتش GBP -
لو شغّلتها قبل كده، تجاهلها هنا (أو شغّلها تاني، هي `CREATE TABLE IF
NOT EXISTS` فآمنة تتكرر برضه).

## ⚠️ تنبيه مهم من التقرير نفسه (صادق جدًا)
المطوّر نفسه وثّق بوضوح إن **الكود ده اتراجع بس، معملوش تنفيذ فعلي على
سيرفر PHP حقيقي أو حساب Google حقيقي خالص** (البيئة اللي كتبه فيها
معندهاش PHP/MySQL/إنترنت). بالذات **Attributes API الجديدة** (الأكتر
تعقيدًا) لسه محتاجة اختبار حقيقي مع حساب Google فعلي قبل ما تعتمد عليها
100%. اختبرها بحذر أول مرة.

## خطوات الرفع
1. باك أب قبل أي حاجة.
2. شغّل الـ4 migrations بالترتيب.
3. ارفع الملفات فوق أماكنها.
4. تأكد إن `cron/process_queue.php` مجدول (مطلوب فعليًا ينفّذ
   `PublishGbpPostJob`/`GbpBackgroundSyncJob`/`GbpPhotoUploadJob` - من
   غيره البوستات والصور هتفضل عالقة "pending" للأبد).
5. اختبر: ارفع صورة جديدة في GBP وشوف هل بتظهر "جارِ الرفع..." فورًا
   بدل ما الصفحة توقف.
6. اختبر تعديل/إلغاء بوست مجدول قبل ما ينشر.
