# التحليل التنافسي — Competitor Intelligence (Tourfecto) — 2026-08-29

مقارنة منهجية لموديول Competitor Intelligence المدمج (Embedded) داخل منصة
Tourfecto AI ضد أبرز حلول استخبارات المنافسين العالمية: SEMrush
(Competitive Research) وSpyFu وSimilarWeb وKompyte (تتبع منافسين متخصص).

> **القيد المنهجي:** التحليل معتمد على مخزون الميزات **الفعلي** للموديول
> (مسح كامل للكود المصدري: 21 ملف Service + Controller + Models + Migrations
> + Cron + Jobs + مسارات)، مقابل الوثائق/الميزات الموثقة علنًا للمنافسين
> الأربعة من المعرفة العامة. لا تُدَّعى أي ميزة غير موجودة فعلًا في الكود،
> وأي ميزة تعتمد على LLM خارجي (Gemini/AIOrchestrator) مُعلَّمة 🔶 لأنها
> تستهلك رصيد المنصة (api_usage_logs) وتتطلب مفاتيح API مهيأة.

---

## 1. نطاق المقارنة

| البُعد | SEMrush / SpyFu / SimilarWeb / Kompyte | الموديول الحالي |
|---|---|---|
| السوق المستهدف | عالمي، متعدد اللغات (غربي غالبًا) | عربي أولًا (ar/en + fr/de)، مدمج في منصة سياحة/وكالات |
| نموذج النشر | SaaS سحابي منفصل باشتراك مستقل | مدمج (Embedded) داخل Tourfecto بدون اشتراك إضافي |
| بيانات السوق | بيانات Panel/تخمينية (SimilarWeb/SEMrush تُقدّر الزيارات) | حقائق ممسوكة فقط (NO FAKE DATA): لقطات فعلية، لا تقدير |
| التقنية | منصات ضخمة بـ ML/Indexing ضخم | PHP خام (PDO، لا Composer خارجي) — كل الموديول ملفات داخلية |
| عزل التينانت | حسابات منفصلة | عزل صارم عبر `user_id` + `assertCompetitorOwnership()` + RBAC داخلي |

---

## 2. ملخص وضع الموديول لكل منطقة ميزات

الأسطورة: ✅ موجود · 🔶 جزئي/محدود · ❌ غير موجود

### 2.1 اكتشاف المنافسين (Discovery)

| الميزة | SEMrush | SpyFu | SimilarWeb | Kompyte | الموديول |
|---|---|---|---|---|---|
| إضافة يدوية + استيراد جماعي (Bulk) | ✅ | ✅ | ✅ | ✅ | ✅ (`apiAddCompetitor` + `apiBulkImportCompetitors` بسقف 200 صف) |
| اقتراح مرشّح + موافقة/رفض | ✅ | ✅ | ✅ | ✅ | ✅ (`CompetitorDiscoveryService::suggestManualCandidate/approveCandidate`) |
| اكتشاف عبر Google Places (دليل عام حقيقي) | 🔶 | 🔶 | ✅ | 🔶 | ✅ (`GooglePlacesDiscoverySource` — Text Search + Place Details، يحتاج مفتاح) |
| اكتشاف من بيانات Onboarding المستخدم | ❌ | ❌ | ❌ | ❌ | ✅ (`WebsiteOnboardingDiscoverySource` — competitor_1/2/3_url) |
| **استكشاف السوق/التصنيف التلقائي للمنافسين** | ✅ (Market Explorer) | 🔶 | ✅ | 🔶 | ❌ (مصادر محدودة فقط) |
| **فجوة الكلمات المفتاحية (Keyword Gap)** | ✅ | ✅ | ✅ | 🔶 | ❌ (مذكور صراحة كـ "تكامل منفصل لاحقًا" في `CompetitorAnalysisService`) |
| منع اختلاق البيانات | ❌ (بيانات تقديرية) | ❌ | ❌ | ✅ | ✅ (`NullDiscoverySource` يرجع `available=false` بدل نتائج وهمية) |

### 2.2 المراقبة وكشف التغييرات (Monitoring & Change Detection)

| الميزة | SEMrush | SpyFu | SimilarWeb | Kompyte | الموديول |
|---|---|---|---|---|---|
| مراقبة صفحات ثابتة (home/pricing/...) | 🔶 | ❌ | ❌ | ✅ | ✅ (`MonitoringEngine` — 7 صفحات ثابتة) |
| كشف تغييرات بمقارنة Hash/نص مُطبَّع | ❌ | ❌ | ❌ | ✅ | ✅ (`ChangeDetectionService::detectAndRecord`) |
| كشف صفحات جديدة/محذوفة عبر Sitemap | ❌ | ❌ | ❌ | ✅ | ✅ (`SitemapMonitor` — sitemap.xml diff + سقف 500 URL) |
| إشارة التوظيف (Job Postings) | 🔶 | ❌ | ❌ | ✅ | ✅ (Heuristic `SitemapMonitor::isCareerUrl` يرفع الخطورة لـ high) |
| إشارات تقنية (Tech Signals) | ✅ (BuiltWith) | 🔶 | ❌ | 🔶 | ✅ (`WebsiteSnapshotFetcher::extract` — server/x-powered-by/WordPress/Shopify/Wix) |
| مراقبة آلية بالجدولة (Cron + Queue) | ✅ | ✅ | ✅ | ✅ | ✅ (`cron/monitor_competitors.php` → `MonitorCompetitorJob`) |
| تردد مراقبة لكل منافس (daily/weekly/custom) | 🔶 | ❌ | ❌ | ✅ | ✅ (عمود `monitoring_frequency` + `monitoring_interval_hours`) |
| "Check Now" يدوي | ✅ | ❌ | ❌ | ✅ | ✅ (`apiCheckNow` — محدد 5 دقائق/منافس) |
| **التقاط المحتوى المقدَّم بالـ JS** | 🔶 | ❌ | ❌ | ✅ | ❌ (GET خام فقط، بلا متصفح/رندر) |
| **لقطة بصرية/مقارنة Screenshot (Diff)** | ❌ | ❌ | ❌ | ✅ | ❌ (نص فقط، لا صور) |
| **تتبع ترتيب الكلمات المفتاحية (SERP)** | ✅ | ✅ (أرشيف 15 سنة) | ✅ | 🔶 | ❌ (جدول `cm_google_rankings` موجود قديمًا بلا مصدر بيانات حقيقي) |

### 2.3 تحليل الأسعار (Price Analysis)

| الميزة | SEMrush | SpyFu | SimilarWeb | Kompyte | الموديول |
|---|---|---|---|---|---|
| استخراج سعر مهيكل (رقم + عملة) | 🔶 | ❌ | ❌ | ✅ | ✅ (`PriceExtractor` — 18 عملة + عربية + أرقام هندية/عربية) |
| سجل أسعار تاريخي قابل للرسم | 🔶 | ❌ | ❌ | ✅ | ✅ (`apiPriceHistory` من `price_before/after/currency` في `ci_changes`) |
| تصنيف تغيير السعر كحدث عالي الخطورة | 🔶 | ❌ | ❌ | ✅ | ✅ (`ChangeDetectionService::classify` — pricing_change/offer_change = high) |
| مقارنة أسعارك مع أسعار المنافس | 🔶 | ❌ | ❌ | ✅ | 🔶 (مقارنة النشاط فقط، لا أسعار "لي") |
| **تتبع سعر لكل منتج/SKU بمقارنة منتظمة** | 🔶 | ❌ | ❌ | ✅ | 🔶 (الاستخراج "انتهازي" عند تغيّر الصفحة فقط، أول سعر واحد؛ `cm_pricing` القديم يدوي) |

### 2.4 التنبيهات (Alerts)

| الميزة | SEMrush | SpyFu | SimilarWeb | Kompyte | الموديول |
|---|---|---|---|---|---|
| تنبيهات مرتبطة بتغيير فعلي (لا تخمين) | 🔶 | ❌ | ❌ | ✅ | ✅ (`AlertService::notifyChange` — كل تنبيه مربوط بـ `change_id`) |
| Watchlist لكل منافس (حد أدنى للخطورة) | ✅ | ✅ | ❌ | ✅ | ✅ (`CiWatchlistItem` — alert_min_severity + pause) |
| تنبيه بكلمات مفتاحية مخصصة | ✅ | ✅ | ❌ | ✅ | ✅ (`keyword_filters` — تطابق نصي case-insensitive) |
| قنوات: Dashboard / Email / Webhook / Slack | 🔶 | ❌ | ❌ | ✅ | ✅ (Email/Webhook/Slack عبر Jobs غير متزامنة، SSRF-guarded) |
| قناة in_app | 🔶 | ❌ | ❌ | ✅ | 🔶 (تُخزَّن في `ci_alerts` لكن لا يوجد إرسال مخصص لها) |
| ملخص أسبوعي تلقائي (Digest) | ✅ | ❌ | ❌ | ✅ | ✅ (`cron/ci_weekly_digest.php` — AI، opt-in) |
| **قواعد أتمتة متقدمة للتنبيهات (Builder)** | ✅ | 🔶 | ❌ | ✅ | ❌ (watchlist فقط، بلا محرر قواعد) |
| **SMS / Push** | 🔶 | ❌ | ❌ | 🔶 | ❌ |
| **تجميع/تقليل ضجيج التنبيهات** | ✅ | 🔶 | ❌ | ✅ | ❌ |

> **ملاحظة واقعية من الكود:** `AlertService.php:51` يسمح بقناتي `webhook` و`slack`
> كقيم لـ `ci_alerts.channel`، بينما مخطط `ci_alerts` في migration
> `2026_08_08_000042` يعرّف العمود `ENUM('dashboard','email','in_app')` فقط —
> تناقض محتمل بين الكود والمخطط يستحق إصلاح (مخطط فحص صارم أو توسيع الـENUM).

### 2.5 التهديدات والفرص (Threats & Opportunities)

| الميزة | SEMrush | SpyFu | SimilarWeb | Kompyte | الموديول |
|---|---|---|---|---|---|
| محرك قواعد تهديد/فرصة مع دليل (Evidence) | 🔶 | ❌ | ❌ | ✅ | ✅ (`ThreatOpportunityService` — rules-based بشفافية كاملة) |
| رؤى على مستوى السوق (عدة منافسين) | ✅ | ✅ | ✅ | ✅ | 🔶 (العمود `competitor_id` يدعم NULL لكن لا مُولِّد فعلي للرؤى السوقية) |
| دورة حياة رؤية (new/reviewed/dismissed) | ✅ | ❌ | ❌ | ✅ | ✅ (`CiConstants::INSIGHT_STATUSES` + `apiInsightStatus`) |
| توصيات إجرائية مقترنة بكل رؤية | 🔶 | ❌ | ❌ | ✅ | ✅ (`recommended_action` على كل `CiInsight`) |
| **رؤية ناتجة عن مصادر خارجية (أخبار/تمويل)** | ✅ | 🔶 | ✅ | ✅ | ❌ |

### 2.6 الذكاء الاصطناعي (AI)

| الميزة | SEMrush | SpyFu | SimilarWeb | Kompyte | الموديول |
|---|---|---|---|---|---|
| محلل AI مبني على بيانات حقيقية فقط (Grounded) | 🔶 | ❌ | ❌ | ✅ | 🔶 ✅ منطقًا / 🔶 عمليًا (`AICompetitiveAnalyst` — يعتمد على رصيد LLM) |
| سؤال حر (Ask) عن نشاط المنافسين | 🔶 | ❌ | ❌ | ✅ | 🔶 (`apiAiAsk` + RateLimit `ai_ask`) |
| ملخص أسبوعي JSON منظم | 🔶 | ❌ | ❌ | ✅ | 🔶 (`weeklySummary` + RateLimit `ai_weekly_summary`) |
| تحليل Positioning/Strengths/Weaknesses | 🔶 | ❌ | ❌ | ✅ | 🔶 (`analyzeProfile` من لقطات فعلية) |
| تحليل مقارن On-page + درجة رقمية حتمية | 🔶 | ❌ | ❌ | 🔶 | 🔶 (`CompetitorAnalysisService::analyze` — crawl حقيقي + `calculateOnPageScore` حتمي) |
| **Battlecards / تحليل مواقف تنافسية** | 🔶 | ❌ | ❌ | ✅ | ❌ |
| **تنبؤات ML / Next-best-action تنافسي** | ✅ | 🔶 | ❌ | 🔶 | ❌ (قواعد فقط) |

### 2.7 المقارنة والمقاييس (Benchmarking)

| الميزة | SEMrush | SpyFu | SimilarWeb | Kompyte | الموديول |
|---|---|---|---|---|---|
| مقارنة My Business مقابل منافسين | ✅ | ✅ | ✅ | 🔶 | ✅ (`BenchmarkingService::compare` — 5 مؤشرات بيانات حقيقية) |
| Scorecard دوري 0-100 مع أساس (data_backed/estimated) | 🔶 | ❌ | ❌ | ✅ | ✅ (`computeScorecard` + `ci_scorecards` + كرون يومي) |
| اتجاه Scorecard عبر الزمن | ✅ | ❌ | ❌ | ✅ | ✅ (`apiScorecardTrend` — 52 أسبوع) |
| تصدير مقارنة CSV (Excel) | ✅ | ✅ | ✅ | ✅ | ✅ (`apiComparisonExport` + UTF-8 BOM) |
| **مقاييس مرور/جمهور/Backlinks** | ✅ | ✅ | ✅ | ❌ | ❌ (مذكور `customer_signals_score = null: Not Available`) |
| **Share of Voice / حصة سوقية** | ✅ | ✅ | ✅ | ❌ | ❌ |

### 2.8 التقارير (Reports)

| الميزة | SEMrush | SpyFu | SimilarWeb | Kompyte | الموديول |
|---|---|---|---|---|---|
| تقارير أسبوعية/شهرية | ✅ | ✅ | ✅ | ✅ | ✅ (`ReportService::generate` — types: weekly/monthly/profile/threat/opportunity/change) |
| تقرير ملف منافس كامل (Profile) | ✅ | ✅ | ✅ | ✅ | ✅ (`buildProfileReport` — منافس + Timeline + أحدث Scorecard) |
| تصدير HTML/PDF (عبر طباعة المتصفح) + CSV | ✅ | ✅ | ✅ | ✅ | ✅ (`exportReportPrintable` — format=pdf|html|csv) |
| **PDF حقيقي على السيرفر / جدولة تسليم تلقائي** | ✅ | ✅ | ✅ | ✅ | 🔶 (لا مكتبة PDF؛ التسليم الآلي = Weekly Digest فقط) |
| **Report Builder مخصص (حقول/فلاتر)** | ✅ | ✅ | ✅ | 🔶 | ❌ (قوالب ثابتة فقط — ميزة `CrmReportBuilderService` من CRM خارج نطاق هذا الموديول) |

### 2.9 الأمان والبنية التحتية (Security & Infrastructure)

| الميزة | SEMrush | SpyFu | SimilarWeb | Kompyte | الموديول |
|---|---|---|---|---|---|
| حارس SSRF شامل (DNS كامل A/AAAA + IPv6 + إعادة توجيه) | 🔶 | 🔶 | 🔶 | 🔶 | ✅ (`SsrfGuard` — مستوى متقدم جدًا، أنسب من معيار SaaS) |
| Rate Limiting لكل Scope/مستخدم (fixed-window) | ✅ | ✅ | ✅ | ✅ | ✅ (`CiRateLimiter` — 6 scopes + جدول `ci_rate_limits`) |
| RBAC داخلي (4 أدوار) | ✅ | ✅ | ✅ | ✅ | ✅ (`CiPermissions` — Admin/Manager/Analyst/Viewer فوق `users.role`) |
| عزل تينانت + Audit Log | ✅ | ✅ | ✅ | ✅ | ✅ (`assertCompetitorOwnership`/`assertDiscoveryOwnership` + `ActivityLog`) |
| معالجة خلفية غير متزامنة (Queue Jobs) | ✅ | ✅ | ✅ | ✅ | ✅ (`MonitorCompetitorJob` + `SendCompetitorAlertEmail/WebhookJob`) |
| **تكامل ميزانية الاشتراك (Subscription/Wallet)** | ✅ | ✅ | ✅ | ✅ | 🔶 (نقاط `/api/ai/*` القديمة مقيدة بـ `require_competitor_analysis`، لكن نقاط CI الجديدة AuthMiddleware فقط + Rate Limits) |

---

## 3. الفجوات الأعلى أولوية (Gap Analysis)

مرتبة حسب: (الأثر التنافسي × التكلفة التقريبية × التوافق مع القيود المعمارية
— "لا إعادة بناء، Additive فقط، لا تبعيات خارجية").

### 3.1 أولوية عالية (ضرورية للمنافسة الأساسية)

| # | الفجوة | المنافسون الذين يملكونها | الفجوة الحالية في الموديول |
|---|---|---|---|
| G1 | ~~**تتبع ترتيب الكلمات المفتاحية (SERP / Keyword Rankings)**~~ ✅ مُغلق | SEMrush, SpyFu, SimilarWeb | **مُغلق (M5, 2026-08-29)**: جدول `ci_keyword_rankings` جديد ببُعد زمني + `KeywordRankingService` (أحدث قياس/أفضل ترتيب/اتجاه) + مصدر افتراضي `NullKeywordRankingSource` يفشل بأمان عند غياب الإعداد + مُجدول `cron/ci_keyword_rankings.php` يومي يقرأ كلمات `competitor_keywords` الفعلية؛ قياس مرئية بحثية متاح برمجيًا |
| G2 | **تحليلات الزيارات/الجمهور (Traffic & Audience Insights)** | SimilarWeb, SEMrush | لا يوجد أي مقاس مرور/جمهور؛ `BenchmarkingService` يعرض `not_available` صراحةً بدل تخمين |
| G3 | **ذكاء الإعلانات (Ad Intelligence / PPC + أرشيف نصوص الإعلانات)** | SpyFu, SEMrush | لا يوجد تتبع حملات/إعلانات منافسين إطلاقًا |
| G4 | **فجوة الروابط الخلفية (Backlink Gap) ومرجعية الـ SEO** | SEMrush, SpyFu | لا يوجد أي مقاس Backlinks/سلطة للنطاقات |

### 3.2 أولوية متوسطة (تمايز قوي)

| # | الفجوة | المنافسون الذين يملكونها | الفجوة الحالية في الموديول |
|---|---|---|---|
| G5 | **لقطة بصرية/مقارنة Screenshot** | Kompyte | المراقبة نصية فقط (`normalized_excerpt`) — بلا صور/رندر JS؛ التغييرات البصرية المحضة غير قابلة للكشف |
| G6 | ~~**Battlecards وإعداد فريق المبيعات (Sales Enablement)**~~ ✅ مُغلق | Kompyte, SEMrush | **مُغلق (M5, 2026-08-29)**: `BattlecardService` يولّد بطاقات معركة قواعدية (rules-based) من بيانات المراقبة الحقيقية (scorecards/insights/prices/changes) بلا AI ولا تخمين — يرفض بـ `insufficient_data` عند نقص البيانات؛ نقاط قوة/ضعف/موقع سعري/إجراءات موصى بها + 3 endpoints |
| G7 | ~~**تتبع أسعار لكل منتج/SKU بجدولة منتظمة**~~ ✅ مُغلق | Kompyte, Prisync | **مُغلق (M5, 2026-08-29)**: `PriceExtractor::extractAll` يستخرج عدة أسعار مع تسميات سياقية (`deriveLabel`)؛ `ProductPriceTrackerService` مدمج في `MonitoringEngine` فيلتقط كل منتج/سعر بكل دورة مراقبة (جدولة منتظمة فعلًا) لحفظ التاريخ في `ci_product_prices`؛ صفحات prices/products/offers |
| G8 | **استخبارات أخبار/تمويل/توظيف خارجية** | Kompyte | الوحيد المتاح هو Heuristic سلسلة الوظائف عبر Sitemap (`isCareerUrl`)؛ بلا RSS/أخبار/تمويل |
| G9 | **تتبع تواجد المنافس على شبكات التواصل** | SEMrush (Social Tracker), Kompyte | لا يوجد أي مصدر لوسائل التواصل |

### 3.3 أولوية منخفضة (عالية التكلفة / خارج نطاق اليوم)

| # | الفجوة | المنافسون الذين يملكونها | الفجوة الحالية في الموديول |
|---|---|---|---|
| G10 | **Market Explorer / حصة سوقية / Share of Voice** | SEMrush, SimilarWeb | يتطلب بيانات Panel/Index خارجية ضخمة لا يملكها الموديول |
| G11 | **تنبؤات ML (تحليل تنبؤي للتهديدات)** | SEMrush (Market Eye), SimilarWeb | القواعد الحالية `rules_engine` مقصودة وشفافة — يتطلب تدريب/بايبل خارج النطاق |
| G12 | **مقاييس إشارات العملاء (Reviews) داخل Scorecard** | SimilarWeb (Reputation) | `customer_signals_score` يعود `null` صراحةً — يحتاج ربط موديول السمعة (Reputation) المنفصل |

---

## 4. الميزة التنافسية الطبيعية للموديول (لا يملكها المنافسون العامون)

- **حارس SSRF بمستوى دفاع عميق غير معتاد في SaaS**: `SsrfGuard` يحلّ كل سجلات
  A+AAAA ويرفض أي سجل خاص (حماية IPv6 primacy)، يثبّت الاتصال على IPv4
  (`CURLOPT_IPRESOLVE`)، يعيد فحص كل Redirect خطوة بخطوة، ويمنع المنافذ غير
  القياسية — كل مسار جلب خارجي (مراقبة، Sitemap، Webhooks، Discovery) يمر منه.
- **فلسفة "NO FAKE DATA" صريحة وقابلة للتحقق**: `NullDiscoverySource` يرجع
  `available=false` بدل اختلاق نتائج؛ فشل الجلب يُسجَّل `fetch_error` ولا يُعتبر
  "لا تغيير"؛ المقاييس غير المتاحة تُعرض `not_available`؛ ومحلل AI مقيَّد
  (Grounded) على سياق مخزّن فعليًا فقط مع تعليمات صريحة بعدم الاختلاق — هذا
  يتناقض جوهريًا مع نماذج التقدير/الـPanel عند SimilarWeb وSEMrush.
- **مدمج في سياق منصة سياحة عربية**: يعيد استخدام بيانات Onboarding
  (`websites.competitor_1/2/3_url`)، يشارك مفتاح Google Places مع موديول GBP،
  يقيس نشاط المحتوى من `ai_articles` الخاصة بالمنصة، يدعم 18 عملة بينها 7 عربية
  مع أرقام عربية-هندية في `PriceExtractor`، وثنائي اللغة ar/en أولًا.
- **خفيف وبلا تبعيات**: PHP خام + PDO + جدولة Cron + Queue موجودة، بلا أي
  Composer خارجي — يعمل داخل بيئة السيرفر الحالية فورًا.
- **إشارة التوظيف (Job Postings)** كنظير عملي لما تقدمه منصات Kompyte/Crayon،
  منفَّذة فعليًا ومرفوعة الخطورة إلى high فور ظهور/اختفاء صفحة توظيف في Sitemap.

---

## 5. منهجية التحليل

- مخزون الميزات: مسح كامل لكود الموديول — 21 ملف في
  `app/Services/CompetitorIntelligence/`، و`CompetitorIntelligenceController.php`
  (32 نقطة API + مسارا ويب)، وModels (`Competitor`, `CiChange`, `CiSnapshot`,
  `CiAlert`, `CiInsight`, `CiReport`, `CiScorecard`, `CiWatchlistItem`,
  `CiDiscoveryCandidate`, `CiUserPreference`)، وMigrations
  (`2026_08_08_000042`, `000046`, `2026_08_09_000043/000045/000046`,
  `2026_08_10_000047`, `2026_08_14_000048`)، وCron
  (`monitor_competitors.php`, `ci_weekly_digest.php`)، وJobs الثلاثة، ومسارات
  `routes/api.php` + `routes/web.php` + `routes/api_ADDITIONS.php`.
- بيانات المنافسين: المعرفة العامة الموثقة علنًا لميزات SEMrush Competitive
  Research وSpyFu وSimilarWeb وKompyte (دون ادعاء تفوق عددي/تسويقي).
- كل ميزة منافِسة قُورنت 1:1 مع التنفيذ الفعلي في الكود (لا مع الوعد التسويقي)،
  وأي اعتماد على LLM خارجي/رصيد (GeminiClient → `api_usage_logs`) مُعلَّم 🔶.
