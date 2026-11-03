# تسليم: رفع SEO / AEO / GEO (2026-11-03)

كل التغييرات على `main` (نشر تلقائي لـ tourfecto.pro). الأرقام والحالات تحت متقاسة في التواريخ المذكورة، وأي حاجة مش مجرّبة مكتوبة صراحة في آخر القسم.

## اللي اتعمل (مختصر)
| المجال | الصفحة / المسار | الملفات الأساسية |
|---|---|---|
| لوحة الظهور الموحدة (SEO/AEO/GEO) + أثر قبل/بعد من Search Console | `/visibility`, `GET /api/visibility/overview` | `app/Services/Visibility/VisibilityScoreService.php`, `app/Controllers/VisibilityController.php`, `app/Views/Visibility/index.php` |
| فحص Schema ومعاينة | `/seo-schema` | `app/Services/SeoExpert/SeoSchemaValidator.php`, `app/Views/SeoSchema/index.php` |
| AEO: مسودة FAQ من حقائق الصفحة، فحص تغطية FAQ، نص ظاهر + كود Schema + نسخ + خطوات المنصات + تنبيه FAQ مكرر | `/aeo`, `/api/aeo/{draft,scan,build}` | `app/Services/SeoExpert/AeoAnswerBuilder.php`, `app/Controllers/AeoController.php`, `app/Views/Aeo/index.php` |
| GEO: قياس أسبوعي فعلي (ChatGPT / Gemini / Perplexity)، تتبع الأسئلة والمنافسين، سبب توقف القياس | `/geo-insights`, `GET /api/geo/insights` | `app/Services/AiVisibility/*` (`AiVisibilityService`, `AiVisibilityLedger`, `*VisibilityProvider`, `GeoInsightsService`), `app/Jobs/AiVisibilityCheckJob.php`, `app/Views/GeoInsights/index.php` |
| تقرير عميل مشترك بروابط قابلة للإلغاء | `/r/{token}` | `app/Controllers/SeoReportShareController.php`, `app/Services/Visibility/SeoReportShareService.php` |
| مراجعة عزل Tenant وحدود التكلفة | تقرير فقط + ثغرة rate-limit واحدة اتقفلت | `docs/seo-aeo-geo/PROGRESS.md` |

إصلاحات اتعملت أثناء التجربة الحية:
- **GEO**: الطلب المرفوض (400/401/403/404) بيوقف الدفعة وتكلفته صفر؛ سبب أي فشل بيتسجّل ويظهر في الصفحة؛ Gemini بقى `gemini-3.5-flash-lite`؛ Perplexity بقى على `/v1/agent`؛ أي خطأ بعد الطلب المدفوع بيتقفل بسبب `post_processing_failed`.
- **إعدادات الأدمن**: `geo_monthly_budget_usd` ماكانش مسجّل في `SystemSettingsService`، فأي حفظ للإعدادات كان بيصفّر الميزانية. اتسجّل + اختبار تراجع.
- **الاشتراك**: زر الإلغاء بقى نافذة داخل الصفحة (بدل `confirm()` اللي بيتلغي في بعض المتصفحات) + خيار إلغاء فوري نهائي يسمح بإنشاء باقة جديدة + `verifyCsrf()`.
- **رابط التقرير الملغي**: بيرد 410 بصفحة شرح بدل 404 بتاع nginx.
- **ReviewRequestController::aiAssist**: أضفنا `rateLimitGuard` (30/ساعة لكل مستخدم).

## Migrations (SQL بيتطبّق يدويًا في phpMyAdmin)
| الملف | الحالة |
|---|---|
| `database/migrations/2026_11_02_000001_seo_aeo_pages.sql` | اتطبّق (الفحص خزّن 74 صفحة رحلة) |
| `database/migrations/2026_11_02_000002_seo_report_shares.sql` | اتطبّق (الروابط المشتركة اشتغلت) |

مفيش migration جديد من بعد كده. جدول `seo_ai_calls` وباقي جداول GEO بتتعمل لوحدها (`AiVisibilitySchema::ensureSchema`).

## خطوات النشر والتشغيل
1. النشر تلقائي من `main`. مفيش خطوة يدوية للكود.
2. إعدادات الأدمن (صفحة الإعدادات، قسم قياس ظهور AI): القياس العام **مفعّل**، الميزانية الشهرية **5 دولار**، ChatGPT وGemini وPerplexity **مفعّلين**، والمفاتيح محفوظة. بعد أي حفظ في الصفحة راجع خانة الميزانية (العيب اتصلّح، بس أول مرة بعد النشر اتأكد).
3. الكرون: سطر الـscheduler الواحد على السيرفر (`/www/wwwroot/tourfectoai.pro`) بيشغّل `cron/seo_ai_visibility.php` أسبوعيًا (الاثنين 06:00 حسب توقيت السيرفر) والتنبيهات (الاثنين 12:00). لو عايز تشغّله يدويًا: `cd /www/wwwroot/tourfectoai.pro && sudo -u www /www/server/php/84/bin/php cron/seo_ai_visibility.php`.
4. فحص قياس تم: بعد التشغيل، الطابور بيمشي فحص في الدقيقة. شوف `/geo-insights`.
5. لو فحص فشل وعايز تعيده في نفس الأسبوع: الفحص بيتحجز أسبوعيًا لكل (موقع، سؤال، مزوّد). امسح الصف الفاشل من `seo_ai_calls` (الأمر اللي استخدمناه: `DELETE ... WHERE a.id IS NULL AND status IN (...) AND week_start = ...`) وشغّل الكرون.

## قياس GEO الحالي (موقع 33، أسبوع 2026-11-02)
- 41 إجابة: ChatGPT 20، Gemini 18، Perplexity 3 (والباقي لسه في الطابور وقت آخر قراءة). الثقة "متوسطة".
- ظهور البراند: **0%** في كل المزوّدين. ده نتيجة قياس مش عطل. المنافسين الأكتر ظهورًا في الإجابات: tourguidesegypt.com, nilecruisehub.com, egypttraveler.org.
- الأرقام عينات API مش ترتيب تطبيقات المستهلك؛ تابع الاتجاه مش إجابة واحدة.

## اللي مش شغال أو مش مفسَّر
- **فحصان Gemini بحالة `unknown` بدون سبب مسجّل.** ما قدرتش أحدد السبب من الكود. اللي يحسمه: سجل أخطاء PHP على السيرفر (ابحث عن `GEO`).
- **Perplexity**: اشتغل بعد شراء رصيد مدفوع فعلي (الرصيد الترحيبي بيرجّع 403). الكود الجديد على `/v1/agent` اتجرّب على السيرفر بـ3 فحوص ناجحة، لكن ما اتأكدتش من التكلفة المسجّلة لكل فحص.
- **Gemini**: تقدير تكلفة الـgrounding متحفظ (0.035 لكل طلب) مش الرقم الرسمي.

## اللي ما اتجرّبش
- استخراج AEO على صفحة **عربي** حقيقية (الموقع كله إنجليزي).
- مولّدات Hotel وHowTo، وتنفيذ إجراء بضغطة من اللوحة.
- تقرير المشاركة `/r/{token}` على موبايل حقيقي (اتجرّب على عرض موبايل في المتصفح بس).
- صفحة AEO على موبايل حقيقي وفي الوضع الداكن، وحالة صفحة من غير FAQPage (المفروض التحذير ما يظهرش).
- نسخ الحافظة المباشر (متصفح الاختبار منعه؛ البديل اشتغل).
- اختبارات اختراق، ومراجعة الـServices اللي بتتنادى من Jobs/Webhooks، وتشغيل PHPStan الواسع.
- آخر تشغيل كامل للاختبارات اتسجّل في 2026-11-02: 3137 اختبار و4 إخفاقات قديمة في `ReputationModuleIntegrationTest` (من ميزة الباقات). لم أعد التشغيل الكامل بعد تعديلات 2026-11-03؛ اتشغّلت اختبارات `SystemSettingsGeoKeysTest` و`AeoAnswerBuilderTest` بس.

## قرارات مفتوحة عليك
- **زر نشر مباشر على WordPress**: مش مبني عمدًا. المشتركين على منصات مختلفة، فالمسار الحالي نسخ يدوي لأي منصة. النشر المباشر يحتاج ربط صفحة بمقال معيّن ومعاينة فرق وموافقة قبل ما نكتب فوق محتوى.
- **قفل الباقات على السيرفر**: الحجب حاليًا في القوائم بس (مثلًا `/chat` بيفتح على باقة SEO Essential).
- **خصم 0.50 دولار إضافي من المحفظة** ظهر مرة (9700 ← 9600.50 مقابل باقة 99): لسه ما اتحقّقش.
- **زر إلغاء الاشتراك في الإعدادات ← الفواتير** لسه بيستخدم `confirm()` القديم (صفحة الاشتراك الجديدة هي اللي اتصلّحت).
- **CLAUDE.md**: سطر "النشر على السيرفر يدوي" لسه زي ما هو، مع إن tourfecto.pro بيتنشر تلقائي من `main` من 2026-10-31.
