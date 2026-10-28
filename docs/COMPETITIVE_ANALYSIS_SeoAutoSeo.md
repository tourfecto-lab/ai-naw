# التحليل التنافسي — SEO/AutoSeo (Tourfecto) — 2026-08-29

مقارنة منهجية لموديول SEO/AutoSeo المدمج داخل منصة Tourfecto (تدقيق موقع + محتوى
LLM + تنفيذ تلقائي Server-Side + فهرسة فورية + تجارب SEO A/B) ضد أبرز الحلول
العالمية: Ahrefs Site Audit، SEMrush On-Page & Site Audit، SurferSEO، Screaming
Frog SEO Spider، وCloudflare (HTML Rewriter / Transform Rules).

> **القيد المنهجي:** التحليل معتمد على المعرفة العامة بالمواصفات العامة الموثّقة
> للمنافسين + مخزون الميزات الفعلي للموديول (تم مسحه من الكود مباشرة في هذه
> الجلسة: Services/Controllers/Jobs/Migrations/Routes/Cron). كل ادعاء عن الموديول
> مدعوم بمسار ملف، ولا يوجد أي ادعاء بميزة غير موجودة في الكود. الميزات المعتمدة
> على الذكاء الاصطناعي (توليد المحتوى/الاستراتيجية) مُعلَّمة 🔶 لأنها تعتمد على
> رصيد LLM القائم على Credits الخاص بالمنصة.

---

## 1. نطاق المقارنة

| البُعد | المنافسون | الموديول الحالي |
|---|---|---|
| السوق المستهدف | عالمي (إنجليزي أولًا) | عربي أولًا (ar/en + 10 لغات للمحتوى)، مدمج في منصة سياحة/وكالات سفر |
| نموذج النشر | SaaS سحابي / عميل محلي (Screaming Frog) | مدمج (Embedded) داخل منصة Tourfecto متعددة الموديولات |
| طريقة العمل | تحليل/تقرير فقط (Ahrefs/Semrush/Surfer/SF) + إعادة كتابة HTML (Cloudflare فقط) | تحليل + **تنفيذ فعلي** على المواقع الخارجية (embed.js + بروكسي Server-Side) |
| النطاق المفهرس | كل المواقع المعيارية | مواقع العملاء المربوطة + مواقع Website Builder الخاصة بالمنصة |
| المصدر | مغلق | مفتوح المصدر داخل المستودع، بدون Composer/تبعيات خارجية (PHP نقي + PDO) |
| الثمن | اشتراك شهري مرتفع | مجاني ضمن اشتراك المنصة (توليد المحتوى يستهلك Credits) |

---

## 2. ملخص وضع الموديول لكل منطقة ميزات

الأسطورة: ✅ موجود · 🔶 جزئي/محدود · ❌ غير موجود

### 2.1 تدقيق الموقع (Site Audit)

| الميزة | Ahrefs | SEMrush | Surfer | Screaming Frog | Cloudflare | الموديول |
|---|---|---|---|---|---|---|
| فحص on-page حقيقي من HTML + Headers | ✅ | ✅ | 🔶 | ✅ | 🔶 | ✅ (`WebsiteOptimizerController::performAudit` + `AuditChecksService`) |
| تعدد فئات الفحص (SEO/Speed/Security/Mobile/Accessibility/AEO/GEO) | ✅ | ✅ | 🔶 | ✅ | 🔶 | ✅ (7 فئات في `AuditChecksService::run`) |
| **زحف كامل للموقع (Multi-page crawl)** | ✅ | ✅ | ❌ | ✅ | ❌ | ✅ (`SeoCrawlerService` — BFS للروابط الداخلية مع فحص on-page لكل URL وتجميع مقاييس الموقع، مخزّن في `seo_crawl_pages`) |
| تحليل JSON-LD وسلامته (عدّ البلوكات غير الصالحة + استخراج الأنواع) | ✅ | ✅ | 🔶 | ✅ | ❌ | ✅ (`extractJsonLd` + `collectSchemaTypes` + فحص `structured_data_valid`) |
| Score إجمالي + Scores لكل فئة | ✅ | ✅ | 🔶 | ✅ | ❌ | ✅ (`calculateScore` + `calculateCategoryScores`) |
| فحص فجوات الكلمات المفتاحية مقابل المنافسين (Keyword Gap) | ✅ | ✅ | 🔶 | 🔶 | ❌ | 🔶 (موجود في `SeoStrategyService::fetchKeywordGaps` لكن كمدخل للخطة فقط، بلا تقرير مستقل) |
| **فحص Web Vitals / CWV حقيقي (CrUX/Lighthouse)** | ✅ | ✅ | ✅ | ✅ | 🔶 | ❌ (heuristics فقط: ضغط/كاش/render-blocking/CDN — `checkSpeed`) |
| **تنفيذ JavaScript في الزحف (JS Rendering)** | ✅ | ✅ | 🔶 | 🔶 | ❌ | ❌ (فحص تقريبي `js_render_risk` فقط) |
| فحص تخصصي لروبوتات AI / GEO (llms.txt, GPTBot/ClaudeBot/PerplexityBot, OAI-SearchBot, citations, sameAs) | 🔶 | 🔶 | ❌ | ❌ | ❌ | ✅ (`checkGeo` + `checkGeoAdvanced` — ميزة نادرة) |

### 2.2 إنتاج المحتوى (Content)

| الميزة | Ahrefs | SEMrush | Surfer | Screaming Frog | Cloudflare | الموديول |
|---|---|---|---|---|---|---|
| توليد مقالات LLM جاهزة (title/meta/content/slug) | ❌ | 🔶 (Writing Assistant) | 🔶 | ❌ | ❌ | 🔶 (`ArticleGenerator` — يعتمد على رصيد LLM) |
| اكتشاف مواضيع من كلمات متابعة + استعلامات GSC | 🔶 | ✅ | 🔶 | ❌ | ❌ | ✅ (`SeoContentService::discoverTopics` — مصدر keywords/gsc/manual) |
| إدارة حملات محتوى بخط أنابيب (queued→generated→indexed→testing→published) | ❌ | 🔶 | ❌ | ❌ | ❌ | ✅ (`seo_content_campaigns` + `seo_content_items`) |
| اقتراح FAQ + Schema جاهز مع المقال | ❌ | 🔶 | 🔶 | ❌ | ❌ | ✅ (`ArticleGenerator::buildFaqSchema`) |
| **حلقة مغلقة: توليد → فهرسة → A/B → قياس CTR → تطبيق الفائز (Cron)** | ❌ | ❌ | ❌ | ❌ | ❌ | ✅ (`SeoContentService::runEngineCycle` + `cron/seo_content_engine.php`) |
| **نشر تلقائي للمقال على مواقع عملاء خارجية** | ❌ | ❌ | 🔶 | ❌ | ❌ | 🔶 (يُحفظ في `ai_articles` فقط؛ `ArticleGenerator` يوثّق صراحة "جاهز للنسخ/التنزيل" للمواقع الخارجية) |
| تحسين محتوى مقابل بيانات SERP/كلمات (نقاط محتوى تفصيلية) | 🔶 | ✅ | ✅ | ❌ | ❌ | ❌ |

### 2.3 التطبيق التلقائي / البروكسي (Auto-Apply & Edge)

| الميزة | Ahrefs | SEMrush | Surfer | Screaming Frog | Cloudflare | الموديول |
|---|---|---|---|---|---|---|
| **تنفيذ إصلاحات تلقائيًا على موقع خارجي دون لمس استضافته** | ❌ | ❌ | ❌ | ❌ | 🔶 | ✅ (embed.js + `AutoSeoEmbedService`) |
| **إعادة كتابة HTML Server-Side قبل إرجاعها للروبوت/الزائر** | ❌ | ❌ | ❌ | ❌ | ✅ (HTML Rewriter) | ✅ (`SeoProxyService::rewrite` + `SeoProxyController::serve`) |
| حقول قابلة للحقن (title/desc/canonical/viewport/og/JSON-LD/FAQ/speakable/alt/lazy) | ❌ | ❌ | ❌ | ❌ | 🔶 | ✅ (`AutoSeoEmbedService::INJECTABLE_FIELDS` + `SeoProxyService::applyRewrite`) |
| حقول تُخدم Server-Side فقط (robots/llms/sitemap/WebP/hreflang) | ❌ | ❌ | ❌ | ❌ | 🔶 | ✅ (`SERVER_SIDE_FIELDS` + `SeoProxyService::serveAuxFile`) |
| وضع CNAME حقيقي (العميل يوجّه DNS إلى سيرفرنا) | ❌ | ❌ | ❌ | ❌ | ✅ | ✅ (`SeoProxyService::findByHost` + `serveCname`) |
| أوضاع سرعة/جرأة للتطبيق (conservative/balanced/aggressive) | ❌ | ❌ | ❌ | ❌ | ❌ | ✅ (`AutoSeoEmbedService::MODE_SEVERITIES`) |
| معاينة قبل التطبيق (before/after دون كتابة DB) | ❌ | ❌ | ❌ | ❌ | 🔶 | ✅ (`SeoProxyService::previewChanges`) |
| Rollback + سجل تغييرات كامل (old/new + trigger + mode) | ❌ | ❌ | ❌ | ❌ | 🔶 | ✅ (`auto_seo_change_log` + `AutoSeoEmbedService::rollback`) |
| حماية SSRF على الجلب الخارجي + Rate Limit للـ Proxy العام | ❌ | ❌ | ❌ | ❌ | ✅ | ✅ (`SsrfGuard::validateUrl` + `isRateLimited` في `SeoProxyController`) |

### 2.4 المخطط البنيوي (Schema / Canonical / Hreflang)

| الميزة | Ahrefs | SEMrush | Surfer | Screaming Frog | Cloudflare | الموديول |
|---|---|---|---|---|---|---|
| حقن JSON-LD (Organization/FAQPage/Speakable) | 🔶 (تقرير) | 🔶 | 🔶 | 🔶 | 🔶 | ✅ (`applyRewrite` لحقول json_ld/faq_schema/speakable + `normalizeJsonLd`) |
| Canonical: فحص + توليد + حقن | ✅ | ✅ | 🔶 | ✅ | 🔶 | ✅ (فحص `canonical_self` + إصلاح + حقن) |
| Hreflang: فحص | ✅ | ✅ | 🔶 | ✅ | ❌ | ✅ (فحص `hreflang` في `checkSeo`) |
| **Hreflang: توليد/حقن تلقائي متعدد اللغات** | ❌ | 🔶 | ❌ | ❌ | ❌ | 🔶 (`applyHreflang` يولّد وسوم ببنية `/{lang}{path}` افتراضية + x-default — لا يكتشف بنية اللغة الفعلية للموقع) |
| Open Graph وسوم (فحص + حقن مُنقّى) | 🔶 | 🔶 | 🔶 | ✅ | ❌ | ✅ (`og_tags` بفلترة `strip_tags('<meta><link>')` + إزالة معالجات الأحداث) |
| Speakable / بيانات القراءة الصوتية | ❌ | ❌ | ❌ | ❌ | ❌ | ✅ (فحص + حقن) |

### 2.5 ملفات الإرشاد (robots / sitemap / llms.txt)

| الميزة | Ahrefs | SEMrush | Surfer | Screaming Frog | Cloudflare | الموديول |
|---|---|---|---|---|---|---|
| فحص robots.txt (وجود + إشارة Sitemap + حظر روبوتات AI) | ✅ | ✅ | 🔶 | ✅ | ❌ | ✅ (`robots_txt` + `sitemap_robots_ref` + `ai_bot_sections`) |
| توليد/تحرير robots.txt وخدمته Server-Side | ❌ | ❌ | ❌ | ❌ | 🔶 | ✅ (`serveAuxFile` لحقل `robots_txt`) |
| فحص صلاحية sitemap.xml (تحميل + عدّ `<loc>`) | ✅ | ✅ | 🔶 | ✅ | ❌ | ✅ (`sitemap_status` في `checkSeo`) |
| **مولّد sitemap ديناميكي من نتائج الزحف** | ✅ | ✅ | ❌ | ✅ | ❌ | 🔶 (إصلاح sitemap قالب ثابت + خدمة Server-Side، لا يُبنى من الصفحات المكتشفة) |
| llms.txt / llms-full.txt (فحص + توليد + خدمة) | ❌ | ❌ | ❌ | ❌ | ❌ | ✅ (`llms_txt` فحص/توليد + `checkGeo::llms_full_txt` + `serveAuxFile`) |

### 2.6 الفهرسة (Indexing)

| الميزة | Ahrefs | SEMrush | Surfer | Screaming Frog | Cloudflare | الموديول |
|---|---|---|---|---|---|---|
| **IndexNow (فهرسة فورية لـ Bing/Yandex/Seznam/Naver)** | ❌ | 🔶 | ❌ | ❌ | ❌ | ✅ (`IndexNowService` + `SeoIndexingController` + توليد مفتاح تلقائي عند الربط) |
| إرسال تلقائي للفهرسة بعد كل تطبيق إصلاح/تدقيق/محتوى | ❌ | ❌ | ❌ | ❌ | ❌ | ✅ (`AutoSeoController::submitToIndexNow` + `AutoSeoReauditJob` + `indexItem`) |
| **Baidu Active Push (السوق الصيني)** | ❌ | ❌ | ❌ | ❌ | ❌ | ✅ (`BaiduIndexingService` + `BaiduIndexingController` — يفعّل فقط عند وجود `zh` في target_languages) |
| خدمة ملف مفتاح IndexNow Server-Side | ❌ | ❌ | ❌ | ❌ | ❌ | ✅ (`SeoProxyService::renderSite`) |
| **Google Indexing API / URL Inspection** | 🔶 | 🔶 | ❌ | ❌ | ❌ | ✅ (`GoogleIndexingService` — OAuth Service Account JWT RS256 + toggle لكل موقع؛ 🔶 غير مختبَر ضد Google فعليًا) |
| ربط Google Search Console (قياس CTR/ظهور/ترتيب) | ✅ | ✅ | 🔶 | ❌ | ❌ | ✅ (`GoogleSearchConsoleAPI` + كاش `seo_gsc_page_metrics` عبر `SeoPerformanceService`) |

### 2.7 تجارب SEO A/B

| الميزة | Ahrefs | SEMrush | Surfer | Screaming Frog | Cloudflare | الموديول |
|---|---|---|---|---|---|---|
| **تجارب SEO A/B على عناصر الصفحة (title/desc/canonical/JSON-LD/FAQ)** | ❌ | 🔶 (عبر SearchPilot المنفصل) | ❌ | ❌ | ❌ | ✅ (`SeoAbTestService` + `SeoAbTestController`) |
| توزيع حتمي لكل صفحة (ثبات النسخة للروبوت الواحد) | ❌ | 🔶 | ❌ | ❌ | ❌ | ✅ (`pickVariant` عبر `crc32($pageUrl)`) |
| تسجيل العروض مع تمييز bots (servings) | ❌ | 🔶 | ❌ | ❌ | ❌ | ✅ (`seo_ab_servings` + `isBot`) |
| **قياس CTR فعلي من GSC لكل نسخة** | ❌ | 🔶 | ❌ | ❌ | ❌ | ✅ (`aggregateMetrics` + `/results` في `SeoAbTestController`) |
| **دلالة إحصائية (chi-squared مع تصحيح Yates + عتبة ظهور)** | ❌ | 🔶 | ❌ | ❌ | ❌ | ✅ (`chiSquare2x2` — عتبة 100 ظهور، alpha 0.05) |
| ترقية الفائز تلقائيًا وتطبيق عنوانه (حلقة محتوى) | ❌ | ❌ | ❌ | ❌ | ❌ | ✅ (`SeoContentService::applyWinningTitleToItem`) |
| أسبقية نسخة التجربة على القيمة الثابتة في البروكسي | ❌ | ❌ | ❌ | ❌ | ❌ | ✅ (`SeoProxyService::rewrite`) |

### 2.8 تحسين الصور

| الميزة | Ahrefs | SEMrush | Surfer | Screaming Frog | Cloudflare | الموديول |
|---|---|---|---|---|---|---|
| تحويل WebP تلقائي (مع fallback للصورة الأصلية) | ❌ | ❌ | ❌ | ❌ | ✅ (Polish) | ✅ (`ImageOptimizationService::optimize` — GD + كاش أسبوع + تغيير حجم >1920px) |
| Lazy Loading تلقائي (تخطي أول صورة hero) | ❌ | ❌ | ❌ | ❌ | 🔶 | ✅ (`applyLazyLoading`) |
| Alt تلقائي للصور الناقصة | ❌ | 🔶 | 🔶 | ❌ | ❌ | ✅ (سيرفر `image_alt` + متصفح في embed.js من title) |
| **srcset/responsive/AVIF/CDN تلقائي** | ❌ | ❌ | ❌ | ❌ | ✅ | ❌ (WebP ثابت بجودة 85 فقط) |

### 2.9 التقرير / الأداء

| الميزة | Ahrefs | SEMrush | Surfer | Screaming Frog | Cloudflare | الموديول |
|---|---|---|---|---|---|---|
| لقطات قبل/بعد (seo_reports) + سجل درجات التدقيق عبر الزمن | 🔶 | ✅ | ❌ | ❌ | ❌ | ✅ (`SeoPerformanceService::snapshot` + `history` + `AutoSeoController::report`) |
| تجميع مقاييس GSC/GA4 في تقرير موحّد | ✅ | ✅ | 🔶 | ❌ | ❌ | ✅ (`cachedSummary` + `ga4Summary`) |
| **رسوم بيانية/لوحة تفاعلية** | ✅ | ✅ | ✅ | ❌ | ❌ | ✅ (`SeoChartService` — scoreTrend/categoryScores/gscTopPages/fixesAppliedTrend جاهزة لـ Chart.js من بيانات حقيقية) |
| **تقارير مجدولة PDF/Email** | ✅ | ✅ | ❌ | ❌ | ❌ | 🔶 (`SeoScheduledReportService` — جدولة daily/weekly/monthly + HTML RTL عبر `Mailer` في cron؛ PDF خارج النطاق) |
| تصدير نتائج التدقيق (CSV/API) | ✅ | ✅ | ❌ | ✅ | ❌ | ❌ |

### 2.10 الجدولة / الأتمتة

| الميزة | Ahrefs | SEMrush | Surfer | Screaming Frog | Cloudflare | الموديول |
|---|---|---|---|---|---|---|
| **إعادة تدقيق دورية حسب التردد (daily/weekly/monthly)** | ✅ | ✅ | ❌ | ❌ | ❌ | ✅ (`SeoSchedulerService::reauditDueSites` + `cron/auto_seo_scheduler.php` + `AutoSeoReauditJob`) |
| **إعادة فهرسة دورية (IndexNow) بدون تدخل** | ❌ | ❌ | ❌ | ❌ | ❌ | ✅ (`reindexDueSites` + `reindexSite`) |
| **Rank Tracking (تتبع ترتيب يومي للكلمات المفتاحية)** | ✅ | ✅ | ❌ | ❌ | ❌ | ✅ (`RankTrackingService` + `cron/seo_rank_tracking.php` — فحص يومي عبر `KeywordRankingSourceInterface` وسجل زمني في `seo_rank_tracking_history`؛ المصدر الافتراضي Null يفشل بأمان) |
| **سير عمل محتوى آلي بحلقة مغلقة (Cron)** | ❌ | ❌ | ❌ | ❌ | ❌ | ✅ (`cron/seo_content_engine.php` → `runEngineCycle`) |
| تنفيذ خلفي عبر طابور مهام (Queue) للعمليات الثقيلة | 🔶 | 🔶 | ❌ | ❌ | ❌ | ✅ (`AutoSeoReauditJob` + `SeoContentGenerateJob` عبر `QueueManager`) |
| خطة استراتيجية 30/60/90 يوم مبنية على بيانات حقيقية (LLM) | 🔶 | 🔶 | ❌ | ❌ | ❌ | 🔶 (`SeoStrategyService::generatePlan` — يستهلك 1 Credit، يدمج SEO score + منافسين + كلمات + Keyword Gap + Outreach) |

---

## 3. الفجوات الأعلى أولوية (Gap Analysis)

مرتبة حسب: (الأثر التنافسي × التكلفة التقريبية × التوافق مع القيود المعمارية).

### 3.1 أولوية عالية (ضرورية للمنافسة الأساسية)

| # | الفجوة | المنافسون الذين يملكونها | الفجوة الحالية في الموديول |
|---|---|---|---|
| G1 | ~~**زحف كامل للموقع (Multi-page crawl)**~~ ✅ مُغلق | Ahrefs/SEMrush/Screaming Frog | **مُغلق (M6, 2026-08-29)**: `SeoCrawlerService` زحاف BFS للروابط الداخلية (نفس الدومين/عمق 1-6/حد 3-100 صفحة/ميزانية وقت) مع فحص on-page فعلي لكل URL (title/meta description/H1/عدد كلمات/كود HTTP/وقت استجابة/أخطاء) مخزّن في `seo_crawl_pages` + تجميع مقاييس الموقع (تكرارات العناوين وH1، صفحات بلا meta/H1، متوسط السرعة والكلمات) + `lastCrawl` + endpoints `POST/GET /api/website-optimizer/crawl`؛ لا ينفّذ JavaScript (خارج النطاق G2) |
| G2 | **JS Rendering + Web Vitals حقيقية** | Ahrefs/SEMrush/Surfer | **لم تبدأ (خارج نطاق M6)**: يحتاج headless browser (Playwright/Puppeteer) + بيانات CrUX/Lighthouse — لا توجد تبعيات خارجية في القيود المعمارية؛ الزحف النصي G1 يوفر on-page بديلًا |
| G3 | ~~**الفهرسة لدى Google (Google Indexing API / URL Inspection)**~~ ✅ مُغلق | Ahrefs/SEMrush (عبر GSC) | **مُغلق (M6, 2026-08-29)**: `GoogleIndexingService` يتكامل مع Google Indexing API الرسمي عبر OAuth Service Account (JWT RS256) — `notify`/`submitSite`/toggle لكل موقع (`google_indexing_enabled`/`last_google_indexed_at`) + endpoints `googleToggle/googleSubmit/googleStatus`؛ **غير مختبَر** ضد Google فعليًا (يحتاج حساب خدمة + تفعيل Indexing API) ويفشل بأمان `available=false` عند غياب `GOOGLE_SERVICE_ACCOUNT_JSON` |
| G4 | ~~**بيانات كلمات مفتاحية خارجية (حجم بحث/صعوبة/SERP)**~~ ✅ مُغلق | Ahrefs/SEMrush/Surfer | **مُغلق (M6, 2026-08-29)**: `KeywordResearchSourceInterface` + `HttpKeywordResearchSource` قابل للتكوين (`KEYWORD_RESEARCH_API_URL`/`_KEY`) و`NullKeywordResearchSource` fail-safe — `KeywordResearchService::enrichTrackedKeywords` يحدّث `search_volume/difficulty/enriched_at` من بيانات حقيقية فقط بلا اختلاق (status/إثراء endpoints)؛ **غير مختبَر** مع مزوّد خارجي فعلي |

### 3.2 أولوية متوسطة (تمايز قوي)

| # | الفجوة | المنافسون الذين يملكونها | الفجوة الحالية في الموديول |
|---|---|---|---|
| G5 | **نشر تلقائي للمحتوى المولَّد على مواقع خارجية** | Surfer (نصف تلقائي) | **لم تبدأ (خارج نطاق M6)**: المقال يُحفظ في `ai_articles` فقط؛ الحلقة المغلقة تنقطع عند النشر الفعلي — يتطلب تكامل نشر خارجي (Tombstone/Webhooks) خارج القيود الحالية |
| G6 | ~~**تقرير بصري + تقارير مجدولة (PDF/Email)**~~ ✅ مُغلق | Ahrefs/SEMrush | **مُغلق (M6, 2026-08-29)**: `SeoChartService` يسلّم بيانات Chart.js جاهزة من DB حقيقية (scoreTrend/categoryScores/gscTopPages/fixesAppliedTrend) + `SeoScheduledReportService` جدولة daily/weekly/monthly (`seo_report_schedules`) وبناء تقرير HTML RTL مهرَّب بالكامل + إرسال عبر `Mailer` في `cron/seo_scheduled_reports.php` (skip آمن عند غياب إعداد البريد)؛ PDF خارج النطاق (بلا مكتبة توليد PDF) |
| G7 | ~~**Rank Tracking (تتبع ترتيب يومي للكلمات المفتاحية)**~~ ✅ مُغلق | Ahrefs/SEMrush | **مُغلق (M6, 2026-08-29)**: `RankTrackingService` يعيد استخدام `KeywordRankingSourceInterface` (M5) — فحص يومي `dueWebsites` (فاصل يوم واحد عبر `websites.last_rank_tracked_at`)، تسجيل كل قياس في `seo_rank_tracking_history` (بُعد زمني)، تحديث `current_position/last_checked_at`، نظرة عامة بـ best/trend/readings + سلسلة زمنية لكل كلمة + `cron/seo_rank_tracking.php` + endpoints (GET/check/history) |
| G8 | **تحسين صور متقدم (srcset/AVIF/تحجيم متجاوب)** | Cloudflare (Polish) | `ImageOptimizationService` ينتج WebP بجودة ثابتة + lazy فقط؛ لا srcset ولا AVIF ولا اختيار حسب DPR |

### 3.3 أولوية منخفضة / خارج نطاق تنفيذ اليوم

| # | الفجوة | ملاحظات |
|---|---|---|
| G9 | **مولّد sitemap ديناميكي من نتائج الزحف** | إصلاح sitemap قالب ثابت يُخدم Server-Side (`serveAuxFile`)؛ لا يُبنى من الصفحات المكتشفة فعلًا |
| G10 | **تصدير نتائج التدقيق (CSV/API خارجي)** | لا يوجد endpoint تصدير للتدقيق/النتائج رغم توفر البيانات في `wo_audits/wo_audit_findings` |
| G11 | **فحص H1/عنوان متعدد النسخ + فحص عمق الزحف** | مشتقة من G1؛ ستحل تلقائيًا عند وجود زاحف متعدد الصفحات |

---

## 4. الميزة التنافسية الطبيعية للموديول (لا يملكها المنافسون العامون)

- **تنفيذ تلقائي فعلي على المواقع الخارجية دون لمس الاستضافة**: الموديول لا يكتفي
  بالتحليل — يعيد كتابة HTML Server-Side عبر `SeoProxyService` (وضع CNAME أو
  `/s/{token}`) و/أو يحقن إصلاحات عبر `embed.js`، مع أوضاع جرأة
  (conservative/balanced/aggressive)، معاينة قبل التطبيق، Rollback كامل، وخدمة
  ملفات robots/sitemap/llms.txt/IndexNow key Server-Side. هذا يجمع ما بين
  Cloudflare HTML Rewriter وSearchPilot في أداة واحدة مدمجة — لا يفعله Ahrefs
  ولا SEMrush ولا Surfer ولا Screaming Frog.
- **SEO A/B Testing بدلالة إحصائية حقيقية**: توزيع حتمي لكل صفحة + قياس CTR فعلي
  من GSC + اختبار chi-squared مع تصحيح Yates وعتبة ظهور (100) + ترقية الفائز
  تلقائيًا (`SeoAbTestService`). تقنية على مستوى SearchPilot، غائبة عن كل
  المنافسين المدروسين كأداة مدمجة.
- **GEO/AEO (تحسين محركات الإجابة التوليدية)**: فحص وتوليد وخدمة `llms.txt` /
  `llms-full.txt`، أقسام روبوتات AI في robots.txt (GPTBot/ClaudeBot/PerplexityBot/
  OAI-SearchBot)، الاستشهاد بمصادر موثوقة، `sameAs`، Speakable — منطقة ناشئة لا
  تقدمها الأدوات التقليدية.
- **فهرسة متعددة المحركات تشمل Google والسوق الصيني**: IndexNow تلقائي عبر 4
  محركات (مع توليد مفتاح وخدمة ملف المفتاح Server-Side عند الربط) + Google
  Indexing API عبر Service Account (`GoogleIndexingService` — جاهز، غير مختبَر
  ضد Google فعليًا) + Baidu Active Push بشرط استهداف `zh`
  (`BaiduIndexingService`) — تكامل نادر ومربح لوكالات سفر
  تستهدف السوق الصيني.
- **حلقة محتوى مغلقة بالكامل تعمل بـ Cron**: اكتشاف مواضيع (كلمات/GSC) → توليد
  LLM → حفظ `ai_articles` → IndexNow → تجربة A/B على العنوان → قياس CTR → تطبيق
  العنوان الفائز، كلها بلا تدخل يدوي (`cron/seo_content_engine.php`).
- **عربي أولًا (RTL)** مع 12 لغة محتوى — معظم المنافسين العالميين عربيتهم ضعيفة.
- **بدون تبعيات خارجية**: PHP نقي + PDO بدون Composer في زمن التشغيل، ما يجعل
  الصيانة والاختبار (FakeDatabase) خفيفة.

---

## 5. منهجية التحليل

- مخزون الميزات: مسح كامل لـ Services/Controllers/Jobs/Models/Cron/Routes/
  Migrations الخاصة بـ SEO في `/workspace`:
  - Services: `app/Services/Seo/*` (AuditChecksService, BaiduIndexingService,
    ImageOptimizationService, SeoAbTestService, SeoContentService,
    SeoPerformanceService, SeoProxyService, SeoSchedulerService,
    SeoCrawlerService, GoogleIndexingService, KeywordResearchService,
    RankTrackingService, SeoChartService, SeoScheduledReportService +
    Null/HttpKeywordResearchSource),
    `app/Services/AutoSeo/AutoSeoEmbedService.php`,
    `app/Services/SeoStrategy/SeoStrategyService.php`,
    `app/Services/Indexing/IndexNowService.php`, `app/Services/AI/ArticleGenerator.php`.
  - Controllers: AutoSeoController, SeoContentController, SeoProxyController,
    SeoAbTestController, SeoIndexingController, SeoStrategyController,
    BaiduIndexingController, SeoInsightsController, WebsiteOptimizerController
    (لا يوجد `SeoOptimizerController` منفصل — دوره يؤديه WebsiteOptimizerController).
  - Migrations: `2026_08_20_000001` (embed) / `000002` (IndexNow+A/B) / `000003`
    (performance+schedule) / `000004` (content engine)، `000050` (strategy),
    `add_baidu_token`, `expand_target_language`,
    `2026_08_29_000004` (crawl_pages/rank_tracking_history/report_schedules).
- بيانات المنافسين: المعرفة العامة بالمواصفات الموثّقة (الصفحات الرسمية
  المعروفة) دون جلب صفحات في هذه الجلسة؛ الأرقام التسويقية خارج نطاق الادعاء.
- كل ميزة منافِسة قورنت 1:1 مع التنفيذ الفعلي في الكود (وليس مع الوعد التسويقي)؛
  الميزات المعتمدة على LLM/الرصيد أُعلِّمت 🔶.
