# التحليل التنافسي — Reputation Management (Tourfecto) — 2026-08-29

هذا التحليل يقارن موديول "إدارة السمعة" (Reputation) المدمج في منصة Tourfecto AI — وهو موديول مسؤول عن مراجعات جوجل وTripAdvisor، وطلبات المراجعة، والردود الذكية، وتحليل المشاعر، وقواعد الرد الآلي، ولوحة معلومات السمعة — مقابل الحلول التجارية العالمية الرائدة في هذا المجال.

تعتمد المقارنة على مخزون فعلي للكود المصدري داخل المستودع الحالي (خدمات، وحدات تحكم، نماذج، ترحيلات، مسارات، مهام cron) وليس على مواصفات تسويقية. كل ميزة مذكورة في هذا التقرير إما موثّقة بملف مصدري حقيقي أو معلمة كفجوة مفقودة. التقديرات التسويقية للمنافسين تُستخدم كسياق فقط ولا تُعتبر التزامًا مضمونًا.

مصطلحات المستوى: ✅ موجود بشكل فعلي في الكود · 🔶 جزئي/محدود/مشروط · ❌ غير موجود.

---

## 1. نطاق المقارنة

| البُعد | المنافسون (Birdeye / Podium / Trustpilot Business / Reputation.com) | الموديول الحالي |
|---|---|---|
| السوق المستهدف | عالمي، عملاء متعددو اللغات والقطاعات | سوق عربي أولًا، قطاع السياحة والضيافة ووكالات السفر (Tourfecto) |
| النموذج التقني | SaaS سحابي مستقل لكل منصة | موديول مدمج داخل منصة Tourfecto (مصدر مفتوح في المستودع، PHP/PDO بلا تبعيات خارجية) |
| مصادر المراجعات | Birdeye: أكثر من 100 مصدر · Podium: جوجل/فيسبوك وغيرها · Reputation.com: تجميع واسع | Google Business (OAuth لكل عميل) + TripAdvisor (مفتاح مشترك، أحدث 5 فقط) |
| التسعير | اشتراك شهري لكل موقع/مستخدم (Birdeye، Podium، Reputation.com) أو نموذج freemium حسب الحجم (Trustpilot) | مجاني ضمن اشتراك المنصة + نظام Credits داخلي للردود المولدة بالذكاء الاصطناعي (review_credits عبر SubscriptionValidator) |
| نشر الطلب/الرد | SMS + إيميل + روابط QR + صفحات هبوط | واتساب (UltraMsg) + إيميل فقط، بدون SMS أو QR أو صفحات هبوط |

---

## 2. ملخص وضع الموديول

### 2.1 مراجعات متعددة المصادر (Review Sources & Sync)

| الميزة | Birdeye | Podium | Trustpilot Business | Reputation.com | الموديول |
|---|---|---|---|---|---|
| مزامنة مراجعات Google Business | ✅ | ✅ | 🔶 | ✅ | ✅ — GoogleReviewSyncService مع polling دوري (cron كل 6 ساعات) وترحيل كامل pageToken، وتفريغ dedup عبر unique_platform_review |
| مزامنة مراجعات TripAdvisor | ✅ | ❌ | ❌ | ✅ | 🔶 — TripAdvisorReviewSyncService لكن قراءة فقط (read-only) وتجلب أحدث 5 مراجعات فقط لكل موقع، بمفتاح API مشترك واحد لكل المنصة |
| مصادر أخرى (Booking/Expedia/Trustpilot/فيسبوك/…) | ✅ | ✅ | ✅ | ✅ | ❌ — قيم ENUM فقط في الترحيل (source_platform) بلا أي كود مزامنة (Booking/Expedia/Trustpilot) |
| Webhook فوري من المنصات | ✅ | ✅ | 🔶 | ✅ | 🔶 — لا يوجد webhook وارد من جوجل/TripAdvisor؛ الاعتماد على polling مجدول (sync_google_reviews.php) |
| إشعارات المراجعات الجديدة | ✅ | ✅ | ✅ | ✅ | ✅ — إشعارات داخلية فورية على المراجعات المستلمة عبر webhook/المزامنة |
| كشف تكرار المراجعة/المراجع | ✅ | ✅ | ✅ | ✅ | ✅ — unique_platform_review + نافذة منع التكرار DUPLICATE_WINDOW_HOURS=24 في ReviewRequestService |

### 2.2 طلبات المراجعة (Review Requests)

| الميزة | Birdeye | Podium | Trustpilot Business | Reputation.com | الموديول |
|---|---|---|---|---|---|
| طلب مراجعة مجدول بعد الخدمة | ✅ | ✅ | ✅ | ✅ | ✅ — جدولة بسيطة delay_hours من تاريخ نهاية الخدمة + Endpoint Smart Timing (getSmartTiming) |
| إرسال عبر واتساب | 🔶 | ✅ | ❌ | 🔶 | ✅ — عبر ChatManager::sendMessageForWebsite (اتصال UltraMsg لكل موقع) |
| إرسال عبر إيميل | ✅ | ✅ | ✅ | ✅ | ✅ — عبر Mailer/EmailChannelAPI |
| إرسال عبر SMS | ✅ | ✅ | ❌ | ✅ | ❌ — Twilio متاح في موديول CRM (CrmSmsService) لكن غير مربوط بطلبات المراجعة |
| QR code / روابط قصيرة / صفحات هبوط | ✅ | ✅ | ✅ | ✅ | ❌ — غير موجود |
| تذكيرات تلقائية | ✅ | ✅ | ✅ | ✅ | ✅ — حالات sent → reminded عبر cron (process_review_requests.php) بحد أقصى MAX_SEND_ATTEMPTS=3 |
| قوالب رسائل | ✅ | ✅ | ✅ | ✅ | ✅ — جدول review_request_templates (friendly/professional/short/thank_you/custom) |
| مساعد ذكاء اصطناعي لصياغة الرسالة | ✅ | ✅ | ✅ | ✅ | 🔶 — aiAssist عبر GeminiClient (مفتاح المنصة، يعتمد على Credits) |
| Opt-Out دائم | ✅ | ✅ | ✅ | ✅ | ✅ — جدول review_request_opt_out مستقل عن حالة الطلب |
| الربط مع CRM (بعد ربح صفقة) | ✅ | ✅ | 🔶 | ✅ | ✅ — maybeCreateFromCrmDeal من مصدر crm_deal_id عند Deal Won |
| Attribution (ربط الطلب بالمراجعة الفعلية) | ✅ | ✅ | ✅ | ✅ | ✅ — matched_review_id → reviews.id |
| تحليلات الحملة (حتى يتم تحديثها) | ✅ | ✅ | ✅ | ✅ | 🔶 — تحليلات عند عينة لا تقل عن MIN_SAMPLE_FOR_ANALYTICS=3 وإلا تعيد not_enough_data |
| تصدير CSV لبيانات الطلبات | ✅ | ✅ | ✅ | ✅ | ✅ — exportCsv لطلبات المراجعة فقط (لا يشمل المراجعات نفسها) |

### 2.3 الرد على المراجعات (Review Replies)

| الميزة | Birdeye | Podium | Trustpilot Business | Reputation.com | الموديول |
|---|---|---|---|---|---|
| إرسال رد فعلي إلى Google | ✅ | ✅ | 🔶 | ✅ | ✅ — sendReply حقيقي عبر GoogleBusinessAPI (PUT replies) وليس وهميًا |
| الرد على TripAdvisor | ✅ | ❌ | ❌ | ✅ | 🔶 — لا يوفّر TripAdvisor API للردود؛ الإرسال يعيد خطأ واضحًا والعميل ينسخ ردًا يدويًا (إرشادات Attribution إلزامية) |
| توليد رد بالذكاء الاصطناعي | ✅ | ✅ | ✅ | ✅ | 🔶 — ReplyGenerator عبر TourfectoAIEngine (LLM خارجي + Credits) مع fallback لقوالب ثابتة عند فشل الاستدعاء |
| قوالب ردود ثابتة حسب المشاعر/المنصة | ✅ | ✅ | ✅ | ✅ | ✅ — generateFromTemplate كبديل مضمون |
| سير عمل موافقة (مسودة → اعتماد → إرسال) | ✅ | ✅ | ✅ | ✅ | ✅ — حالات reply_status: pending/approved/rejected + dismiss/approve من الواجهة |
| قواعد رد آلي (rating/sentiment → auto_reply/notify) | ✅ | ✅ | 🔶 | ✅ | ✅ — gbp_reply_rules (auto_reply/custom/notify + auto_reply_and_notify) تُطبّق عبر cron/apply_reply_rules.php على مراجعات جوجل غير المجابة خلال 7 أيام |
| تعيين مسؤول/تذكير بمراجعة حرجة | ✅ | ✅ | 🔶 | ✅ | 🔶 — إشعارات عامة فقط بلا تعيين مالك/مسؤول للرد |
| تخصيص لغة الرد | ✅ | ✅ | 🔶 | ✅ | 🔶 — auto_reply_language وحقل اللغة، لكن التوليد يعتمد على قدرات الـ LLM الخارجي |

### 2.4 تحليل المشاعر (Sentiment Analysis)

| الميزة | Birdeye | Podium | Trustpilot Business | Reputation.com | الموديول |
|---|---|---|---|---|---|
| تصنيف المشاعر (إيجابي/محايد/سلبي) | ✅ | ✅ | ✅ | ✅ | ✅ — SentimentAnalyzer مع حفظ sentiment_score/confidence في reviews |
| تحليل عبر LLM | ✅ | ✅ | ✅ | ✅ | 🔶 — TourfectoAIEngine::analyzeSentiment (LLM خارجي + Credits) |
| fallback بدون LLM | 🔶 | 🔶 | 🔶 | 🔶 | ✅ — قوائم كلمات مفتاحية إيجابية/سلبية (عربي + إنجليزي) عند تعذر الاستدعاء |
| كشف اللغة | ✅ | ✅ | ✅ | ✅ | 🔶 — detectLanguage مبني على الكلمات المفتاحية لا نموذج لغوي |
| استخراج مواضيع/أسباب المراجعات (Topics) | ✅ | ✅ | ✅ | ✅ | 🔶 — كلمات مفتاحية ثابتة في واجهة النظرة العامة، لا تحليل ديناميكي حقيقي |

### 2.5 لوحة المعلومات والتحليلات (Dashboard & Analytics)

| الميزة | Birdeye | Podium | Trustpilot Business | Reputation.com | الموديول |
|---|---|---|---|---|---|
| إحصائيات عامة وحسب المنصة | ✅ | ✅ | ✅ | ✅ | ✅ — showOverview/showStats مع مؤشرات: إجمالي المراجعات 30 يوم، متوسط التقييم، السلبية، المسودات المعلقة |
| اتجاه زمني (Trend) | ✅ | ✅ | ✅ | ✅ | ✅ — رسم بياني 8 أسابيع مع فلاتر (الكل/سلبي/تريب أدفايزر) |
| KPIs متقدمة (velocity، response rate، first response time) | ✅ | ✅ | 🔶 | ✅ | ✅ — GbpReputationAnalyticsService (متوسط التقييم واتجاهه، سرعة المراجعات، نسبة الرد، أول زمن رد، توزيع التقييم، مزيج المشاعر) |
| إشارات مخاطر (Risk Signals) | ✅ | ✅ | 🔶 | ✅ | ✅ — انخفاض مفاجئ في التقييم، قفزة مراجعات، قفزة سلبية، نمط مريب |
| Local SEO Audit + اكتمال الملف | ✅ | 🔶 | ❌ | ✅ | ✅ — GbpLocalSeoAuditService + GbpProfileScoreService (نقاط 0–100 حتمية) |
| حصة الظهور (Share of Voice) | ✅ | ✅ | ❌ | ✅ | 🔶 — متاح فقط عند تمكين Google Places (يُعاد available=false مع سبب عند غيابه) |
| مقارنة مع منافسين محليين | ✅ | ✅ | ❌ | ✅ | 🔶 — GbpCompetitorBenchmarkService مقابل حتى 5 منافسين حقيقيين عبر Places Text Search، مشروط بتوفر المفتاح |
| تصدير تقارير المراجعات (CSV/PDF) | ✅ | ✅ | ✅ | ✅ | ❌ — لا يوجد تصدير للمراجعات/التحليلات (فقط CSV لطلبات المراجعة) |

### 2.6 الأتمتة (Automation)

| الميزة | Birdeye | Podium | Trustpilot Business | Reputation.com | الموديول |
|---|---|---|---|---|---|
| جدولة طلبات المراجعة | ✅ | ✅ | ✅ | ✅ | ✅ — cron/process_review_requests.php يعالج المستحقة تلقائيًا |
| تذكيرات تلقائية | ✅ | ✅ | ✅ | ✅ | ✅ — نفس cron مع عدّاد محاولات وحالات فشل |
| قواعد رد آلي على مراجعات جوجل | ✅ | ✅ | 🔶 | ✅ | ✅ — cron/apply_reply_rules.php بتنبيه أو رد آلي فعلي (يتطلب اتصالًا صالحًا وتوكنًا صالحًا) |
| Auto-pilot من Webhook | ✅ | ✅ | ❌ | ✅ | ✅ — botSettings.auto_pilot في ReputationManager::processWebhook |
| محفّز CRM (Deal Won) | ✅ | ✅ | ❌ | ✅ | ✅ — إنشاء طلب مراجعة تلقائي عند تحويل صفقة CRM |
| جدولة منشورات Google Business (GBP) | ✅ | 🔶 | ❌ | ✅ | ✅ — GbpContentService مع جدولة منشورات (update/offer/event/product) عبر OAuth نفسه |
| بنشماركات مجدولة على مستوى المنصة | ✅ | ✅ | ❌ | ✅ | ❌ — revai_benchmarks_rebuild.php خارج نطاق موديول السمعة الحالي |

### 2.7 التكاملات (Integrations)

| الميزة | Birdeye | Podium | Trustpilot Business | Reputation.com | الموديول |
|---|---|---|---|---|---|
| Google Business Profile (OAuth) | ✅ | ✅ | 🔶 | ✅ | ✅ — OAuth لكل عميل على حدة مع تجديد توكن محمي بقفل (SELECT FOR UPDATE) وعزل تينانت كامل |
| TripAdvisor Content API | ✅ | ❌ | ❌ | ✅ | 🔶 — مفتاح مشترك واحد لكل المنصة (غير قابل للربط لكل عميل) وقراءة فقط |
| واتساب (UltraMsg) | 🔶 | 🔶 | ❌ | 🔶 | ✅ — عبر اتصال واتساب لكل موقع داخل المنصة |
| إيميل (Mailer) | ✅ | ✅ | ✅ | ✅ | ✅ — قنوات إيميل عامة للمنصة |
| Google Places (للبنشمارك/حصة الظهور) | ✅ | ✅ | ❌ | ✅ | 🔶 — يتطلب مفتاح Places إضافيًا وإلا تُعطّل الميزة بأسباب واضحة |
| LLM خارجي (Gemini/TourfectoAIEngine) | ✅ | ✅ | ✅ | ✅ | 🔶 — خارجي ويعتمد على أرصدة Credits للمنصة وليس نموذجًا محليًا |
| SMS (Twilio) لطلبات المراجعة | ✅ | ✅ | ❌ | ✅ | ❌ — متاح في CRM لكن غير مربوط بالسمعة |
| Booking/Expedia/Trustpilot API | ✅ | ✅ | ✅ | ✅ | ❌ — غير موجود إطلاقًا |

### 2.8 عرض المراجعات (Review Display & Widgets)

| الميزة | Birdeye | Podium | Trustpilot Business | Reputation.com | الموديول |
|---|---|---|---|---|---|
| واجهات داخلية (قائمة/تفاصيل/نظرة عامة) | ✅ | ✅ | ✅ | ✅ | ✅ — صفحات showOverview/showReviews/showStats |
| Rating Widgets عامة على مواقع العملاء | ✅ | 🔶 | ✅ | ✅ | ❌ — لا يوجد Rating Widget قابل للدمج |
| TrustBox / مقتطفات جوجل / Schema.org | ✅ | 🔶 | ✅ | ✅ | ❌ — لا يوجد Schema/SEO للمراجعات |
| عرض مراجعات داخل Website Builder | 🔶 | 🔶 | 🔶 | 🔶 | ❌ — لم يُعثر على أي ربط بين المراجعات وWebsiteBuilderService |

### 2.9 الصلاحيات وعزل البيانات (Permissions & Tenant Isolation)

| الميزة | Birdeye | Podium | Trustpilot Business | Reputation.com | الموديول |
|---|---|---|---|---|---|
| AuthMiddleware على كل النقاط | ✅ | ✅ | ✅ | ✅ | ✅ — جميع مسارات /api/reputation و/api/gbp و/api/review-requests محمية |
| عزل المستخدم والموقع (user_id + website_id) | ✅ | ✅ | ✅ | ✅ | ✅ — كل الاستعلامات مفلترة بالموقع الحالي مع تحقق ملكية (مثل finalizeTripAdvisorConnection) |
| قيود اشتراك (Review Credits) | ✅ | ✅ | ✅ | ✅ | ✅ — SubscriptionMiddleware:require_review_credits على توليد/إرسال الردود مع محفظة wallet |
| صلاحيات أدوار تفصيلية للموديول | ✅ | ✅ | ✅ | ✅ | 🔶 — لا توجد صلاحيات لكل ميزة (من يرسل ردًا/من يدير الحملات) |
| سجل تدقيق (Audit Log) | ✅ | ✅ | 🔶 | ✅ | 🔶 — GbpAuditLogger للـ GBP module فقط، لا يوجد سجل لطلبات المراجعة |
| Partner API بمجالات محدودة | ✅ | ✅ | ✅ | ✅ | ✅ — نطاقات read-only مثل reputation:read لشركاء محددين |

---

## 3. الفجوات الأعلى أولوية

### فجوات عالية الأولوية

| # | الفجوة | المنافسون الذين يقدمونها | الحالة الحالية | الأولوية |
|---|---|---|---|---|
| G1 | تعدد مصادر المراجعات خارج جوجل/TripAdvisor (Booking، Expedia، Trustpilot، فيسبوك، OTA أخرى) | Birdeye (100+ مصدر)، Podium، Reputation.com | ✅ Google + 🔶 TripAdvisor (أحدث 5 فقط) فقط؛ بقية المنصات مجرد قيم ENUM بلا كود | عالية |
| G2 | قنوات استقطاب مراجعات إضافية: SMS + QR code + روابط قصيرة + صفحات هبوط | Podium (SMS جوهر منتجها)، Birdeye، Reputation.com | ✅ **مغلقة (2026-08-29)** (جزئيًا) — قناة SMS عبر `CrmSmsService` (Twilio) أُضيفت لكل مسارات طلبات المراجعة (`ReviewRequestService`) مع رسائل عربية واضحة عند عدم التهيئة وفشل الإرسال | عالية |
| G4 | تحليل موضوعات/عوامل ديناميكي (Topic Extraction) بدل الكلمات المفتاحية الثابتة | Birdeye، Trustpilot Insights، Reputation.com | ✅ **مغلقة (2026-08-29)** — `ReviewTopicExtractor` (Server-Side، ثنائي اللغة، بلا LLM) يستخرج الموضوعات من نصوص المراجعات ويجمعها حسب المشاعر/التقييم ويشتق اقتراحات التحسين، معروضة في النظرة العامة | عالية |
| G5 | تصدير المراجعات والتحليلات (CSV/PDF/Excel) | جميع المنافسين | ✅ **مغلقة (2026-08-29)** — `exportReviewsCsv` في ReputationController (فلاتر + تحقق ملكية + صف ملخص + حذف معلومات المراجع) مع مسار `GET /api/reputation/export-reviews` | عالية |
| G6 | ربط TripAdvisor/قنوات لكل عميل بدل مفتاح مشترك واحد (عزل الإعدادات + توسيع النطاق) | Birdeye، Reputation.com (self-service onboarding لكل عميل) | مفتاح API مشترك واحد لكل المنصة | عالية |

### فجوات متوسطة الأولوية

| # | الفجوة | المنافسون الذين يقدمونها | الحالة الحالية | الأولوية |
|---|---|---|---|---|
| G7 | صلاحيات أدوار تفصيلية للموديول (من يوافق على الردود، من يدير الحملات) | Birdeye، Podium، Reputation.com | AuthMiddleware + عزل تينانت فقط، لا RBAC | متوسطة |
| G8 | سجل تدقيق لطلبات المراجعة والردود والإعدادات | Birdeye، Reputation.com | GbpAuditLogger للـ GBP module فقط | متوسطة |
| G9 | نشرة تقارير دورية آلية (Email Digest أسبوعي/شهري) | Birdeye، Reputation.com | غير موجود | متوسطة |
| G10 | إدارة حملات/مواقع متعددة في لوحة موحدة مع مقارنة بين الفروع | Birdeye، Reputation.com | كل صفحة مرتبطة بموقع واحد (website_id) | متوسطة |
| G11 | بنشمارك/حصة ظهور دون الاعتماد على مفتاح Places إضافي | Birdeye، Reputation.com | available=false مع سبب عند غياب المفتاح | متوسطة |

### فجوات منخفضة الأولوية

| # | الفجوة | المنافسون الذين يقدمونها | الحالة الحالية | الأولوية |
|---|---|---|---|---|
| G12 | نماذج مشاعر/ترجمة محلية (Native ML) بدل LLM خارجي | Birdeye، Reputation.com | LLM خارجي + كلمات مفتاحية؛ تكلفة تطوير مرتفعة | منخفضة |
| G13 | تطبيقات موبايل / إشعارات push | Podium (تطبيق)، Birdeye | غير موجود | منخفضة |
| G14 | إدارة الأسئلة والأجوبة على Google Business Profile (Q&A) | Reputation.com | غير موجود | منخفضة |

---

## 4. الميزة التنافسية الطبيعية للموديول

1. **مدمج داخل منصة Tourfecto لا حلاً مستقلًا**: طلبات المراجعة مربوطة فعليًا بصفقات CRM (maybeCreateFromCrmDeal عند Deal Won) مع Attribution حقيقي للمراجعة، وموثقة في نظام الإشعارات والاشتراكات — تجربة متكاملة لا يوفرها منافس مستقل بسهولة لعميل واحد.
2. **عزل تينانت حقيقي لكل عميل على جوجل**: OAuth مستقل لكل موقع (platform_connections) مع تجديد توكن محمي بقفل (SELECT FOR UPDATE) — لا حساب جوجل مشترك بين العملاء، خلافًا لتكاملات مفتاح مشترك مثل TripAdvisor.
3. **تخصص سياحي عربي**: يستهدف المصدرين الحاسمين للفنادق ووكالات السفر (جوجل + TripAdvisor) بواجهات RTL وثنائية اللغة (عربي/إنجليزي) وقوائم كلمات مفتاحية للمشاعر بالعربية — سوق تفتقر إليه المنافسون العالميون.
4. **تحليلات صادقة "بدون أرقام مزيّفة"**: كل الميتركس مبنية من بيانات حقيقية (Reviews + Performance API)، مع حد أدنى للعينة (MIN_SAMPLE_FOR_ANALYTICS=3) وإعادة available=false مع سبب واضح عند غياب التكاملات (Places) — خلافًا للتقارير الترويجية.
5. **ذكاء اصطناعي بمعطيات حقيقية فقط**: GbpAIInsightsService يولّد رؤى من الأرقام الفعلية ويُمنع من اختلاق الأرقام (prompt صريح)، مع fallback مضمون للقوالب عند فشل الـ LLM — موثوقية نادرة في سوق هذه الأدوات.
6. **تشغيل بدون تبعيات خارجية**: PHP/PDO نقي بلا حزم Composer، قابل للتشغيل في أي بيئة مشتركة دون إعداد معقد، ويشمل مجموعة عريضة مدمجة (مزامنة + طلبات + ردود + قواعد + تحليلات + مراجعة SEO + منشورات GBP) في موديول واحد.

---

## 5. منهجية التحليل

- **مخزون الميزات**: مسح كامل للمستودع — خدمات app/Services/Reputation وapp/Services/GoogleBusiness، وحدات التحكم ReputationController وReviewRequestController، النماذج، ترحيلات database/migrations، مسارات app/routes (api.php / web.php / api_ADDITIONS.php)، ومهام cron (process_review_requests.php، sync_google_reviews.php، sync_tripadvisor_reviews.php، apply_reply_rules.php).
- **بيانات المنافسين**: من الوثائق والمواصفات العامة الموثقة لـ Birdeye وPodium وTrustpilot Business وReputation.com، دون ادعاء القدرات غير المؤكدة.
- **مستوى التنفيذ**: كل ميزة صُنفت ✅/🔶/❌ بناءً على وجود كود حقيقي يُنفذها فعليًا، وليس على وجود أسماء أو حقول في قاعدة البيانات فقط (مثل ENUM لمنصات بلا كود مزامنة).
- **حدود التحليل**: الحصة الظهور والبنشمارك تحتاج مفتاح Google Places للتقييم العملي؛ قدرة توليد النصوص/المشاعر تعتمد على توفر أرصدة Credits للـ LLM الخارجي، وهو ما وُسم بـ "جزئي".
