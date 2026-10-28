# التحليل التنافسي — موديول Revenue Intelligence (Tourfecto) — 2026-08-29

مقارنة منهجية لموديول "ذكاء الإيرادات" (Revenue Intelligence) المدمج داخل منصة
Tourfecto ضد أبرز حلول فئة Revenue Intelligence العالمية: **Clari** (أتمتة
التنبؤ + Copilot)، **Gong Revenue Intelligence** (ذكاء المحادثات/الصفقات)،
**Revenue Grid / Salesloft** (منصة التنبؤ وإيرادات المبيعات)، و**insightSquared**
(التحليلات والتقارير لأقسام الإيرادات). الموديول مكتوب بلغة PHP خالصة (بدون
Composer تبعيات وقت تشغيل جديدة، PDO، بدون Namespaces) ومدمج كطبقة تحليل فوق
بيانات المنصة الموجودة فعلاً (إيرادات مسجّلة، صفقات CRM، إنفاق إعلاني،
اشتراكات، Webhooks ستريب).

> **القيد المنهجي:** التحليل معتمد على المخزون الفعلي للموديول (مُسح من الكود
> بالكامل مع الاستشهاد بملفات) + المعرفة العامة بالوظائف الموثّقة رسميًا
> للمنافسين (صفحاتهم العامة/توثيقاتهم). كل ادعاء بخصوص الموديول مدعوم بملف
> حقيقي؛ لا توجد ميزة تُذكر دون وجودها في الكود. الأرقام التسويقية للمنافسين
> تُذكر كسياق فقط ولا تُستخدم كادعاء تفوق. المبدأ الحاكم للموديول: "لا أرقام
> مخترعة — كل رقم من بيانات حقيقية، وإن لم تكن البيانات كافية: Not enough data".

---

## 1. نطاق المقارنة

| البُعد | المنافسون | الموديول الحالي |
|---|---|---|
| السوق المستهدف | عالمي (متعدد اللغات، إنجليزي أولًا) | عربي أولًا (ar/en مع Normalization عربي)، مدمج في منصة سياحة/وكالات |
| نموذج النشر | SaaS سحابي مستقل | مدمج (Embedded) داخل منصة Tourfecto متعددة الموديولات |
| مصدر البيانات | ETL/تكاملات من CRM + Billing + مكالمات | قراءة مباشرة من جداول المنصة الحالية (rev_revenue_records، crm_deals، ad_campaigns، subscriptions، biz_subscriptions + Webhook Stripe) |
| المصدر | مغلق | مفتوح داخل المستودع، بدون تبعيات خارجية وقت التشغيل |
| الثمن | لكل مقعد/سنوي بالدولار (Clari/Gong مرتفعان) | مجاني ضمن اشتراك المنصة (No per-seat) |
| الذكاء الاصطناعي | نماذج تنبؤ ML + LLM | إحصائي صرف للتنبؤ + LLM (Gemini) لصياغة الإجابة فقط عبر Credits المنصة |

---

## 2. ملخص وضع الموديول لكل منطقة ميزات

الأسطورة: ✅ موجود · 🔶 جزئي/محدود · ❌ غير موجود

### 2.1 لوحة الإيرادات والـ Overview

| الميزة | Clari | Gong | Revenue Grid | insightSquared | الموديول |
|---|---|---|---|---|---|
| إجمالي إيراد + نمو مقابل الفترة السابقة | ✅ | ✅ | ✅ | ✅ | ✅ (RevenueOverviewService::getOverview: total/growth/trend/من فترات 5) |
| إيراد متكرر مقابل لمرة واحدة (MRR/One-time) | ✅ | ✅ | ✅ | ✅ | 🔶 (من جدول subscriptions لمنصة المستخدم نفسه فقط — RevenueOverviewService::getRecurringRevenue) |
| تحليل حسب المصدر (Source) | ✅ | ✅ | ✅ | ✅ | ✅ (بالمقارنة مع الفترة السابقة — getRevenueBySourceWithGrowth) |
| حسب المنتج/الخدمة | ✅ | ✅ | ✅ | ✅ | ❌ (صراحة: لا جدول منتجات مرتبط بالإيراد — RevenueOverviewService::getRevenueByProduct) |
| رسوم بيانية (اتجاه/مصادر) | ✅ | ✅ | ✅ | ✅ | ✅ (Chart.js: daily trend + by source — Controller pageScript) |
| تحذير عملات مختلطة (Mixed Currency) | ✅ | ✅ | ✅ | ✅ | ✅ (كشف صريح دون تحويل FX — RevenueDataGateway::getRevenueTotals) |
| ملخص تنفيذي (Executive) | ✅ | ✅ | ✅ | ✅ | ✅ (ExecutiveSummaryService يجمّع أهم رقم من كل خدمة) |
| تخصيص داشبورد لكل مستخدم | ✅ | ✅ | ✅ | ✅ | ✅ (RevenueDashboardService + revai_dashboard_prefs، قائمة Widgets معروفة فقط) |
| تصدير تقارير (JSON/CSV) | ✅ | ✅ | ✅ | ✅ | ✅ (apiExportReport + streamCsv بترميز UTF-8 BOM) |
| عائد الإنفاق (ROAS/Spend) | 🔶 | 🔶 | 🔶 | ✅ | ✅ (إجمالي إنفاق يدوي + ad_campaigns.spend — RevenueDataGateway) |

### 2.2 التنبؤ (Forecasting)

| الميزة | Clari | Gong | Revenue Grid | insightSquared | الموديول |
|---|---|---|---|---|---|
| تنبؤ إحصائي مع فاصل ثقة ونطاق | ✅ | ✅ | ✅ | ✅ | ✅ (انحدار خطي بسيط على آخر 90 يوم، R²، Confidence، Range — RevenueForecastService::computeForecast) |
| **تنبؤ تنبؤي بـ ML (Win Probability / AI)** | ✅ | ✅ | ✅ | ✅ | 🔶 (إحصائي فقط؛ "المرجع الوحيد" win_probability من مرحلة الـCRM — لا نموذج ML) |
| توقع على مستوى الصفقة (Deal-level) | ✅ | 🔶 | ✅ | ✅ | ✅ (DealLevelForecastService: هذا الشهر/الربع/لاحقًا/غير موقّت بوزن الاحتمالية) |
| سيناريوهات What-if | ✅ | 🔶 | ✅ | ✅ | ✅ (scenarioForecast + نية what_if_scenario في الـAssistant) |
| موسمية | ✅ | ✅ | ✅ | ✅ | 🔶 (مقارنة فترة سابقة مكافئة فقط، مع إفصاح أنها ليست نموذج موسمية كامل — computeSeasonalFactor) |
| قياس دقة التنبؤ الفعلية (مقارنة بالمحقّق) | ✅ | ✅ | ✅ | ✅ | ✅ (getAccuracyHistory: توقع قديم مقابل الإيراد الفعلي في نفس الفترة) |
| تخزين سجل التنبؤات | ✅ | ✅ | ✅ | ✅ | ✅ (revai_forecasts — Model RevaiForecast) |
| تنبؤ حسب المنتج/العميل/القطاع | ✅ | ✅ | ✅ | ✅ | ❌ (لا تنبؤات جزئية؛ الإيراد غير مرتبط بمنتج أو بعميل في rev_revenue_records) |

### 2.3 خط الصفقات (Pipeline Revenue)

| الميزة | Clari | Gong | Revenue Grid | insightSquared | الموديول |
|---|---|---|---|---|---|
| قيمة خط الصفقات + قيمة موزونة بالاحتمالية | ✅ | ✅ | ✅ | ✅ | ✅ (PipelineRevenueService::computePipeline) |
| تغطية الخط مقابل الإيراد الفعلي (Coverage) | ✅ | ✅ | ✅ | ✅ | ✅ (weighted pipeline / متوسط الإيراد الشهري 90 يوم) |
| صفقات مرجّح فوزها (Likely Wins) | ✅ | ✅ | ✅ | ✅ | ✅ (الاحتمالية ≥ 70%) |
| صفقات متعثرة/متأخرة (At-Risk/Stalled) | ✅ | ✅ | ✅ | ✅ | ✅ (أيام التأخير + سبب مقترح، وإدراجها كمخاطر) |
| إسناد الإيراد للمندوب/الفريق (Attribution) | ✅ | ✅ | ✅ | ✅ | ✅ (DealLevelForecastService::aggregateByRep/ByTeam + sales_reps/sales_teams) |
| الفصل الصريح بين "خط الصفقات" و"الإيراد المحقق" | ✅ | ✅ | ✅ | ✅ | ✅ (ملاحظة صريحة في المخرجات: Forecast وليس Revenue محقق) |
| Quotas/أهداف حصص المندوبين | ✅ | ✅ | ✅ | ✅ | ❌ |

### 2.4 الاحتفاظ والانقطاع (Retention & Churn)

| الميزة | Clari | Gong | Revenue Grid | insightSquared | الموديول |
|---|---|---|---|---|---|
| MRR / ARR | ✅ | ✅ | ✅ | ✅ | ✅ (BizSubscriptionService::computeMrr/computeArrFromMrr — من biz_subscriptions) |
| تفكيك MRR (New/Expansion/Contraction/Churn) | ✅ | ✅ | ✅ | ✅ | ✅ (computeMrrBreakdown من أحداث حقيقية — biz_subscription_events) |
| NRR حرفي | ✅ | ✅ | ✅ | ✅ | 🔶 (يُحسب حرفيًا فقط عند توفر biz_subscriptions؛ وإلا "Not enough data") |
| GRR حرفي | ✅ | ✅ | ✅ | ✅ | 🔶 (تقريب صريح: التوسعات غير قابلة للفصل في النموذج أحادي الصف — computeGrr) |
| Cohort Retention (بشهر أول شراء) | ✅ | ✅ | ✅ | ✅ | ✅ (RevenueRetentionService::computeCohortRetention من صفقات CRM المكسوبة) |
| معدل تكرار الشراء | ✅ | ✅ | ✅ | ✅ | ✅ (computeRepeatPurchaseRate) |
| تحليل أسباب التوقف (Churn Reason) | ✅ | ✅ | ✅ | ✅ | ✅ (RevenueChurnService + RevenueBenchmarkService::classifyChurnReason: صريح/ضمني/مجهول — لا تخمين) |
| استقرار الإيراد المتكرر (كشف فجوات شهرية) | ✅ | ✅ | ✅ | ✅ | ✅ (computeRecurringStability) |

### 2.5 الشذوذ (Anomaly Detection)

| الميزة | Clari | Gong | Revenue Grid | insightSquared | الموديول |
|---|---|---|---|---|---|
| كشف ارتفاعات/انخفاضات غير طبيعية يوميًا | ✅ | ✅ | ✅ | ✅ | ✅ (Z-score على الانحراف عن المتوسط، عتبات 2.0/3.0 — RevenueAnomalyService) |
| تصنيف الخطورة + نطاق متوقع | ✅ | ✅ | ✅ | ✅ | ✅ (high/medium + expected_range) |
| اقتراح سبب (من مصدر مهيمن في اليوم) | ✅ | ✅ | ✅ | ✅ | 🔶 (يقترح السبب فقط إذا هيمن مصدر واحد ≥ 70% من اليوم — وليس حكمًا قطعيًا) |
| توصية بالتحقيق + ربط بإجراء | ✅ | ✅ | ✅ | ✅ | ✅ (recommended_investigation + يتحول لـ Next-Best-Action) |
| دمج الشذوذ في سجل الـInsights | ✅ | ✅ | ✅ | ✅ | ✅ (RevenueInsightPersister::anomalyToInsight) |

### 2.6 المعايير (Benchmarks)

| الميزة | Clari | Gong | Revenue Grid | insightSquared | الموديول |
|---|---|---|---|---|---|
| معايير مقارنة (Percentiles) | ✅ | ✅ | ✅ | ✅ | ✅ (revai_benchmarks: p25/p50/p75، basis=platform/manual، sample_size) |
| معايير مشتقة من بيانات منصة حقيقية مجهولة | 🔶 | ✅ | 🔶 | 🔶 | ✅ (cron/revai_benchmarks_rebuild.php — تجميع حقيقي بلا user_id، حد أدنى 10 حسابات) |
| اتساع المقاييس المعيارية | ✅ | ✅ | ✅ | ✅ | 🔶 (حاليًا يُعاد بناء مقياس واحد فعليًا: growth_percent_monthly فقط) |
| رفض كتابة أرقام عند قلة البيانات | 🔶 | 🔶 | 🔶 | 🔶 | ✅ (لا صف يُكتب ما لم يتوفر الحد الأدنى — "Not enough data" مُحافظ عليه) |

### 2.7 المساعد/النائب الذكي (AI Assistant & Copilot)

| الميزة | Clari | Gong | Revenue Grid | insightSquared | الموديول |
|---|---|---|---|---|---|
| مساعد محادثة للإيرادات | ✅ (Clari Copilot) | ✅ | ✅ | 🔶 | ✅ (RevenueAssistantService: 13 نية، عربي/إنجليزي) |
| **معالجة عربية (Normalization/مرادفات عامية)** | ❌ | 🔶 (NLP متعدد اللغات) | ❌ | ❌ | ✅ (normalizeArabic + مرادفات عربية واسعة + اكتشاف الفترة: شهر/أسبوع/ربع/سنة) |
| إجابة قائمة على بيانات حقيقية فقط (لا تخمين LLM) | 🔶 | 🔶 | 🔶 | 🔶 | ✅ (كل إجابة محسوبة من الخدمات الحقيقية؛ LLM لا يخترع — شرط صريح) |
| سرد LLM طبيعي فوق إجابة محسوبة (Copilot) | ✅ | ✅ | ✅ | 🔶 | 🔶 (RevenueCopilotService: Gemini يعيد صياغة فقط بنفس الأرقام؛ أي فشل LLM → يرجع الرد الصارم) |
| أسئلة متابعة مقترحة (Follow-up chips) | ✅ | ✅ | 🔶 | 🔶 | ✅ (suggestFollowUps — 3 أسئلة لكل نية) |
| إعادة توجيه ذكية عند عدم التطابق | ✅ | ✅ | ✅ | ✅ | ✅ (suggestClosestIntents — "تقصد كذا؟") |
| سيناريو What-if باللغة الطبيعية | ✅ | ✅ | 🔶 | 🔶 | ✅ (استخراج نسبة النمو من السؤال "ماذا لو زادت 20%؟") |
| حفظ سجل الأسئلة/الإجابات | ✅ | ✅ | ✅ | ✅ | ✅ (revai_ai_queries — RevaiAiQuery) |
| **وكلاء AI مستقلون / أتمتة LLM كاملة** | ✅ | 🔶 | ✅ | 🔶 | ❌ (المساعد قائم على قواعد + LLM صياغة فقط) |

### 2.8 الإجراءات والتنفيذ (Next Best Action & Executor)

| الميزة | Clari | Gong | Revenue Grid | insightSquared | الموديول |
|---|---|---|---|---|---|
| Next Best Action مرتبة (Opportunities+Risks+Anomalies) | ✅ | ✅ | ✅ | 🔶 | ✅ (RevenueActionService::rankActions بالخطورة/الثقة/الأثر) |
| تنفيذ فعلي: إنشاء مهمة CRM + إشعار داخلي | ✅ | ✅ | ✅ | ✅ | ✅ (RevenueActionExecutor + ActionExecutor: CrmTaskService + Notification) |
| منع التكرار (Dedup) + نافذة زمنية | ✅ | ✅ | ✅ | ✅ | ✅ (revai_action_executions + alreadyExecuted(7 days)) |
| Dry-run (استعراض بدون كتابة) | ✅ | ✅ | ✅ | ✅ | ✅ (apiActionsExecute?dry_run=1) |
| سجل تنفيذ | ✅ | ✅ | ✅ | ✅ | ✅ (apiActionsHistory) |
| تنفيذ تلقائي دوري مع مفتاح إيقاف | ✅ | ✅ | ✅ | ✅ | ✅ (cron/revenue_action_executor.php + system_settings revai_auto_execute) |
| وسم المصدر بحالة Actioned | ✅ | ✅ | ✅ | ✅ | ✅ (afterExecution يحدّث revai_insights.status='actioned') |
| إشعارات استباقية للمخاطر العالية | ✅ | ✅ | ✅ | ✅ | ✅ (RecomputeRevenueInsightsJob::notifyHighSeverity + منع تكرار 24 ساعة) |

### 2.9 التكاملات (Integrations)

| الميزة | Clari | Gong | Revenue Grid | insightSquared | الموديول |
|---|---|---|---|---|---|
| Webhook Stripe موقّع (HMAC-SHA256) | ✅ | ✅ | ✅ | ✅ | ✅ (StripeWebhookService::verifySignature — يُرفض أي حدث بلا توقيع 401) |
| تخزين سر مشفّر (لا نص صريح) | ✅ | ✅ | ✅ | ✅ | ✅ (Encryption::encrypt + revai_stripe_settings.webhook_secret_enc) |
| استيعاب Idempotent (منع تكرار الأحداث) | ✅ | ✅ | ✅ | ✅ | ✅ (revai_stripe_events فريد لكل user_id+event_id) |
| أحداث Stripe مدعومة | ✅ | ✅ | ✅ | ✅ | 🔶 (3 أحداث فقط: subscription.created / invoice.payment_succeeded / subscription.deleted — بلا refunds/chargebacks) |
| ربط OAuth Connect تلقائي | ✅ | ✅ | ✅ | ✅ | ❌ (إدخال webhook_secret يدويًا لكل مستخدم) |
| سحب/Backfill تاريخي من Stripe | ✅ | ✅ | ✅ | ✅ | ❌ (استيعاب واردة فقط) |
| مزامنة إيرادات تلقائية من مدفوعات/حجوزات المنصة | ✅ | ✅ | ✅ | ✅ | ❌ (rev_revenue_records تُدخل يدويًا عبر RevenueController::createRecord؛ لا يوجد جدول orders/مدفوعات مرتبط) |
| قراءة CRM مباشرة (صفقات/جهات/مراحل) | ✅ | ✅ | ✅ | ✅ | ✅ (RevenueDataGateway قراءة فقط من crm_deals/crm_contacts/crm_leads/crm_pipeline_stages) |
| دمج الإنفاق الإعلاني (ad_campaigns) | 🔶 | 🔶 | 🔶 | ✅ | ✅ (getMarketingSpendTotal: إنفاق يدوي + ad_campaigns.spend) |
| مزامنة الاشتراكات الداخلية | 🔶 | 🔶 | 🔶 | ✅ | 🔶 (من جدول subscriptions الخاص بخطة المستخدم نفسه فقط — صف لكل مستخدم) |

### 2.10 العمارة والخصوصية (Architecture & Trust)

| الميزة | Clari | Gong | Revenue Grid | insightSquared | الموديول |
|---|---|---|---|---|---|
| عزل تينانت صارم (كل استعلام بـ user_id) | ✅ | ✅ | ✅ | ✅ | ✅ (RevenueDataGateway: كل Query مقيّد إجباريًا بـ user_id) |
| طبقة وصول بيانات مركزية واحدة | ✅ | ✅ | ✅ | ✅ | ✅ (كل الوصول عبر RevenueDataGateway فقط — لا SQL مبعثر) |
| كاش متدرج TTL + إبطال عند الأحداث | ✅ | ✅ | ✅ | ✅ | ✅ (RevenueCacheService + ربط events: revenue.updated / crm.deal.won / crm.deal.lost) |
| إعادة حساب خلفية (Jobs + Queue) | ✅ | ✅ | ✅ | ✅ | ✅ (RecomputeRevenueInsightsJob + cron/revenue_intelligence_scan.php) |
| ملخص يومي بالبريد (Daily Digest) | ✅ | ✅ | ✅ | ✅ | ✅ (SendRevenueDigestJob + إمكانية إلغاء الاشتراك digest_daily) |
| سجل تدقيق (Audit Log) موحّد | ✅ | ✅ | ✅ | ✅ | ✅ (revai_insights + revai_ai_queries + ActivityLog + revai_action_executions) |
| **مبدأ "لا أرقام مخترعة" (No invented numbers)** | 🔶 | 🔶 | 🔶 | 🔶 | ✅ (مكتوب ومطبّق في كل Service؛ أي بيانات ناقصة → "Not enough data" مع السبب) |
| تنبيهات قنوات خارجية (Slack/Teams) | ✅ | ✅ | ✅ | ✅ | ❌ (إشعارات داخلية فقط عبر Notification) |

---

## 3. الفجوات الأعلى أولوية (Gap Analysis)

مرتبة حسب (الأثر التنافسي × التكلفة التقريبية × التوافق مع القيود المعمارية).

### 3.1 أولوية عالية

| # | الفجوة | المنافسون الذين يملكونها | الحالة الحالية في الموديول |
|---|---|---|---|
| G1 | **تنبؤ إيرادات بـ ML / Win Probability تنبؤية** (نماذج تتعلم من تاريخ الفوز/الخسارة) | Clari, Gong, Revenue Grid, insightSquared | التنبؤ انحدار خطي إحصائي فقط (RevenueForecastService::computeForecast)؛ الاحتمالية الوحيدة مصدرها win_probability الثابت لمرحلة الـCRM. لا نموذج تعلم. |
| G2 | **الإيراد حسب المنتج/الخدمة** | كلهم | ✅ **مغلقة (2026-08-29)** — `rev_revenue_records` كسب بُعد منتج اختياري (`product_name`/`category`) عبر ميجريشن، و`getRevenueByProduct()` يجمّع الإيراد حسب المنتج/التصنيف مع fallback شفاف للمصدر؛ الإدخال اليدوي (`RevenueController::createRecord`) والواجهة يدعمانه |
| G3 | **ذكاء المحادثات/المكالمات** (تحليل فحوى المكالمات/الإيميلات، كشف مخاطر الصفقة من المحادثة، سبب الفوز/الخسارة من النص) | Gong (جوهر المنتج)، Clari/Revenue Grid بدرجات | لا استيعاب لأي بيانات محادثات/مكالمات إطلاقًا؛ كل التحليل من حقول CRM فقط. |
| G4 | **مزامنة إيرادات تلقائية من المدفوعات/الحجوزات** (بدل الإدخال اليدوي) | كلهم (يتصلون بـ Billing/Payments) | `rev_revenue_records` تُدخل يدويًا عبر RevenueController::createRecord؛ لا يوجد جدول orders/مدفوعات مرتبط يُقرأ منه. هذا يجعل كل مؤشرات "الإيراد الفعلي" معتمدة على دقة الإدخال اليدوي. |

### 3.2 أولوية متوسطة

| # | الفجوة | المنافسون الذين يملكونها | الحالة الحالية في الموديول |
|---|---|---|---|
| G5 | **NRR/GRR حرفي دائم** (تتبع توسّع/انكماش كل اشتراك على حدة) | Clari, insightSquared, Revenue Grid | يُحسبان حرفيًا فقط عند ملء biz_subscriptions/events يدويًا أو عبر Webhook Stripe؛ وGRR تقريب صريح لأن التوسعات غير قابلة للفصل في النموذج أحادي الصف (BizSubscriptionService::computeGrr). |
| G6 | **اتساع المعايير** (Benchmarks) | كلهم (معايير غنية عبر مقاييس/صناعات) | ✅ **مغلقة (2026-08-29)** — `cron/revai_benchmarks_rebuild.php` ينتج 4 مقاييس مستقلة الآن (growth_percent_monthly + win_rate_percent + avg_deal_value + revenue_monthly_avg) لكل منها حدّه الأدنى من الحسابات ورفض كتابة عند قلة البيانات |
| G7 | **أهداف/حصص المبيعات (Quotas/Goals)** | كلهم | ✅ **مغلقة (2026-08-29)** — `RevenueQuotaService` يقرأ `crm_sales_goals` (عزل تينانت) مع الإنجاز الفعلي من `rev_revenue_records` + إشارة منفصلة للصفقات المكسوبة + التنبؤ من الصفقات المفتوحة المقررة في الشهر (وزن بالاحتمالية) + الفجوة والحالة، عبر تبويب "الأهداف والحصص" و`GET /api/revenue-intelligence/quotas` |
| G8 | **عمق تكامل Stripe** | كلهم | لا OAuth Connect تلقائي، لا Backfill تاريخي، و3 أحداث فقط مدعومة (بلا refunds/chargebacks/price_changed). |
| G9 | **ربط بيانات "عمر العميل" بالإيراد** (rev_revenue_records بلا contact_id) | كلهم | إيراد العميل يُشتق حصريًا من صفقات CRM المكسوبة (CustomerRevenueService) — وليس من سجل الإيراد الفعلي المدفوع؛ تفصيل "إيراد لكل عميل" غير كامل. |

### 3.3 أولوية منخفضة / خارج نطاق تنفيذ اليوم

| # | الفجوة | المنافسون الذين يملكونها | الحالة الحالية في الموديول |
|---|---|---|---|
| G10 | **تقارير/داشبورد مخصصة بسحب وإفلات** | insightSquared (جوهر المنتج) | التصدير يدعم أنواع محددة مسبقًا فقط (overview/opportunities/risks/customers/pipeline_forecast)؛ لا Report Builder بصري داخل الموديول. |
| G11 | **قنوات تنبيه خارجية (Slack/Teams/Webhook)** | كلهم | إشعارات داخلية فقط (Notification) + بريد digest؛ لا تكامل Slack/Teams. |
| G12 | **وكلاء AI مستقلون / أتمتة LLM شاملة** | Clari (Agents), Revenue Grid | المساعد قواعد + LLM صياغة فقط؛ لا وكلاء، ولا تحليل LLM حر (وهذا مقصود للحفاظ على "لا اختراع"). |
| G13 | **تطبيقات موبايل أصيلة / بدون اتصال** | كلهم | واجهة ويب مدمجة في اللوحة فقط. |

---

## 4. الميزة التنافسية الطبيعية للموديول (لا يملكها المنافسون العامون)

- **مدمج داخل المنصة بدون ETL**: يقرأ نفس جداول CRM/إعلانات/اشتراكات/إيرادات
  Tourfecto مباشرة عبر `RevenueDataGateway` — لا إعداد تكاملات خارجية، لا خطوط
  بيانات، ولا مشاكل مزامنة. البيانات (الصفقات المكسوبة، الإنفاق، الاشتراكات)
  موجودة فعلًا في نفس النظام.
- **مبدأ "Fact-first AI، لا أرقام مخترعة"**: كل إجابة في المساعد محسوبة من
  الخدمات الحقيقية، والـLLM (Gemini) مقيّد بـ Prompt صارم يعيد صياغة نفس
  الأرقام فقط (RevenueCopilotService::buildPrompt) — عكس مساعدي Clari/Gong
  الذين قد يولّدون نصوصًا حرة. عند أي فشل LLM يرجع النظام للرد الصارم بدل
  التوقف أو الاختلاق.
- **عربي أولًا**: Normalization عربي (همزة/ألف، تاء مربوطة/هاء، ألف مقصورة/
  ياء) + مرادفات عامية + اكتشاف الفترة من السؤال — لا يملكه أي من المنافسين
  الأربعة بنفس المستوى. واجهة RTL كاملة بخط Tajawal.
- **معايير منصّية حقيقية مجهولة**: Benchmark تُشتق من مجاميع فعلية عبر كل
  الحسابات دون كشف هويات (cron/revai_benchmarks_rebuild.php مع حد أدنى للعينة
  ورفض الكتابة عند قلة البيانات) — بدل أرقام تسويقية أو اختراع.
- **خفيف وبدون تبعيات**: PHP خالص بلا Namespaces/Composer وقت تشغيل، يعمل على
  استضافة مشتركة (cPanel) بنفس آلية بقية موديولات المنصة — خلافًا لبنى
  Clari/Gong السحابية المغلقة.
- **تكامل Stripe آمن لكل تينانت**: سر Webhook مشفّر AES-256-CBC لكل مستخدم،
  تحقق توقيع HMAC-SHA256، واستيعاب Idempotent عبر جدول أحداث فريد — أمان
  معياري دون كشف الأسرار في أي استجابة.
- **التكلفة**: مجاني ضمن اشتراك المنصة (لا لكل مقعد) — ميزة تسعيرية كبيرة مقابل
  Clari/Gong ذات التسعير المرتفع.

---

## 5. منهجية التحليل

- **مخزون الميزات**: مسح كامل لملفات
  `app/Services/RevenueIntelligence/` (22 خدمة + Gateway + Mapper + Webhook)،
  و`app/Controllers/RevenueIntelligenceController.php` (28 مسار API + واجهة
  تبويبات)، والنماذج (`RevaiForecast`/`RevaiInsight`/`RevaiAiQuery`)، و5
  Migrations (rev_revenue_records، revai_forecasts/insights/queries،
  biz_subscriptions/sales_teams/benchmarks، dashboard_prefs/stripe_settings،
  action_executions)، والـJobs (`RecomputeRevenueInsightsJob`/
  `SendRevenueDigestJob`)، والـCron (scan/action_executor/benchmarks_rebuild)،
  والـEvents (`app/Config/revenue_intelligence_events.php`)، والـRoutes
  (`app/routes/api.php`)، واختبارات `tests/Legacy/RevenueIntelligenceTest.php`
  (80+ حالة).
- **بيانات المنافسين**: المعرفة العامة بالوظائف الموثّقة رسميًا لكل من Clari
  (Forecast Automation + Copilot)، Gong Revenue Intelligence (ذكاء
  المحادثات)، Revenue Grid/Salesloft (التنبؤ والإيرادات)، insightSquared
  (التحليلات). لا يُستخدم أي رقم تسويقي كادعاء تفوق.
- **قاعدة التصنيف**: ✅ فقط لما يوجد تنفيذ فعلي في الكود؛ 🔶 للوظائف
  الإحصائية/المشروطة/المحدودة (مثل NRR/GRR المشروطة ببيانات الاشتراكات، أو
  Copilot LLM المقيّد بصياغة الإجابة)؛ ❌ للغائب. كل ما يتطلب ML أو LLM حرًا
  صُنّف جزئيًا/محدودًا لأنه إحصائي فقط أو معتمد على Credits منصة.
