# تعليمات التركيب — موديول Google Business Profile (Round 8 - Professional Finalization)

## ⚠️ اقرأ الأول
1. `GBP_PRODUCTION_FINAL.md` — التقرير النهائي الكامل (Feature Table، Critical/High/Medium/Low، Definition of Done checklist، Open Items)
2. `PRODUCTION_READINESS_REPORT.md` — تقرير Round 7
3. `CHANGELOG.md` — سجل كل التعديلات من Round 1 لحد Round 7

**مهم جدًا:** الكود ده اتكتب واتراجع عبر 8 جولات، لكن **ولا سطر كود اتشغّل فعليًا** على PHP/MySQL/Google حقيقي — البيئة اللي اتكتب فيها الكود دي معندهاش PHP interpreter ولا MySQL ولا اتصال إنترنت لـ Google APIs. أي حد/أداة هتكمل الشغل لازم:
- تشغّل `php -l` على كل ملف PHP قبل ما تعتمد عليه
- تطبّق الـ migrations على قاعدة بيانات حقيقية وتتأكد من نجاحها
- تعمل OAuth حقيقي مع حساب Google Business Profile تجريبي (خصوصًا Attributes API اللي اتصلحت في Round 7 - لسه معندهاش أي تأكيد حي)

## هيكل المشروع
المشروع الأساسي اسمه **Tourfecto** - منصة SaaS Multi-tenant لإدارة الأعمال والتسويق الرقمي، مبني بـ:
- PHP خام (مفيش Laravel/Symfony) - نمط MVC بسيط، Controllers بترجع HTML مباشر (heredoc) للصفحات
- MySQL/PDO
- Queue: جدول `jobs` في قاعدة البيانات، بيتنفذ عن طريق `cron/process_queue.php` (بينده عليه Cron Job حقيقي كل دقيقة تقريبًا)
- الموديول ده (GBP) جزء واحد بس من مشروع أكبر فيه موديولات تانية (CRM, Ads, Competitor Intelligence, Revenue Intelligence, OTA, Social Media...) - **ركّز على ملفات GBP بس، الملفات التانية دي سياق فقط**

## 1) ملفات جديدة كليًا (مش موجودة في المشروع الأصلي - انسخها زي ما هي)
```
app/Controllers/GbpProfileController.php
app/Services/GoogleBusiness/GbpSetupStatusService.php
app/Services/GoogleBusiness/GbpSyncService.php
app/Services/GoogleBusiness/GbpProfileService.php
app/Services/GoogleBusiness/GbpPhotoService.php
app/Services/GoogleBusiness/GbpMediaUploadHandler.php
app/Services/GoogleBusiness/GbpInsightsService.php
app/Services/GoogleBusiness/GbpAIInsightsService.php
app/Services/GoogleBusiness/GbpAuditLogger.php
app/Services/GoogleBusiness/GbpHealthCheckService.php   ← جديد في Round 8
app/Jobs/GbpBackgroundSyncJob.php
app/Jobs/GbpPhotoUploadJob.php
cron/gbp_enqueue_background_sync.php
tests/Integration/GoogleLiveTest.php                    ← جديد في Round 8 (Live Test Harness)
```

## 2) ملفات موجودة في المشروع الأصلي - استبدلها بالكامل بالنسخة دي
```
app/Services/Reputation/GoogleBusinessAPI.php        ← تعديلات مهمة (Retry Policy + Token Race Fix + Attributes API + Error Classification)
app/Services/Reputation/GoogleReviewSyncService.php  ← Token Refresh Race Condition Fix (مهم!)
app/Services/GoogleBusiness/GbpContentService.php
app/Controllers/GoogleBusinessContentController.php
app/Controllers/ReputationController.php
app/Models/GbpScheduledPost.php
app/Jobs/PublishGbpPostJob.php                        ← Idempotency Fix (مهم!)
app/routes/api.php                                    ← Health endpoint + AI credits middleware
public_html/index.php                                 ← classmap محدّث
cron/bootstrap.php                                     ← classmap محدّث
```

⚠️ **مهم**: `app/routes/api.php`, `public_html/index.php`, `cron/bootstrap.php` هما نسخة **مدموجة** فوق باقي موديولات المشروع (OTA/CRM/Competitor Intelligence/Revenue Intelligence...). لو حصل أي تعديل تاني على الملفات دي بعد آخر نسخة اتبعتت هنا، **لازم تعمل diff/merge يدوي** قبل الاستبدال المباشر عشان متضيعش أي تعديل حديث.

## 3) قاعدة البيانات (4 migrations - شغّلهم بالترتيب ده بالظبط، كلهم إضافيين بس)
```
database/migrations/2026_08_09_000042_create_gbp_module_tables.sql
database/migrations/2026_08_11_000043_add_queue_job_id_to_gbp_scheduled_posts.sql
database/migrations/2026_08_11_000044_add_async_upload_status_to_gbp_photos.sql
database/migrations/2026_08_14_000045_create_gbp_audit_log.sql
```

## 4) Routes الجديدة المضافة (للمرجع - مسجّلة بالفعل في api.php المرفق)
```
GET    /api/gbp/status
POST   /api/gbp/sync/{website_id}
GET    /api/gbp/profile
POST   /api/gbp/profile
GET    /api/gbp/attributes
POST   /api/gbp/attributes
GET    /api/gbp/photos
POST   /api/gbp/photos
DELETE /api/gbp/photos/{id}
POST   /api/gbp/photos/{id}/primary
GET    /api/gbp/insights
GET    /api/gbp/ai-insights
GET    /api/gbp/recommendations
GET    /api/gbp/health          ← جديد Round 8 (Admin only)
PUT    /api/gbp/content/{id}
DELETE /api/gbp/content/{id}
POST   /api/gbp/content/{id}/schedule/{scheduleId}/cancel
```

## 5) Environment Variables
لا يوجد جديد إلزامي. يعيد استخدام: `GOOGLE_MAPS_API_KEY`, `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `GOOGLE_OAUTH_REDIRECT_URI`, `GEMINI_API_KEY` (كلها موجودة بالفعل في المشروع).

اختياري (لتشغيل Live Google Test Harness بس):
```
GBP_LIVE_TEST=true
GBP_LIVE_TEST_WEBSITE_ID=<website_id عنده اتصال Google Business حقيقي>
GBP_LIVE_TEST_USER_ID=<user_id بتاع نفس الموقع>
GBP_LIVE_TEST_ALLOW_WRITES=true   (اختياري إضافي - لسه مش مكتمل التنفيذ، شوف الملف)
```

## 6) خطوات التركيب الموصى بيها
1. Backup كامل (كود + قاعدة بيانات)
2. طبّق الـ 4 migrations بالترتيب
3. انسخ الملفات الجديدة
4. استبدل/ادمج الملفات الموجودة (راجع الملاحظة في بند 2)
5. تأكد إن `cron/process_queue.php` مضبوط كـ Cron Job فعلي (كل دقيقة تقريبًا) - من غيره الصور/المنشورات/المزامنة الخلفية هتفضل عالقة
6. شغّل `php -l` على كل ملف اتعدل
7. افتح `/gbp-content` في متصفح حقيقي
8. اعمل ربط Google OAuth حقيقي وجرّب:
   - Setup Wizard / Connection Center
   - Profile + Attributes (الأولوية القصوى - أول تأكيد حي)
   - Photos
   - Posts
   - Insights
9. شغّل `php tests/Integration/GbpModuleTest.php` (اختبارات بدون Google حقيقي)
10. شغّل `php tests/Integration/GoogleLiveTest.php` (بعد ضبط الـ env vars في بند 5) لو متاح حساب Google تجريبي
11. راجع `GET /api/gbp/health` (Admin) للتأكد من حالة النظام العامة

## 7) الأولوية القصوى لو هتكمل التطوير
راجع قسم **"Open Items"** في `GBP_PRODUCTION_FINAL.md` - فيه تصنيف واضح لإيه اللي محتاج:
- **LIVE GOOGLE TEST REQUIRED** (Attributes API خصوصًا - أعلى مخاطرة حاليًا لأنها اتعملها Rewrite كامل بناءً على توثيق بس)
- **كود لسه محتاج يتكتب** (EVENT/OFFER الحقيقي، AI Credit atomic deduction، Photo/Post reconciliation مع Google)
- **مراجعة إضافية** (Database indexes، Rate limiting مخصص، اختبارات UI في متصفح حقيقي)

## 8) قاعدة أساسية لو AI/مطور هيكمل الشغل
**متعتبرش أي جزء "خلص" لمجرد إنه Static Review نجح.** المشروع ده فيه تاريخ موثّق (في CHANGELOG.md) من أخطاء حقيقية اتلقطت بس لما حد راجع الكود بعناية أو حاول يشغّله فعليًا - زي مسارات API غلط كانت هتفشل 404 فورًا، وحقل اسمه غلط في طلب Google. الافتراض الافتراضي الصح هو "لسه محتاج تأكيد حي"، مش "شغال أكيد".
