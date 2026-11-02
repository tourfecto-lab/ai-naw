# PROGRESS_SEO — خدمة SEO / AEO / GEO

الفرع: `seo-aeo-geo-pro` (من `main`). الذاكرة بين الجلسات: ابدأ من هنا ولا تعيد القراءة.
مصدر الـ audit: فحص للكود بتاريخ 2026-11-02 (بعضه بالـ grep فقط، معلّم).

## 1) الموجود فعلاً

| القدرة | الحالة | الدليل |
|---|---|---|
| زحف بـ JS rendering | ناقص | `Seo/SeoCrawlerService.php:15` HTML فقط، 25 رابط |
| Core Web Vitals حقيقية | شغال | `Seo/PageSpeedService.php` (Lighthouse + CrUX) — لم أتحقق من سطح الواجهة |
| تحسين الصور | جزئي | `ImageOptimizationService.php` WebP فقط، لا AVIF/srcset |
| Sitemap ديناميكي من الزحف | جزئي | `cron/seo_sitemap_sync.php`، لا توليد من نتائج الزحف |
| تصدير التدقيق CSV/API | ناقص | JSON فقط في `WebsiteOptimizerController` |
| Cannibalization | ناقص | لا كاشف؛ `SeoKeywordConflictReview` داخل الخطة فقط |
| اقتراحات روابط داخلية | جزئي | داخل توليد المحتوى فقط |
| Schema validator | جزئي | `SeoSchemaBuilder` يبني، لا مدقق |
| Hreflang audit | جزئي | `HreflangService` (بناء/تحقق خريطة)، لا فحص تبادلي بالزحف |
| تقرير عميل قابل للمشاركة | ناقص | `SeoMonthlyReportService` داخلي/إيميل فقط |
| Featured Snippet / PAA | ناقص | لا كاشف SERP features |
| مولّد FAQ/HowTo/QAPage + تحقق | جزئي | نصوص فقط بلا مولّد/مدقق |
| Question coverage | ناقص | — |
| إجابات مباشرة 40-60 كلمة | جزئي | في البرومبت، بلا تحقق |
| Share of voice تاريخي | شغال | `AiVisibility/AiVisibilityMetrics.php`, `cron/seo_ai_visibility.php` |
| مقارنة منافسين GEO | جزئي | تعدّ المنافسين فقط عند غياب البراند |
| تحليل مصادر الاقتباس | جزئي | عدّ فقط؛ واجهة حسب الدومين غير مؤكدة |
| llms.txt / llms-full.txt | جزئي | `GeoLlmsBuilder.php` (8 رحلات، 19000 حرف)، لا full ولا مدقق |
| Entity readiness | جزئي | لا فحص sameAs/Organization/Offer |
| Prompts مراقبة لكل عميل | شغال | `api.php:2064-2065`, `AiVisibilityQuestions` |
| تنبيه فقد اقتباس | ناقص | ميزانية فقط |

## حلقة التنفيذ
- اكتشاف ← توصية ← تنفيذ ← أثر ← تقرير موجودة لـ SeoExpert.
- مقطوعة: إصلاحات AutoSeo/WebsiteOptimizer لا تغذّي `SeoImpactService::recordAppliedTask` (لم يُتتبع بالكامل). `SeoImpactService::ready()` يحتاج 5 جداول من هجرة Phase 4 (تأكد أنها اتشغلت).
- لا ارتجاع من نتائج GEO إلى توصيات/تنبيهات.

## 2) الفجوات بالأولوية (أثر × جهد)
1. تنبيه فقد اقتباس / هبوط SoV
2. تقرير عميل قابل للمشاركة (ar/en، رابط بتوكن)
3. تصدير التدقيق CSV/JSON
4. ربط AutoSeo/Optimizer بـ SeoImpact
5. Schema validator + مولّد FAQ/HowTo/QAPage
6. Cannibalization + شبكة الروابط الداخلية
7. اختبارات للنواة غير المختبرة (`GeoLlmsBuilder`, `AiVisibilityMetrics`, `SeoImpactService`, `SeoCrawlerService`)
8. JS-rendered crawl (جهد عالٍ، مؤجل)
لاحقاً: AVIF/srcset، llms-full.txt + مدقق، entity readiness، PAA (يحتاج DataForSEO، تكلفة).

## 3) سجل التنفيذ
### الدفعة 1 — تنبيه فقد اقتباس / هبوط ظهور AI (فجوة 1)
- `AiVisibilityAlertService` يقارن آخر أسبوعين مقاسين لكل مزوّد (حد أدنى 5 أسئلة بالأسبوع، لا تنبيه على عينات ناقصة): اختفاء الذكر، هبوط ≥30 نقطة مئوية، فقد الاقتباس. إشعار للعميل مرة لكل أسبوع/سوق (cache)، نص صادق إن القياس عينة API.
- الملفات: `app/Services/AiVisibility/AiVisibilityAlertService.php`، `cron/seo_ai_visibility_alerts.php`، `cron/schedule.php` (+مدخل)، `app/Config/project_classmap.php` (+سطر يدوي؛ لا تشغّل `build_project_classmap.php` لأنه يعيد كتابة الملف كله ويغيّر 600+ سطر)، `tests/Unit/AiVisibilityAlertServiceTest.php`.
- الاختبار: `php -l` نضيف؛ الاختبار الجديد 4/4 بـ `phpunit --no-configuration`. **لم أقدر أشغّل bootstrap الاختبارات** (الاتصال بـ MariaDB المحلي رُفض 1045 لأن كلمة السر تأتي من `.env` ولم ألمسه)، فـ ClassmapTargetsExist/RouteHandlersExist ما اتشغلوش.
- لم يُجرَّب على الموقع الحي (الكرون محتاج رفع). لا UI جديدة: الإشعار يظهر في مركز الإشعارات الحالي، وبيرابط `/ai-visibility`.
- الباقي: عرض سجل التنبيهات داخل صفحة ظهور AI، إشعار بريد.

## 4) جاهز للرفع (الدفعة 1)
- ملفات جديدة: `app/Services/AiVisibility/AiVisibilityAlertService.php`، `cron/seo_ai_visibility_alerts.php`
- ملفات معدّلة: `cron/schedule.php`، `app/Config/project_classmap.php` (سطر `AiVisibilityAlertService`)
- هجرة SQL: لا. ملفات تتمسح: لا.
- cron: `0 12 * * 1 php cron/seo_ai_visibility_alerts.php` (لو الجدولة المركزية `cron/schedule.php` شغالة عندك فهو متضاف تلقائياً).

## 5) استدعاءات API مدفوعة في التجارب
0
