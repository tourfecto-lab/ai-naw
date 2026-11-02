# جرد SEO / AEO / GEO — 2026-11-02 (الفرع: seo-aeo-geo-pro)

الجرد سريع على مستوى الهيكل (أحجام الملفات، المسارات، الـgrep، `php -l`)، ولم يتضمن تشغيل الواجهة أو الاختبارات بعد. أي بند "غير مؤكد" يتحقق منه في مرحلته.

## الموجود
- **SEO**: زاحف، فحوص تقنية (`AuditChecksService`)، PageSpeed، تتبع ترتيب عبر DataForSEO مع ميزانية يومية وكاش (`SeoDailyBudget`, `SeoApiCache`, `SeoSerpCostSaving`)، فجوة كلمات، Content Decay/Brief/Calendar، A/B، تقارير شهرية ومجدولة، تنبيهات ترافيك، Search Console API، IndexNow + Google/Baidu Indexing، SeoExpert (مهام، سياسات صفحات، تحقق)، Auto SEO (embed.js)، SeoImpact (قبل/بعد، حجز ومنتجات المنصة)، SeoStrategy.
- **Schema**: `SeoSchemaBuilder` (394 سطر): TouristTrip, TouristAttraction/Destination, Product/Offer/AggregateOffer, BreadcrumbList, FAQPage, Organization/LocalBusiness/TravelAgency, AggregateRating، مع قائمة أنواع مسموحة (`typesAllowed`).
- **GEO**: `AiVisibilityService` + مزودون معزولون (OpenAI, Gemini, Claude, Sonar)، أسئلة مولّدة محليًا بـ14 لغة، كاشف ذِكر (`Detector`)، Evidence، Ledger، WalletGuard، تنبيهات (`AiVisibilityAlertService`)، مسودة `llms.txt`، cron يومي + تنبيهات.
- **cron**: 11 مهمة SEO/GEO في `cron/`.
- `php -l` على كل ملفات النطاق (Services + Controllers): بلا أخطاء.

## الناقص (بترتيب الأولوية)
1. **لا توجد لوحة "ظهور" موحّدة**: لا يوجد SEO/AEO/GEO score مجمّع ولا "أهم 5 إجراءات بالأثر". الدرجات متفرقة (`SeoPerformanceService`, `SeoChartService`).
2. **AEO غير موجود كوحدة**: الـgrep لا يجد خدمة AEO. `HowTo` مذكور في `WebsiteOptimizerController` فقط؛ لا مولّد صفحات إجابات من بيانات النشاط، ولا قياس استحواذ على الإجابات/featured snippet.
3. **Schema**: ينقص Hotel/LodgingBusiness وReview وHowTo وVideo/Event في القائمة المسموحة؛ لا معاينة + validation واجهة قبل النشر (غير مؤكد، يتحقق منه).
4. **GEO**: ينقص رسم تطور زمني واضح، تتبع citations ومصادرها كاملًا، مقارنة منافسين، وربط توصية إصلاح بكل prompt (غير مؤكد في الواجهة).
5. **تصحيح**: `tf-geo.js` و`admin-geo.js` هما بيانات خريطة جغرافية (ليسا ميزة GEO). واجهة GEO الفعلية = `Views/AiVisibility/index.php` (21KB) + `ai-visibility.js` (8.5KB) بأسطر كثيفة، فتقييم حجمها بعدد الأسطر كان مضللًا؛ تُراجع بالتجربة.
6. **تقارير العميل**: لا مكتبة PDF ولا رابط مشاركة عام (لا `share_token`/dompdf/mpdf في app).
7. **SeoImpact مع Search Console**: يتحقق من عمق الربط الفعلي.
8. **Tenant isolation**: يوجد ملفا اختبار عزل فقط على مستوى المشروع؛ لا اختبار عزل مخصص لوحدات SEO/GEO (يتحقق).

## المكسور / للتحقق
- لم يُكتشف كسر عبر الفحص الساكن. الفحص الحقيقي (تشغيل الشاشات لوكال + console/network + PHPUnit للنطاق) هو أول خطوة في المرحلة 1.
- `WebsiteOptimizerController` (1877 سطر) و`AutoSeoController` (1169) ضخمان: مرشحان لفصل المنطق إلى Services قبل إضافة ميزات.

## ملاحظات تشغيل
- التوثيق القديم (`PENDING_FOR_OWNER_AR.md` بند 7) يقول إن النشر التلقائي على tourfectoai.pro وإن tourfecto.pro يدوي؛ تعليماتك الحالية تقول العكس. لم أعدّله — أحتاج تأكيدك أولًا.
- أي migration جديد سيُكتب كملف فقط، والكود يتصرف بأمان بدونه.

## نتيجة الفحص اللوكال (2026-11-02)
- PHPUnit `--filter 'Seo|AiVisibility|Indexing|Schema'`: 144 اختبارًا، 478 assertion، كلها ناجحة (32 تحذير legacy معروف).
- فحص الشاشات على tourfecto.pro **لم يتم**: لا توجد جلسة مسجّلة متاحة لي (المتصفح المدمج غير مسجّل، وإضافة Chrome غير متصلة).
