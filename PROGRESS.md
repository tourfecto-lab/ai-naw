# PROGRESS — الخطوة 4: Ads (5) + CRM (1) + AI Chat (2)

## دمج البنود الوظيفية الـ3 على main + تأجيل redesign (2026-09-01)
- **ماذا اتدمج على main:** البنود الـ3 (Webhook تتبع التسليم، Double Opt-in،
  نموذج تواصل/حجز) وصلت main بالـ cherry-pick من `redesign/frontend-all` في
  3 commits (الأصول: `b8cecee`, `0b77d21`, `d4d3984`). سبب الـ cherry-pick:
  فرع `redesign/frontend-all` كان مخلوطًا — الـ 3 commits دي مبنية فوق 4
  commits لإعادة تصميم واجهات (ads/crm/chat) غير مرتبطة بهذه المهمة.
- **ماذا اتأجل (وليه):** الـ 4 commits UI (`2a55bf7` ads، `c06d1f7` fix ads،
  `6c48f94` crm، `7a67c47` chat) — شغل حقيقي "extract views + JS" مفحوص
  (كل المسارات/الـ endpoints محفوظة، lint/phpstan نظيفان، بلا regression)
  **لكن لم يُثبت طلبه بطلب صريح من الفريق**، ويعدّل ملفًا مشتركًا حساسًا
  (`app/Core/Controller.php::renderPanelPage`) بيأثر على كل اللوحة. نقلت على
  فرع `redesign/pending-review` (نفس الـ 4 commits فوق main) لمراجعة واعية
  قبل أي دمج مستقبلي على main — مش جزء من main حاليًا.
- **نتيجة الفصل:** المحتوى كله محفوظ — الوظيفي على main، والـ redesign على
  `redesign/pending-review`؛ وحذفت `redesign/frontend-all` عشان محدش يدمجه
  تاني بالغلط.
- **التحقق:** بعد دمج الوظيفي على main: lint (817 OK) + phpstan (No errors) +
  phpunit **1147/18443** — صفر regression (4 فشلات Revenue الوحيدة سابقة
  الوجود على main الأصلية: TEST_PERIOD=2026-08 انتهى تاريخه).

## البنود الـ3 (مكتملة 2026-09-01): Webhook تتبع التسليم + Double Opt-in + نموذج تواصل/حجز
- **هدف:** 3 إضافات فوق الكود الشغال على فرع `redesign/frontend-all` بلا حذف/
  إعادة بناء أي موديول، كل بند بـ commit منفصل + push (b8cecee / 0b77d21 / التالي).
- **منفّذ:**
  - **بند 1 — Webhook تتبع الارتدادات/الشكاوى:** `POST /webhooks/email/delivery-status/{user_id}`
    بتوقيع حسب المزوّد (SendGrid/Mailgun/Postmark/عام) → `handleDeliveryWebhook`
    يفعّل `recordDeliveryIssue` (كانت غير مستدعاة)؛ ميجريشن `delivery_webhook_*` +
    قسم "Webhook تتبع التسليم" في شاشة SMTP؛ إصلاح bug قديم في
    `EmailSubscriber::$fillable` (كان يمنع حفظ `bounce_count`).
  - **بند 2 — Double Opt-in:** حالة `pending_optin` (ميجريشن ENUM `ALTER MODIFY`
    بلا مساس بالقيم الموجودة) للاشتراك العام بس (source=form)، تأكيد بتوكن عبر
    Mailer، تفعيل → `subscribed` + `optin_ip/optin_at`؛ الاستعلامات الجماهيرية
    تستثني `pending_optin`؛ استيراد/إدخال الأدمن يبقى `subscribed` فورًا.
  - **بند 3 — نموذج تواصل/حجز:** `siteLeadFormHtml` على الرئيسية (جولات/فندق)
    يرسل لـ `submitLead` الموجود (`visitor_name/phone/email/message`) بدون تكرار
    CSRF؛ زرار "احجز الآن" في الهيرو يظهر فقط عند وجود `crm_products`
    (website_id, is_active=1) ويوجّه لـ `#tours`/`#rooms`؛ قاعدتا `.ws-btn-outline`
    + فاصل أزرار في `generated-site.css`.
- **التحقق:** 3 ملفات اختبار تكامل جديدة — EmailDeliveryWebhook (6)،
  EmailDoubleOptin (6)، WebsiteLeadForm (12) — كلها OK متكررة؛ lint (852 ملف) +
  phpstan بلا أخطاء؛ E2E حي للـ opt-in (subscribe→confirm) نجح.
- **ملاحظة:** قيد SMTP الخام موثّق في الشاشة (لا webhooks رسمية من سيرفر SMTP
  نفسه — يُستخدم endpoint المزوّد أو التسجيل اليدوي).
- **Commits:** بند 1 `b8cecee` + بند 2 `0b77d21` + بند 3 (آخر commit) — تفاصيل
  كاملة في CHANGELOG.md.

## الموديول 9 (مكتمل 2026-08-31): اختبارات SearchConsole + Integrations + SocialMedia بـ fakes
- **هدف:** تغطية الأنظمة الثلاثة بمصادر حقيقية **وصفر شبكة** — حقن
  `?callable $transport` في عملاء HTTP (نفس بنية رد curl — نمط حقن م3).
- **منفّذ (إضافات بلا تغيير سلوك الإنتاج):**
  - **9a — SearchConsole:** `GoogleSearchConsoleAPI` (connect/auth-fail/مالفورم/
    شبكة/analytics+summary) + `GoogleSearchConsoleIntegration::request()` (بدون
    token رفض مبكر + dispatch عبر partial mock).
  - **9b — Integrations:** `BaseIntegrationService` (constructor transport +
    `httpJson/httpForm` على `dispatch/rawRequest`) + `MixpanelService::track`
    على `rawRequest` — تغطية Slack/Algolia/Calendly/HubSpot/Mixpanel/OneSignal/
    Zapier/Zoom (headers/URL/body الصح + ok:false + HTTP errors + شبكة + Zoom
    token exchange).
  - **9c — SocialMedia:** `MetaSocialAPI`/`TikTokAPI`/`YouTubeAPI` (transport)
    — listPages/نشر فيسبوك وانستجرام/video containers/checkPublishStatus/
    checkVideoStatus + `SocialPostService::generateCaption` عبر GeminiClient وهمي.
- **التحقق:** 3 ملفات جديدة (52 اختبار/203 assertion): SearchConsole 12/48،
  Integrations 18/69، SocialMedia 22/86. **1111/18199 OK**؛ lint (814 ملف) +
  phpstan بلا أخطاء.
- **ملاحظة:** placeholders في `PublishSocialPostJobTest` (6) خارج نطاق الموديول
  (محتاجين DI للـ Job نفسه) — تُترك كما هي.
- **Commit:** منفصل + push (تفاصيل في CHANGELOG.md).

## الموديول 8 (مكتمل 2026-08-31): اختبارات تكامل OAuth Login (Google/Facebook/Microsoft/Apple)
- **هدف:** تغطية تدفقات تسجيل الدخول الاجتماعي كاملة بمصادر حقيقية **وصفر شبكة**
  (حقن transport وهمي بنفس بنية رد curl — نمط حقن WordPressPublisher من م3).
- **منفّذ (إضافات بلا تغيير سلوك الإنتاج):**
  - **8a — الحقن:** `SocialLoginClient::__construct($provider, ?callable $transport)`
    و`AppleSignInClient::__construct(?callable $transport)` + `httpRequest()` خاص
    بيفضّل الـ fake قبل curl (نفس الخيارات/الهيئات بالظبط).
  - **8b — السيناريوهات:** نجاح (تبادل+بروفايل/فك id_token)؛ توكن غير صالح/منتهي
    (400/401)؛ فشل شبكة المزوّد الخارجي؛ replay (نفس الكود مرتين — الأولى تنجح
    والثانية `invalid_grant` + `verifyOAuthState` أحادية الاستخدام)؛ Facebook
    (`access_token` في query)؛ Microsoft (tenant في URL)؛ Apple (client_secret
    JWT ES256 من مفتاح EC + فك id_token + رفض المالفورم/نقص sub).
- **التحقق:** `tests/Integration/OAuthLoginModuleIntegrationTest.php` جديد
  (16 اختبار/56 assertion؛ مستخدم 999961 + عناوين 203.0.113.x؛ إعدادات OAuth
  تُزرع في system_settings وتُمسح). **1007/17793 OK**؛ lint (811 ملف) + phpstan
  بلا أخطاء.
- **ملاحظة:** تثبيت flake نادر من الترتيب العشوائي في `EmailMarketingContactsIntegrationTest`
  و`SeoAutoSeoModuleIntegrationTest` (إعادة إنشاء المستخدم/الموقع لو اترشّحوا) —
  بدون لمس كود الإنتاج.
- **Commit:** منفصل + push (تفاصيل في CHANGELOG.md).

## الموديول 7 (مكتمل 2026-08-31): Rate Limiting شامل — AI لكل مستخدم + Auth لكل IP
- **هدف:** حماية معدلات شاملة لكل نقط النهايات عبر الـ `RateLimiter` الموجود
  (مش نظام جديد): نطاق AI لكل مستخدم (تكلفة)، نطاق Auth لكل IP (Brute Force)،
  والطبقة العامة (middleware) برسائل 429 عربي.
- **منفّذ (إضافات — بلا لمس أي منطق شغال):**
  - **7a — البنية:** `RateLimiter::resetWindow(identifier,type)` (مسح العدّاد +
    إلغاء الحظر) + `Controller::rateLimitGuard(tier,scope,max,window)` يرجع
    429 عربي مع retry_after/limit. Fail-open عند فشل الفحص.
  - **7b — نطاق AI (20/دقيقة لكل مستخدم، عداد مشترك بين كل نقاط AI):**
    AIController (analyze/generateArticle/analyzeCompetitor/discoverKeywords/
    enrichKeywords) + ChatController::generateReply + CreativeStudio (requestMedia/
    requestVideo/enhancePrompt/requestVideoScript) + MarketingAssistant::run +
    ExecutiveExtras::askCeoAdvisor + SocialMedia::generateCaption.
  - **7c — نطاق Auth (30/دقيقة لكل IP):** AuthController login/register/
    forgotPassword/resetPassword/socialRedirect/socialCallback/appleCallback.
  - **7d — الطبقة العامة:** رسالة 429 عربي في `RateLimitMiddleware` + مسارات
    reset-password/resend-verification في خريطة الحدود + `addRateLimitHeaders`
    أصبحت protected (للاختبار).
- **التحقق:** `tests/Integration/RateLimitingModuleIntegrationTest.php` جديد
  (8 اختبارات/50 assertion؛ مستخدم 999951 + عناوين 203.0.113.x) — رفض بعد
  تجاوز الحد + التعافي بعد `resetWindow` + عداد مشترك + fail-open +
  middleware عربي. **975/17681 OK**؛ lint (810 ملف) + phpstan بلا أخطاء.
- **ملاحظة:** تثبيت `ExecutiveSuiteModuleIntegrationTest::testAskRequiresWebsites`
  بتفريغ دفاعي لمواقع المستخدم 999801 (flake نادر من ترتيب عشوائي).
- **Commit:** منفصل + push (تفاصيل في CHANGELOG.md).

## الموديول 6 (مكتمل 2026-08-31): Marketing Assistant — تغطية الأدوات الست + الحفظ
- **هدف:** تغطية `MarketingAssistantService` (الأدوات الست + بناء البرومبت +
  حفظ `ai_assistant_interactions` + `activity_logs` + فشل AI + أداة مجهولة +
  اقتطاع العنوان + السجل + الربط مع Action Center) بمصادر حقيقية وصفر
  شبكة/AI حقيقية.
- **منفّذ (تغطية فقط — لم يتغير أي كود إنتاجي):**
  - **6a — الأدوات:** availableTools (6) + run() لكل أداة يحفظ صفًا بنوعه.
  - **6b — run():** برومبت قالب الأداة عبر `MarketingFakeGemini` (يمدد
    GeminiClient) + حفظ التفاعل + ActivityLog (marketing_assistant/tool.used).
  - **6c — الفشل:** فشل AI → `خطأ: ...` محفوظ بلا throw؛ أداة غير معروفة →
    InvalidArgumentException بلا كتابة؛ اقتطاع العنوان (100 حرف).
  - **6d — السجل والربط:** `where()` تنازليًا + ظهور الناتج كعنصر `marketing`
    في `ActionCenterService::getActionItems`.
- **التحقق:** `tests/Integration/MarketingAssistantModuleIntegrationTest.php`
  جديد (20 اختبار/80 assertion؛ مستخدم معزول 999900). **959/17581 OK** من أول
  تشغيل؛ lint (809 ملف) + phpstan بلا أخطاء.
- **Commit:** منفصل + push (تفاصيل في CHANGELOG.md).

## الموديول 5 (مكتمل 2026-08-31): Executive Suite — تغطية تكامل كاملة للوحات الإدارة
- **هدف:** تغطية الموديولات التنفيذية الثلاثة القائمة (`ExecutiveDashboardService`/
  `CeoAdvisorService`/`ActionCenterService`+`ActionCenterExecutionService`+
  `ActionCenterExecutor`) بمصادر بيانات حقيقية وصفر شبكة/AI حقيقية.
- **منفّذ (تغطية فقط — لم يتغير أي كود إنتاجي):**
  - **5a — ExecutiveDashboard:** الست درجات من بيانات حقيقية (wo_audits/
    competitors/reviews/ai_articles/tracked_keywords) مع null للمصادر الفارغة +
    Top Opportunities/Problems + RecentChanges (يستبعد rolled_back) + لقطة
    المنافسين (حد 5).
  - **5b — CeoAdvisor:** `gatherAccountSnapshot` يجمع كل المصادر فعلًا،
    `ask()` عبر `FakeCeoAi` (صفر شبكة) + رفض سؤال فارغ/بلا مواقع + فشل AI.
  - **5c — Action Center:** تجميع 8 مصادر موحّدة بترتيب الأولوية + فلتر
    الموقع؛ `getNextBestActions` للمصادر القابلة للتنفيذ فقط؛ `ActionCenterExecutor`
    (taskCreator/notifier وهميين + action_executions حقيقي + dedup + dry_run +
    وسم ci_insights actioned + history).
- **التحقق:** `tests/Integration/ExecutiveSuiteModuleIntegrationTest.php` جديد
  (46 اختبار/202 assertion؛ مستخدم معزول 999800، موقعان 999850/999851).
  **939/17501 OK** من أول تشغيل؛ lint (808 ملف) + phpstan بلا أخطاء.
- **ملاحظات:** دروس من البيانات الحقيقية — `` `trigger` `` عمود محجوز + enum
  manual_click/audit_auto_pilot؛ `reviews.source_platform` enum
  (google_business لا google)؛ due_date في `planOne` نسبية لـ NOW؛ action_key
  يلحق period عند وجوده؛ وسم ci_insights يتطلب affected_area_id (كما يمرّره
  الإنتاج عبر mapItemToAction).
- **Commit:** منفصل + push (تفاصيل في CHANGELOG.md).

## الموديول 4 (مكتمل 2026-08-31): Creative Studio — حقن عملاء التوليد + تغطية كاملة
- **هدف:** جعل موديول الاستوديو الإبداعي (`MediaGenerationService`/
  `VideoScriptService`/`GenerateMediaJob`/`GenerateVideoJob`) قابلًا للاختبار
  بلا مساس بسلوك الإنتاج وصفر شبكة/AI حقيقية.
- **منفّذ (إضافات — بلا لمس أي منطق شغال):**
  - **4a — حقن العملاء:** `GenerateMediaJob`/`GenerateVideoJob` قبلان
    `?callable $clientFactory` (الغائب → `new GeminiClient()`/`new VeoClient()`
    كما كان). `VideoScriptService` كان قابلًا للحقن أصلًا.
  - **4b — الاختبارات:** `tests/Integration/CreativeStudioModuleIntegrationTest.php`
    جديد (20 اختبار/142 assertion): fakes تمدّ GeminiClient/VeoClient،
    `ROOT_PATH` مؤقت لكتابة الملفات خارج public_html.
- **التحقق:** التغطية: MediaGenerationService (نسب الأبعاد + جدولة jobs +
  ActivityLog + رفض الأنواع + fallback المدة)، GenerateMediaJob (نجاح بكتابة
  ملف + امتداد jpg + فشل AI + عنصر مفقود)، GenerateVideoJob (فشل البدء +
  نجاح البدء/provider_ref + اكتمال الفحص + مهلة + poll_attempts)،
  VideoScriptService (JSON/نشاط/فشل/مشوه). **893/17299 OK**؛ lint (807 ملف)
  + phpstan بلا أخطاء.
- **ملاحظات:** قاعدة بيانات الاختبار مطلوبة منها أعمدة الفيديو — أُضيف
  migration `2026_08_07_000040` لقائمة `applyTestMigrations`. تحقّق من
  معرّفات المستخدم المعزولة (999600) لتفادي تصادم مع Booking/OTA.
- **Commit:** منفصل + push (تفاصيل في CHANGELOG.md).

## الموديول 3 (مكتمل 2026-08-31): Publishing — تغطية WordPress/Custom API + حالة publish_failed
- **هدف:** جعل النشر الحالي (`WordPressPublisher`/`CustomApiPublisher`/
  `PublishScheduledArticleJob`/`AIController::publishArticle`) قابلًا للاختبار
  بلا مساس بسلوك الإنتاج، وتمييز فشل النشر الفعلي عن فشل الجدولة، وإصلاح
  انحراف enum `ai_articles.status`.
- **منفّذ (إضافات — بلا لمس أي منطق شغال):**
  - **3a — حقن transport:** `WordPressPublisher`/`CustomApiPublisher` قبلان
    `?callable $transport` (الاختبارات تحقن Fake؛ الإنتاج يبقى curl كما كان) +
    `buildResult()` يوحّد رسائل الأخطاء العربية بين مسار curl والوهم.
  - **3b — الجدولة:** `PublishScheduledArticleJob` بمُنشئ `?callable
    $publisherFactory` + `makePublisher(platform)`؛ فشل النشر الفعلي →
    `publish_failed` + `error_message` + Notification؛ فشل ما قبل التنفيذ →
    `schedule_failed`؛ النجاح → `published` + `published_at` + `published_url` +
    `wp_post_id` + تحرير `scheduled_job_id`.
  - **3c — الـ controller:** `AIController::publishArticle` يضبط
    `status='publish_failed'` + `error_message` + Notification + 502.
  - **3d — migration:** `2026_08_31_000003_fix_ai_articles_publish_status.sql`
    (idempotent) يعيد `published` ويضيف `publish_failed` للـ enum؛ مسجّل في
    `applyTestMigrations`.
- **التحقق:** `tests/Integration/PublishingModuleIntegrationTest.php` جديد
  (20 اختبار/138 assertion عبر `FakePublishTransport` — صفر شبكة/AI):
  testConnection/أخطاء/فشل شبكة، createPost/updatePost (URL + status +
  أخطاء 500 + رد غير JSON)، CustomApi (هيدرز Auth/Secret + is_test/source +
  url)، job end-to-end عبر publisherFactory (نجاح WordPress/CustomApi، فشل
  publish_failed، لا اتصال schedule_failed، مقال غير scheduled noop)، انحراف
  enum. **854/17157 OK** (إعادة تشغيل واحدة لتذبذب `SeoAutoSeo` السابق للوجود)؛
  lint (806 ملف) + phpstan بلا أخطاء.
- **Commit:** منفصل + push (تفاصيل في CHANGELOG.md).

## الموديول 2 (مكتمل 2026-08-31): White-Label — دعوات العملاء + لوحة تحكم الوكيل
- **هدف:** تدفّق دعوة العميل بالرمز/الرابط (العميل الحقيقي هو من يقبل → يتحول
  لعميل في `agency_clients`) + لوحة تحكم الوكيل، كلها داخل عزل agency_id صارم.
- **منفّذ (إضافات جديدة — بلا لمس أي منطق شغال):**
  - **2a — الدعوات:** جدول `agency_invitations` (migration idempotent) +
    `AgencyInvitation` + `AgencyService::createInvitation` (رمز فريد/idempotent) +
    `acceptInvitation` (تحقق من الحالة/الانتهاء/تطابق البريد/حد المقاعد، يضيف
    العميل بنسبة دعوته، ActivityLog + إشعار لصاحب الوكالة) + `revokeInvitation`/
    `listInvitations`.
  - **2b — لوحة الوكيل:** `agencyStats()` (عملاء/حجوزات/إيراد/عمولات/دعوات
    معلقة/آخر العمولات) + `clientPerformance()` (أداء كل عميل تنازليًا بالإيراد).
  - **2c — الـ API:** 5 مسارات جديدة عبر `AuthMiddleware` + `ownedAgency`
    (404 للوكالات غير المملوكة): إنشاء/قائمة/إلغاء دعوة، قبول بالرمز (لا يُعاد
    الرمز)، لوحة الوكيل.
  - **تسجيل:** `AgencyInvitation` في `cron/bootstrap.php` + `public_html/index.php`
    + `applyTestMigrations`؛ إصلاح `AgencyClient::$fillable` بنقص `commission_rate`.
- **التحقق:** `tests/Integration/AgencyInvitationIntegrationTest.php` جديد
  (20 اختبار/124 assertion: إنشاء/idempotency/رفض مدخلات، قبول بنسبة الدعوة،
  رفض رمز خاطئ/منتهي/ملغي/بريد مختلف/حد مقاعد، عزل endpoints، لوحة الوكيل
  بتجميع حجوزات وعمولات حقيقية). دورة العمولة الكاملة مغطاة في
  `AgencyCommissionIntegrationTest` السابق. **813/17019 OK**؛ lint (805 ملف) +
  phpstan بلا أخطاء.
- **ملاحظة اختبارات:** إعادة ضبط `CONTENT_TYPE` في helper الاختبارات لتفادي
  تسريب application/json من bootstrap يؤثر على `parseInput`.
- **Commit:** منفصل + push (تفاصيل في CHANGELOG.md).

## الموديول 1 (مكتمل 2026-08-31): OTA Integration — GetYourGuide + Viator + ربط إيراد الحجوزات
- **هدف:** جعل عملاء OTA الحاليين قابلين للاختبار بأمان (بلا مساس بسلوك الإنتاج)
  وربط إيراد الحجوزات OTA في `rev_revenue_records` بنفس قواعد
  `BookingEngine::recordBookingRevenue/Refund`.
- **منفّذ (إضافات جديدة — بلا لمس أي موديول قائم):**
  - **1a — العملاء:** `GetYourGuideAPI`/`ViatorAPI` مُعاد هيكلتهما بحقنة
    `?callable $transport` اختيارية (الاختبارات تحقن Fake؛ الإنتاج يبقى curl بالظبط
    كما كان) + envelope موحّد + معالجة آمنة للأجسام غير JSON (no throw) +
    `log()` في `Logger` مع fallback `app_log`.
  - **1b — ربط الإيراد:** `OtaBookingService` جديد — `recordBookingRevenue()`
    يُدرج `source='ota_booking'` + `event('revenue.updated')` idempotent على
    `user_id+source+reference_id`؛ `recordBookingRefund()` بمصدر `ota_refund` بمبلغ
    سالب فقط بعد إيراد موجب ومرة واحدة؛ fail-safe try/catch + Logger؛ تحقق من
    صحة المدخلات.
  - **1c — التسجيل:** الكلاسات الثلاثة مسجّلة في `cron/bootstrap.php` و
    `public_html/index.php`.
- **التحقق:** `tests/Integration/OtaModuleIntegrationTest.php` جديد (19 اختبار/130
  assertion عبر `FakeOtaTransport` — صفر شبكة/AI): verifyToken/أخطاء مفتاح/فشل شبكة،
  استعلام tours و searchProducts (تحقق من المعاملات/الجسم)، getBooking + malformed
  آمنة + أخطاء 429، ربط الإيراد الكامل (حقول + idempotency + رفض مدخلات + استرداد
  بعد موجب فقط + ظهوره في تقرير `RevenueOverviewService` المختلط + عزل بين المستخدمين).
- **ملاحظة تذبذب:** فشلان مؤقتان في تشغيلتين (FK في `ci_insights` و assert في
  `SeoAutoSeo`) — سابقان للوجود ومرتبطان بترتيب الاختبارات العشوائي؛ إعادة التشغيل
  سليمة تمامًا: **773/16831 OK** في تشغيلتين متتاليتين؛ lint (803 ملف) + phpstan بلا أخطاء.
- **Commit:** منفصل + push (تفاصيل في CHANGELOG.md).

## البند 2 (مكتمل 2026-08-31): إكمال وحدة Backlink/Outreach Backend
- **هدف:** البنية الخلفية الكاملة لموديول الـ Outreach بعد الحصول على الباك لينكس:
  مراقبة أسبوعية للحالة، متابعات (مسودات فقط)، وتقرير أداء للـ pipeline.
- **منفّذ (إضافات جديدة — بلا لمس أي موديول قائم):**
  - **2a — المراقبة:** جدول `monitored_backlinks` (migration idempotent)
    + `MonitoredBacklink` + `BacklinkMonitorService`
    (`registerAcquiredLink` idempotent / `checkLink` live على 2xx-3xx و lost على
    4xx/5xx/خطأ / `monitorDue` أسبوعي / `summaryForWebsite`). فحص HTTP آمن
    SSRF-protected عبر `WebsiteSnapshotFetcher`، مع حقنة `callable` قابلة للاختبار.
  - **2b — المتابعات:** `OutreachFollowUpDraftService::generateDueFollowUps()`
    — مرشّحون نشطون مرّ 7 أيام على آخر رسالة مُرسلة → المسودة التالية
    (أقصى 3 متابعات، idempotent، **مسودات فقط — ممنوع الإرسال التلقائي**)
    + إشعار `Notification` واحد لكل مستخدم للمراجعة.
  - **2c — التقرير:** `OutreachPerformanceService::report()` — قمع المراحل +
    معدلات التحويل + حالة الباك لينكس + متوسط الوقت للوصول للرابط (أيام).
  - **الربط:** `OutreachController::updateProspectStatus` يسجّل الرابط تلقائيًا عند
    `link_acquired` + `link_url` (فشل هادئ لا يكسر تحديث الحالة)؛ 3 مسارات جديدة
    عبر `AuthMiddleware`: `GET /api/outreach/backlinks`,
    `POST /api/outreach/backlinks/{id}/check`, `GET /api/outreach/performance`.
  - **Crones:** `cron/monitor_backlinks.php` (أسبوعي) + `cron/generate_outreach_followups.php`
    (يومي) — `class_exists` guard + إحصائيات STDOUT + catch Throwable؛ الكلاسات
    الجديدة مسجّلة في `cron/bootstrap.php` و `public_html/index.php` و
    `tests/bootstrap.php` (قائمة `applyTestMigrations`).
- **التحقق:** `tests/Integration/OutreachBacklinkMonitoringIntegrationTest.php` جديد
  (18 اختبار/112 assertion: فحص live/lost، idempotency للتسجيل والمتابعات، الاستحقاق
  الأسبوعي، ملخص الموقع، ربط الـ controller، مسودات بعد 7 أيام فقط، حد 3 متابعات،
  تقرير الأداء — بحقنة وهمية بلا شبكة/AI)؛ **735/16701 OK**؛ lint + phpstan بلا أخطاء.
- **Commit:** منفصل + push (تفاصيل في CHANGELOG.md).

## البند 1 (مكتمل 2026-08-31): ربط الحجوزات الفعلية بسجلات الإيرادات
- **هدف:** تدفّق الحجوزات الفعلية إلى `rev_revenue_records` بدون المساس بمنطق
  Stripe/CRM/العمولة/الكاش القائم.
- **منفّذ في `app/Services/BookingEngine.php`:**
  - مصدر جديد `booking`: يُدرج عند `confirmBooking()` و `confirmBookingFromPayment()`
    داخل نفس الـ transaction (بعد `recordAgencyCommission`)، بقيمة `total_amount`
    والعملة، مع `event('revenue.updated')` لتفريغ كاش `RevenueCacheService`.
  - مصدر جديد `booking_refund`: يُدرج عند `cancelBooking()` بمبلغ سالب
    `-total_amount` **فقط** إذا كانت الحالة السابقة `confirmed` (إلغاء `pending`
    بلا تصحيح).
  - **Idempotent:** فحص `user_id + source + reference_id` قبل الإدراج؛ **fail-safe**
    بلا `throw` (لا يكسر تدفق التأكيد/الإلغاء إن فشل التسجيل).
- **بدون لمس:** `RevenueDataGateway`/`RevenueController`/`RevenueCacheService`/
  `CustomerRevenueService` (المصدر يبقى `crm_deals won` فقط) — تغطية نموذجية
  مُثبتة بالاختبارات.
- **التحقق:** `tests/Integration/BookingRevenueIntegrationTest.php` جديد
  (18 اختبار/92 assertion: مسار يدوي+دفع، idempotency، الاسترداد عند الإلغاء،
  تقارير `RevenueOverviewService` المختلطة)؛ إعادة تشغيل حزم الحجز القائمة
  (36 اختبار) سليمة؛ **717/16543 OK**؛ lint + phpstan بلا أخطاء.
- **ملاحظة فنية:** `getOverview()`/`getRevenueBySourceWithGrowth()` يستخدمان نهاية
  فترة حصرية (`recorded_at < now`) — الاختبارات تستخدم `backdateRevenueRecords()`
  لتجنّب تذبذب نفس الثانية.
- **Commit:** منفصل + push (تفاصيل في CHANGELOG.md).

**التاريخ:** 2026-08-28
**الفرع:** `main`
**الحالة:** 8 بنود جديدة بالتتابع — كل بند migration+model+service+controller+routes+Lang+tests+checks+commit منفصل

## خطة التطوير (الخطوة 4): التطوير الفعلي للموديولات الستة — M1 + M2 + M3 + M4 + M5 + M6 مكتملون
- **M6 (مكتمل):** SEO/AutoSeo — إغلاق G1/G3/G4/G6/G7 من
  `docs/COMPETITIVE_ANALYSIS_SeoAutoSeo.md`:
  - G1 زحف متعدد الصفحات: `SeoCrawlerService` BFS للروابط الداخلية (عمق 1-6/حد
    3-100 صفحة/ميزانية وقت/مُحدَّد نطاق بنفس الدومين) مع فحص on-page فعلي لكل URL
    (title/meta/H1/عدد كلمات/HTTP/وقت استجابة/أخطاء) → `seo_crawl_pages` + تجميع
    مقاييس الموقع (تكرارات عناوين/H1، صفحات بلا meta/H1، متوسط سرعة/كلمات) +
    `lastCrawl` + endpoints `POST/GET /api/website-optimizer/crawl`.
  - G3 الفهرسة لدى Google: `GoogleIndexingService` عبر Google Indexing API الرسمي
    (OAuth Service Account JWT RS256) + toggle/status لكل موقع
    (`google_indexing_enabled`/`last_google_indexed_at`) — fail-safe
    `available=false` عند غياب `GOOGLE_SERVICE_ACCOUNT_JSON` (**غير مختبَر** ضد
    Google فعليًا؛ يُكمّل IndexNow/Baidu القائمين).
  - G4 بيانات كلمات خارجية: `KeywordResearchSourceInterface` + `HttpKeywordResearchSource`
    (`KEYWORD_RESEARCH_API_URL`/`_KEY`) + `NullKeywordResearchSource` — إثراء
    `tracked_keywords` بـ search_volume/difficulty/enriched_at من بيانات حقيقية
    بلا اختلاق (**غير مختبَر** مع مزوّد خارجي فعلي).
  - G6 تقرير بصري + مجدول: `SeoChartService` (scoreTrend/categoryScores/gscTopPages/
    fixesAppliedTrend جاهزة لـ Chart.js) + `SeoScheduledReportService` جدولة
    daily/weekly/monthly → تقرير HTML RTL مهرَّب عبر `Mailer` في
    `cron/seo_scheduled_reports.php` (skip آمن عند غياب البريد)؛ PDF خارج النطاق.
  - G7 Rank Tracking: `RankTrackingService` يعيد استخدام `KeywordRankingSourceInterface`
    (M5) — فحص يومي `dueWebsites` + سجل زمني `seo_rank_tracking_history` +
    تحديث `current_position` + نظرة عامة best/trend/readings + سلسلة زمنية لكل
    كلمة + `cron/seo_rank_tracking.php` + 8 endpoints عبر `SeoInsightsController`.
  - G2 (JS Rendering/Web Vitals) وG5 (نشر خارجي للمحتوى) **خارج نطاق M6** — بلا
    تكامل Infrastructure؛ تُركا مفتوحتين ومُوثّقتين في ملف الفجوات.
  - التحقق: lint 793 OK، PHPStan 0، **699/16451 OK** (منها 20 اختبار M6 في
    `SeoAutoSeoModuleIntegrationTest`) — commit `4be3a22` منفصل + push.
  - ملف الفجوات حُدّث (G1/G3/G4/G6/G7 ✅ مغلقة) + CHANGELOG.md.
- **M5 (مكتمل):** Competitor Intelligence — إغلاق G1/G6/G7 من
  `docs/COMPETITIVE_ANALYSIS_CompetitorIntelligence.md`:
  - G1 تتبع ترتيب الكلمات المفتاحية: جدول `ci_keyword_rankings` (بُعد زمني،
    ترتيب null = خارج أول 100) + `KeywordRankingService` (أحدث قياس + `best_position`
    + `trend` + `history`) + `KeywordRankingSourceInterface`/`NullKeywordRankingSource`
    يفشلان بأمان عند غياب الإعداد + `cron/ci_keyword_rankings.php` يومي يقرأ
    `competitor_keywords` الفعلية للمنافسين النشطين (إغلاق الجدول المهمل).
  - G6 Battlecards: `BattlecardService.generate()` قواعدية بحتة من بيانات المراقبة
    الحقيقية (scorecard/insights/أسعار/تغيّرات) — نقاط قوة/ضعف/موقع سعري/إجراءات،
    ورفض `insufficient_data` عند نقص البيانات (لا اختلاق).
  - G7 تتبع أسعار المنتجات: `PriceExtractor::extractAll` (متعدد الأسعار + تسميات
    سياقية) مدمج في `MonitoringEngine` → كل دورة مراقبة (30د) تلتقط أسعار صفحات
    pricing/products/offers وتحفظ التاريخ في `ci_product_prices`.
  - الواجهة: 3 تبويبات جديدة (keywords/prices/battlecard) + 11 endpoint + حدود
    معدل `keyword_rankings_check`/`battlecard_generate` + ~40 مفتاح i18n + تسجيل
    يدوي للملفات الجديدة في `index.php`/`cron/bootstrap.php`.
  - التحقق: lint 777 OK، PHPStan 0، **659/16049 OK** (منها 30 اختبار M5) —
    commit `c2e3668` منفصل + push.
  - ملف الفجوات حُدّث (G1/G6/G7 ✅ مغلقة) + CHANGELOG.md.
- **M4 (مكتمل):** Email Marketing — إغلاق G2/G3/G9 من
  `docs/COMPETITIVE_ANALYSIS_EmailMarketing.md`:
  - G2 استهداف الشرائح: عمود `segment_id` على `email_campaigns` + `audience()`
    يفضّل الشريحة على القوائم (عزل تينانت + استبعاد الممنوعين) + مُحدِّد الشريحة
    في واجهة الحملات واسمها في الجدول.
  - G3 تتبع رسائل الأتمتة: جدول `email_automation_logs` (توكنات فتح/كليك لكل
    إرسال + حالة sent/failed بعد الإرسال) ومسارا التتبع العامان يسجّلان الفتح/
    الكليك على السجل.
  - G9 درجة التفاعل: `recomputeEngagementScore` من أحداث فتح/كليك حقيقية
    (حملات + أتمتة، +20/+30 حتى 100) يُستدعى تلقائيًا عند كل حدث.
  - التحقق: lint 767 OK، PHPStan 0، **629/15783 OK** — commit منفصل + push.
  - ملف الفجوات حُدّث (G2/G3/G9 ✅ مغلقة) + CHANGELOG.md.
- **M3 (مكتمل):** Revenue Intelligence — إغلاق G2/G6/G7 من
  `docs/COMPETITIVE_ANALYSIS_RevenueIntelligence.md`:
  - G7 أهداف/حصص المبيعات: `RevenueQuotaService` (عزل تينانت) يقرأ
    `crm_sales_goals` + الإنجاز من `rev_revenue_records` + إشارة won منفصلة +
    تنبؤ من open deals المقررة في الشهر (وزن بالاحتمالية) + الفجوة والحالة
    (`ahead/on_track/at_risk/behind`)؛ تبويب `quotas` + `GET /api/revenue-intelligence/quotas`.
  - G2 الإيراد حسب المنتج: ميجريشن يضيف `product_name`/`category` +
    `getRevenueByProduct()` (تجميع + share_percent + fallback) + حقول النموذج
    في `RevenueController::createRecord`.
  - G6 اتساع المعايير: `cron/revai_benchmarks_rebuild.php` ينتج 4 مقاييس
    (growth + win_rate + avg_deal_value + revenue_monthly_avg) بحدود حسابات
    ورفض عند قلة البيانات.
  - إصلاح جذر: ميجريشن الـ Revenue `user_id` BIGINT UNSIGNED → INT(11) (errno 150).
  - التحقق: lint 766 OK، PHPStan 0، **615/15727 OK** — commit منفصل + push.
  - ملف الفجوات حُدّث (G2/G6/G7 ✅ مغلقة) + CHANGELOG.md.
- **M2 (مكتمل):** Reputation — إغلاق G2/G4/G5 من
  `docs/COMPETITIVE_ANALYSIS_Reputation.md`:
  - G2 قناة SMS لطلبات المراجعة: `ReviewRequestService` يدعم `'sms'` في كل
    المسارات + `isChannelConfigured` عبر `CrmSmsService` (Twilio) + `sendByChannel`
    ترسل SMS فعليًا + رسائل عربية عند عدم التهيئة/فشل الإرسال؛ واجهة الفلتر/النموذج
    في `ReviewRequestController` + مفتاح `rr.channel.sms` في كل اللغات.
  - G4 استخراج موضوعات ديناميكي: `ReviewTopicExtractor` (ثنائي اللغة، 10 موضوعات
    قطاعية، كلمات قوية بأوزان، تجميع مشاعر/متوسط/حصة) يعرض `topics`/`improvements`
    في النظرة العامة بدل الكلمات الثابتة — بلا LLM.
  - G5 تصدير المراجعات CSV: `exportReviewsCsv()` (فلاتر + ملكية + صف ملخص +
    حذف بيانات المراجع) عبر `GET /api/reputation/export-reviews`.
  - التحقق: lint 764 OK، PHPStan 0، **601/15641 OK** — commit منفصل + push.
  - ملف الفجوات حُدّث (G2/G4/G5 ✅ مغلقة) + CHANGELOG.md.
- **M1 (مكتمل):** Website Builder — إغلاق G1/G2/G3/G7 من
  `docs/COMPETITIVE_ANALYSIS_WebsiteBuilder.md`:
  - G2 تطبيق `theme_color` فعليًا: `siteDesignAttrs()` + تمرير `$themeKey`
    عبر توقيعات `renderToursHome`/`renderHotelHome`/`showTourDetail`/
    `showRoomDetail`/`showBookingConfirmation` (كان `'gold'` حرفيًا دائمًا).
  - G1 تطبيق `layout_key`: يُقرأ من `website_templates.template_id` عند العرض
    ويُخرِج كلاس body `ws-layout-classic/boutique/luxury` + CSS جديد
    (`generated-site.css`).
  - G3 قسم تقييمات الزوار: `siteReviewsSectionHtml()` (متوسط/نجوم/نموذج تقييم)
    على الرئيسية والتفاصيل — عرض المعتمَد فقط عبر `WebsiteReview::approvedFor`.
  - G7 توجيه الدومين المخصص: `index.php` قسم 9.3b يكتشف `custom_domain` من الـ
    Host ويعيد كتابة المسار لـ `/sites/{slug}` (published فقط، fallback آمن).
  - التحقق: lint 762 OK، PHPStan 0، **583/15567 OK** — commit منفصل + push.
  - ملف الفجوات حُدّث (G1/G2/G3/G7 ✅ مغلقة) + CHANGELOG.md.
## خطة التطوير (الخطوة 2 مكتملة): التحليل التنافسي لـ 6 موديولات
- 6 ملفات `docs/COMPETITIVE_ANALYSIS_<Module>.md` (تبعًا لقالب
  `docs/COMPETITIVE_ANALYSIS.md`): مقارنة فيتشرز الكود الفعلي ضد 2-4 منافسين
  لكل موديول + جدول Gap Analysis (عالية/متوسطة/منخفضة) + الميزات التنافسية.
- الموديولات: Reputation (Birdeye/Podium/Trustpilot/Reputation.com)،
  CompetitorIntelligence (SEMrush/SpyFu/SimilarWeb/Kompyte)،
  RevenueIntelligence (Clari/Gong/RevenueGrid/insightSquared)،
  WebsiteBuilder (Wix/Squarespace/GoDaddy/Durable)،
  EmailMarketing (Mailchimp/Brevo/MailerLite/Klaviyo)،
  SEO/AutoSeo (Ahrefs/SEMrush/Surfer/ScreamingFrog/Cloudflare).
- commit منفصل لكل ملف بصيغة `docs: competitive analysis for <Module> module`.
- تم رصد فجوات كود فعلية خلال التحليل (تُركّت كتوثيق فقط بلا تطوير):
  `layout_key` لا يؤثر على العرض في WebsiteBuilder، تتبع فتح/نقر أتمتة
  الإيميل لا يُحفظ (EmailMarketing)، عدم تطابق ENUM قناة التنبيهات
  (CompetitorIntelligence).

## خطة التطوير (الخطوة 1 مكتملة): Security Audit — تقرير + إصلاح 10 ثغرات XSS
- `SECURITY_AUDIT.md`: فحص 90 ملف Controller، 10 ثغرات مؤكدة (ملف+سطر+نوع خطر).
- الإصلاحات: 4× inline-script JSON (`JSON_HEX_*`)، 2× Reflected (OAuth error +
  REQUEST_URI)، 1× Host header، 1× Stored `<title>`، 2× حقن SEO Server-Side
  (JSON-LD escape + og_tags sanitize) — كلها Additive بلا تغيير business logic.
- التحقق: **583/15567 OK**، lint 762، PHPStan 0. commit + push.

## البند 8 (مكتمل): معدل الحل الإحصائي للـ AI Chat (AI Resolution Rate)
- `AiResolutionRateService`: معدل الحل = المحادثات المنتهية (`resolved`/
  `closed`) المحسومة بالكامل عبر الـAI (بلا `handoff_at`) ÷ الإجمالي
  المنتهي؛ المفتوحة (`open`/`pending`) تُعرض `still_open` ولا تدخل المقام؛
  جودة الاستدعاء من `ai_usage_logs` (نسبة success مع failed/fallback_used).
- لا بيانات → `resolution_rate_percent`/`success_rate_percent` = `null`
  صراحةً + ثقة `low` (high ≥30 / moderate ≥10) — لا اختراع أرقام.
- `AiResolutionRateController` + route `GET /api/ai-chat/websites/{id}/resolution-rate`
  (بعد analytics، عبر `authorizedWebsite`) + 15 مفتاح `chat.resolution_rate.*`.
- اختبارات `AiResolutionRateIntegrationTest` (5/10، 18 assertion).
- **إصلاح حساسية الترتيب في السبولة الكاملة**: `cleanup()` تحذف جداول الـAI
  بـ `user_id` مباشرةً (لا عبر ربط الموقع) — `FixtureLoader` يصفّر
  `AUTO_INCREMENT` للمواقع فيعيد استعمال المعرّف بينما لا يمسح
  `ai_usage_logs`/`ai_conversations`.
- التحقق: **583/15567 OK**، lint 762، PHPStan 0. commit `8c17b49` + push.

## البند 7 (مكتمل): طبقة إعادة ترتيب الصلة في قاعدة المعرفة (KB Re-ranking)
- التغطية الناقصة: `KnowledgeBaseRerankIntegrationTest` (5/10، 28 assertion)
  لطبقة `rerankForQuery`/`tokenize`/`countOverlap`/`buildContextForPrompt`
  القائمة في `KnowledgeBaseService` (مُنجزة أصلًا في `4d29f5e`).
- يغطي: ترتيب الصلة (عنوان ×2.0 + محتوى ×1.0)، اقتطاع التوكنز
  (maxEntries)، اللغة العربية، أرضية score 0.05، واستبعاد `brand_voice`.
- أُصلحت 4 حالات فشل أولية (تعادل ترتيب Titles + عدم تطابق كلمات عربية)
  بتعديل رسالة/محتوى الاختبار.
- التحقق: **573/15511 OK**، lint 759، PHPStan 0. commit `26022be` + push.

## البند 6 (مكتمل): التقييم الإحصائي الشفاف فوق القيادات المتدرجة (Statistical AI Lead Scoring)
- migration `2026_08_28_000008_add_stat_lead_scoring.sql` (idempotent):
  `crm_leads` + `conv_probability` DECIMAL(5,4) + `score_confidence`
  ENUM(low|moderate|high) + `score_signals_json` JSON.
- `CrmStatisticalLeadScoringService`: Wilson Score 95% على القرارات النهائية
  الفعلية (`converted`/`disqualified` أو deal `won`/`lost`) من سجل الحساب
  نفسه؛ `MIN_SAMPLE=10` → `null` + ثقة `low`؛ لا يلمس `score/priority/
  score_reason` القائمة (تحقق اختباري أن score يبقى 0).
- `CrmLeadScoringStatController` + routes (ثابت قبل ديناميكي) + `CrmLead`
  fillable + 19 مفتاح `crm.scoring.*`؛ عزل تينانت: 403 إن اختلف
  `crm_contacts.user_id`، 404 إن لم يوجد.
- اختبارات `CrmStatisticalLeadScoringIntegrationTest` (6/12، 76 assertion).
- التحقق: **563/15483 OK**، lint 758، PHPStan 0. commit `e118f96` + push.

## البند 5 (مكتمل): توصيات "الخطوة التالية" الإحصائية لكل حملة (Next-Best-Action) — Ads
- migration `2026_08_28_000007_create_ad_recommendations.sql`: `ad_recommendations`
  (action ENUM increase_budget|decrease_budget|pause_campaign|rotate_creative|
  start_ab_test|review_targeting|wait، basis statistical|rule، confidence
  low|moderate|high، signals JSON، status pending|applied|dismissed،
  UNIQUE(user, campaign, recommendation_date)).
- `AdNextBestActionService` (357 سطرًا): `linearSlope()` أقل المربعات على آخر
  14 يوم من ad_performance_reports (MIN_DATA_DAYS=5 → `wait` صريح) +
  قواعد increase (صرف ≥95% + ROAS ≥1 + ميل إنفاق تصاعدي) / decrease
  (ROAS <0.5) / rotate (ميل CTR < -0.1 + CTR حديث <1%) / review_targeting
  (صرف ≤30%) / start_ab_test (أصل بأكثر من تنويع بلا تجربة جارية) — مع
  `basis` و `confidence` و `signals` JSON (إشارات فقط لا توصيات كحقيقة).
- `AdNextBestActionController` (4 routes عبر resolveAdsAccess) + Model
  `AdRecommendation` + 22 مفتاح `ads.recommendations.*` (en/ar).
- اختبارات `tests/Integration/AdNextBestActionIntegrationTest.php`
  (8/16، 68 assertion): كل قاعدة + dedupe اليومي + applied/dismiss +
  عزل تينانت + صحة linearSlope.
- التحقق: **551/15361 OK**، lint 755، PHPStan 0. commit `d86e433` + push.

## البند 4 (مكتمل): تنبيهات القواعد على مستوى الأصل/التنويع/التجربة (Rule-triggered Alerts) — Ads
- **المشكلة:** AdAlertService القائم كان يغطي 5 قواعد على مستوى الحملة فقط
  (ad_performance_reports) بلا استفادة من بيانات الأصول/التنويعات/التجارب
  (البنود 1-2).
- migration `2026_08_28_000006_add_rule_alert_creative_types.sql` (idempotent):
  توسعة ENUM `rule_type` في ad_alert_rules + ad_alerts لـ 4 أنواع جديدة.
- `AdAlertService` (إضافة فوق القائم + نفس persist/notify): 4 قواعد جديدة —
  `creative_underperforming` (أفضل تنويع أقل من % من CTR الحملة) و
  `creative_stale` (بلا أداء مُسجّل منذ N يوم عبر recorded_on) و
  `variant_wasted_spend` (إنفاق ≥ حد بلا تحويلات) و
  `ab_test_inconclusive` (تجربة جارية منذ N يوم بلا دلالة إحصائية) —
  تنبيه واحد/حملة/يوم احترامًا لـ UNIQUE مع ذكر أسوأ حالة وعدد المخالفات.
- التكامل مع `GET/POST /api/ads/alerts/rules` + `POST /api/ads/alerts/run`
  القائمة (لا تعديل لـ AdsController). جديد: `AdRuleAlertController` →
  `GET /api/ads/alerts/rule-types` (كتالوج القواعد التسع).
- Bug-fix غير مدمر: return type `?array` → `array|string|null` لـ
  evaluateRule/evaluateAdvancedRule (كان TypeError كامنًا عند insufficient_data).
- Lang: 18 مفتاح `ads.alerts.rule.*` في en/ar.
- tests/bootstrap: إضافة `2026_08_15_000060_add_ads_alerts.sql` (كانت الجداول
  غير موجودة في DB الاختبار).
- اختبارات `tests/Integration/AdRuleAlertIntegrationTest.php` (8/42).
- التحقق: **535/15229 OK**، lint 751، PHPStan 0. commit + push على `main`.

## البند 3 (مكتمل): تقارير مستوى الإعلان/الـ variant (Ad/Variant Reports) — Ads
- **المشكلة:** AdReportService كان يغطي مستوى الحملة فقط (ad_performance_reports)
  بلا تفصيل على مستوى الأصل/التنويع (من بند 1) ولا نافذة زمنية لأداء التنويعات.
- migration `2026_08_28_000005_add_variant_performance_date.sql` (idempotent):
  `ad_creative_variants.recorded_on` DATE (NULL) + backfill بتاريخ الإنشاء +
  index (user_id, recorded_on) — نافذة زمنية حقيقية لتقارير الفترة.
- `AdVariantReportService`: `generate()` (حملات ← أصول ← تنويعات داخل الفترة،
  مقاييس محسوبة عند القراءة فقط + share_of_creative_clicks) +
  `creativeBreakdown()`/`variantBreakdown()`/`campaignBreakdown()`/`variantSummary()`
  + `bestVariant()` (أعلى CTR مع حد أدنى من الانطباعات + سياق الأصل/الحملة).
- تحديث بند 1 (additive): `AdCreativeService::recordPerformance()` يقبل
  `recorded_on` اختياريًا (YYYY-MM-DD وإلا رفض) وافتراضيًا تاريخ اليوم؛
  `AdCreativeVariant::$fillable` أُضيف `recorded_on`.
- `AdVariantReportController`: 6 نقاط API جديدة (كلها AuthMiddleware، المسارات
  الثابتة قبل الديناميكية)، عزل التينانت عبر `resolveAdsAccess()` + فحص ملكية.
- Lang: 20 مفتاح `ads.variant_reports.*` في en/ar.
- اختبارات `tests/Integration/AdVariantReportIntegrationTest.php` (7/41).
- التحقق: **519/15173 OK**، lint 749، PHPStan 0. commit + push على `main`.

## البند 2 (مكتمل): تجارب A/B الإعلانية (Ad A/B Testing) — Ads
- **المشكلة:** تنويعات الأصول (بند 1) كانت أذرعًا بلا تجربة: لا توزيع حركة
  نسبي ولا حكم إحصائي على الفرق في الأداء.
- migration `2026_08_28_000004_create_ad_ab_tests.sql` (idempotent): `ad_ab_tests`
  (user_id/campaign_id/creative_id/name/status/winning_variant_id/started_at/
  ended_at) + `ad_ab_test_variants` (user_id/ab_test_id/creative_variant_id/
  weight_pct/is_control + UNIQUE(ab_test, creative_variant)).
- `AdAbTestService`: createTest/listForCampaign/get مع أذرع وأداء +
  addVariant/updateVariantWeight/removeVariant + startTest (ذراعان على الأقل) +
  completeTest (فائز داخل الأذرع فقط) + archiveTest + `statistics()` (chi-square
  2x2 مع تصحيح Yates لكل ذراع مقابل التحكم، `reliable` لو الخلايا المتوقعة ≥5) +
  `predictWinner()` (أعلى CTR مع دلالة إحصائية وسبب صريح) +
  `pickVariantForTraffic()` (اختيار موزون عشوائي من أوزان الأذرع الجارية).
- `AdAbTestController`: 11 نقطة API جديدة كلها AuthMiddleware، عزل التينانت
  عبر `resolveAdsAccess()` + فحص ملكية في الـ Service.
- Lang: 35 مفتاح `ads.ab_tests.*` في en/ar.
- اختبارات `tests/Integration/AdAbTestIntegrationTest.php` (12/70).
- التحقق: **507/15009 OK**، lint 746، PHPStan 0. commit + push على `main`.

## البند 1 (مكتمل): الأصول الإعلانية (Creative Assets) — Ads
- **المشكلة:** كانت إدارة المحتوى الإبداعي مقتصرة على `ad_copies` (نص فقط،
  على مستوى الحملة) بلا أصل إعلاني فعلي ولا تنويعات ولا أداء لكل تنويع.
- migration `2026_08_28_000003_create_ad_creative_assets.sql` (idempotent,
  جداول جديدة فقط): `ad_creatives` (user_id/campaign_id/name/creative_type
  text|image|video/headline/primary_text/media_url/status) +
  `ad_creative_variants` (user_id/creative_id/variant_label/محتوى +
  impressions/clicks/spend/conversions/revenue/is_control) — أعمدة الأداء خام،
  وCTR/CPC/CPA/ROAS تُحسب عند القراءة (مفيش أرقام مختلقة).
- `AdCreativeService`: CRUD مملوك + أرشفة منطقية (تحافظ على السجلات) +
  تسمية تلقائية للتنويعات A/B/C + `recordPerformance()` (أرقام فعلية فقط،
  رفض أي قيمة غير رقمية) + `bestVariant()` بحد أدنى من الانطباعات.
- `AdCreativeController`: 9 نقاط API جديدة كلها AuthMiddleware، عزل التينانت
  عبر `resolveAdsAccess()` (نفس منهجية AdsController) مع فحص ملكية في الـ Service.
- Lang: 37 مفتاح `ads.creatives.*` في en/ar.
- اختبارات `tests/Integration/AdCreativeIntegrationTest.php` (7/29).
- التحقق: **485/14657 OK**، lint 741، PHPStan 0. commit + push على `main`.

# PROGRESS — الخطوة 3: Outreach Discovery + Ads Attribution (CAPI)

**التاريخ:** 2026-08-28
**الفرع:** `main`
**الحالة:** البند 1 (Outreach Discovery) مكتمل ومُدفوع — البند 2 (Ads Attribution/CAPI) مكتمل ومُدفوع

## البند 1 (مكتمل): اكتشاف تلقائي لمرشّحين الـ Backlink
- `ProspectDiscoverySourceInterface` (عقد المصادر) +
  `CompetitorBacklinkDiscoverySource` (المصدر الافتراضي): مرشّحين من
  `competitors.competitor_domain` + `ci_snapshots` (بيانات عامة معلنة فقط).
- **أمان صارم:** لا استخراج WHOIS/إيميلات خاصة؛ `contact_email`/`contact_name`
  = NULL دائمًا للمرشحين المكتشفين؛ الرسالة تُسجَّل draft وتحتاج موافقة
  صريحة (`approveEmail`) قبل أي إرسال — لا إرسال تلقائي.
- `ProspectDiscoveryService::discoverForWebsite()`: منع التكرار (نفس الموقع/
  `link_acquired`/الدومين الذاتي)، `relevance_score` (0-100) من بيانات متاحة،
  حفظ `status='prospect'` + توليد مسودة تلقائية.
- **قرار صادق:** لا توجد بيانات referring-domains حقيقية في CompetitorIntelligence،
  فالمصدر يشتقّ المرشحين من المنافسين المتتبعين ويوثّق ذلك في الكود (بدل اختلاق أرقام).
- `POST /api/outreach/discover` + rate limit `discovery_run` (10/ساعة) عبر
  `CiRateLimiter` القائم.
- `public_html/index.php`: تحميل الملفات الجديدة يدويًا (نمط السيرفر بلا
  composer dump-autoload). `tests/bootstrap.php`: إضافة migrations
  CI/Outreach/Ads (idempotent) إلى `applyTestMigrations()`.
- اختبارات `tests/Integration/OutreachDiscoveryIntegrationTest.php` (10/59).
- التحقق: **429/14297 OK**، lint 730، PHPStan 0. commit + push على `main`.

## البند 2 (مكتمل): ربط الإعلانات بالحجز + CAPI
- migration `2026_08_28_000001_add_booking_ad_attribution.sql`: عمود
  `bookings.attributed_utm_link_id` + FK → `ad_utm_links(id) ON DELETE SET NULL`
  (idempotent). **إصلاح جذري** لـ `2026_08_15_000050` (كانت تكسر قاعدة نظيفة:
  ALTER يشير لعمود غير موجود + عدم idempotency) — الجداول
  `ad_utm_links`/`ad_autopilot_*`/`ad_market_research`/`ad_competitor_insights`
  أصبحت تُنشأ فعلًا والملف قابل لإعادة التشغيل.
- إسناد 30 يوم: `AdTrackingService` (resolveAndTrackClick ترجع utm_link_id +
  platform؛ كوكي `tf_utm_attribution` HttpOnly SameSite=Lax؛ store/read/clear)،
  `redirectUtmClick` يخزّن قبل التحويل.
- `bookSiteItem`: يقرأ الإسناد ويمرّره للحجز مع `source='ad:meta'`/`'ad:google'`
  (بدون إسناد: `website` كما كان). `BookingEngine::createBooking`: يتحقق أن
  الإسناد يخص حملة الحساب نفسه (منع التلاعب) ويثبّته.
- CAPI: `confirmBooking`/`confirmBookingFromPayment` يدفعان `SendAdConversionJob`
  (طابور `ads`) للحجوزات المئسندة فقط؛ الـ job يحوّل PII لـ SHA-256
  (`AdPiiHasher`) ويرسل `MetaAdsAPI::sendConversionEvent` (Meta CAPI, event_id=
  booking_reference) أو `GoogleAdsAPI::sendEnhancedConversion`
  (uploadClickConversions) — بلا أي PII خام، أسرار من إعدادات النظام/.env
  (placeholders في `.env.example`).
- `AdReportService::calculateRoas()`: ROAS من حجوزات confirmed/completed
  المئسندة لكل حملة ÷ `ad_campaigns.spend`.
- اختبارات `tests/Integration/BookingAdAttributionCapiIntegrationTest.php` (14/58).
- التحقق: **457/14413 OK**، lint 733، PHPStan 0. commit منفصل + push على `main`.

## الخطوة 2 (مكتملة): Paymob / ربط CRM / دمج فروع CRM / White-Label


## الخطوة 1 (مكتملة): دمج feat/email-marketing-platform في main
- fetch + فحص diff يدوي: الفرق الوحيد المتبقي كان `d572eb4`
  (List-Unsubscribe RFC 8058) — محتوى `aff5d24` (ربط الحجز بالموقع)
  موجود فعلًا في main عبر PR #47 squash.
- لا توجد أي علامات conflict (`<<<<<<<`) في الشجرة — تحقق مؤكد.
- دمج محلي: conflict واحد فقط في CHANGELOG.md (تتويب) اتحل باليد.
- بعد الدمج: lint 721 ملف / PHPStan 0 أخطاء / pint pass (بعد إصلاح
  تنسيق قديم في `IntegrationsCenterIntegrationTest` — موجود في main
  قبل الدمج، اتصلح في commit منفصل) / 357/13831 اختبار OK.
- push لـ main: `3947d13..7e8a10b`.
- cleanup الفروع (بموافقة المستخدم): حذف 8 فروع remote قديمة
  (المدمجة + فروع البريد القديمة) + المحلية المرافقة.
- النتيجة: main = `7e8a10b`.

## البند 1 (مكتمل): Paymob كبوابة دفع ثانية
- `app/Services/Payment/PaymobGateway.php`: نفس توقيعات
  StripeCheckoutService بالحرف (isConfigured / createCheckoutSession /
  verifyWebhookSignature / handleWebhook) + `key()`.
- تدفق checkout: auth token → order → payment key → iframe؛ معاملة
  pending في payment_transactions (gateway='paymob')، idempotency بنفس
  نمط Stripe.
- Webhook: تحقق HMAC بخوارزمية Paymob الرسمية (21 مفتاحًا مرتّبًا)،
  success → confirmBookingFromPayment + succeeded؛ فشل → failed والحجز
  pending؛ إعادة تسليم idempotent.
- `BookingController`: `checkout()` يدعم `?gateway=stripe|paymob` +
  `resolvePaymentGateway()` (افتراضي Stripe لو مفعّل — ما غيّرناش
  السلوك الحالي) + `paymobWebhook()` + route
  `POST /api/webhook/booking/paymob`.
- `.env.example` + `tests/phpunit.xml`: مفاتيح PAYMOB_*.
- اختبار: `tests/Integration/PaymobBookingIntegrationTest.php`
  (4/28) — كلهم أخضر. الإجمالي بعد البند: **365/13877 OK**، lint 723،
  PHPStan 0، pint pass.

## البند 2 (مكتمل): ربط CRM بالحجز
- migration `crm_deals.booking_id` (nullable FK → bookings، ON DELETE
  SET NULL) في `2026_08_26_000001_add_booking_id_to_crm_deals.sql`.
- `createBooking` يربط الحجز بأول صفقة open لنفس الحساب/العميل
  (customer_id/الإيميل/الهاتف) — لا ينشئ صفقة جديدة.
- `confirmBooking` + `confirmBookingFromPayment` يرفعان الصفقة المربوطة
  لـ won (مع closed_at)؛ idempotent، والإلغاء لا يغيّر حالة الصفقة.
- اختبارات: 7 حالات جديدة في BookingEngineIntegrationTest (الإيميل/
  الهاتف/العميل، المساران اليدوي والمدفوع، عدم الربط لعميل آخر أو
  صفقة won). النتيجة: Integration 113/113، Unit 69/69.
- commit `4b3ee41` مدفوع على main.

## البند 3 (مكتمل): فحص فروع CRM الستة — كلها متجاوبة (لا دمج)
فحص يدوي كامل لكل فرع مقابل أحدث main (لا merge أعمى)، واحد واحد:

| الفرع | الحالة | الدليل على main |
|---|---|---|
| `feat/crm-phase12` | متجاوز — لا دمج | `8d9e10b` (PR #7) = squash لـ `5de852c`؛ محتوى إصلاح الـ conflict markers (`6e3a32c`) موجود؛ **صفر** مفاتيح Lang/مigrations/دوال فريدة؛ علامات `<<<<<<<` المتبقية إيجابي كاذب (فاصل تعليق في `_internal_error_dashboard_9f21x.php:907`) |
| `feat/crm-phase15` | متجاوز — لا دمج | `4647fcf` (PR #19) = squash لـ `1bb07d0`؛ كل الملفات/الـ migrations/الدوال على main |
| `feat/crm-module-sync` | متجاوز — لا دمج | `1eaeae4` مطابق لـ `916a746`؛ `StripeWebhookService` مطابق 0 diff (وهو Webhook استيعاب Revenue Intelligence — منفصل تمامًا عن `StripeCheckoutService` الخاص بالحجوزات) |
| `feat/business-control-center` | متجاوز — لا دمج | دُمج جزئيًا سابقًا فعلًا: `85f77e9` (PR #5، Phase 10-11) + `abac213` (PR #22، باقي المراحل) + كومِتات لاحقة؛ كل الـ controllers/services/models/migrations/routes/التستات على main (التستات على main تحت `tests/Legacy/Business/` والفرع يحملها كـ rename إلى `tests/Unit/Business/`) |
| `feat/ads-professional-module-merge` | متجاوز — لا دمج | `f7d9650` (PR #11) + لاحقات (`afb8389`، `98ea69b`، `3947d13`)؛ AdPermissionService مطابق 0 diff؛ كل دوال AdsController على main بأسلوب أحدث؛ الملفات 000060/000070 موجودة |
| `feat/billing-payment-module-merge` | متجاوز — لا دمج | `441e3d8` (PR #21) مطابق لـ `f003d33`؛ `BillingRules.php` مطابق 0 diff؛ `renewSubscriptionFromBalance` موجود على main؛ صفر ملفات فريدة |

قاعدة الفحص لكل فرع: `git diff -w main <branch>` — الاختلافات كلها
(أ) تنسيقية (PSR-12/pint)، أو (ب) بقايا بنية قديمة من main قبل إعادة
التنظيم (`app/Chat/*` → `app/Services/Chat/*`، `app/app/*`، إلخ)، أو
(ج) ملفات أضافها main بعد snapshot الفرع. **صفر ملفات فريدة ذات محتوى
جديد** في أي فرع.

قرار: عدم الدمج لأي فرع من الستة (دمج كود قديم فوق main الأحدث والأفضل
يضر فقط). يُقترح حذف الفروع الستة من remote والمحلي.

## البند 4 (مكتمل): White-Label — عمولات الوكيل + تقرير الأداء + ربط البراندنج
- migration `2026_08_26_000002_agency_commissions.sql` (idempotent):
  يعيد إنشاء جداول الوكالات الأساسية (agencies/agency_branding/
  agency_domains/agency_clients/agency_email_templates — كانت فقط في
  `_PENDING_TO_RUN_ON_SERVER.sql` المنتهي، فبقت قابلة للبناء على قاعدة
  اختبار جديدة/نشر جديد)، يضيف `agency_clients.commission_rate`
  (ADD COLUMN IF NOT EXISTS، افتراضي 10.00، قابل للتعديل لكل عميل)،
  وينشئ `agency_commissions` (booking_id فريد + FK لـ bookings).
- `BookingEngine`: hook بعد التأكيد (confirmBooking + confirmBookingFromPayment)
  يسجل عمولة pending = total_amount × commission_rate لعملاء الوكالة
  النشطين فقط؛ idempotent عبر ON DUPLICATE KEY. أساس المبلغ = total_amount
  (نفس payment_transactions.amount عند الدفع — لا رسوم بوابة/استرجاع في السكيما).
- `AgencyController` (3 طرق جديدة + routes): `listCommissions`,
  `markCommissionPaid` (يدوي فقط)، `performanceReport` (عملاء نشطون،
  حجوزات مؤكدة، إيراد، عمولات pending/paid) — كلها بعزل صارم عبر
  `ownedAgency()` (وكيل لا يرى ولا يعلّم بيانات وكيل آخر → 404).
- ربط AgencyBranding باللوحة (كان غير مستخدم): `current_user_agency_branding()`
  في i18n.php (عميل نشط أو مالك + static cache)؛ `site_brand_html()`/
  `site_favicon_html()` يفضلان أصول الوكالة المخصصة؛ `renderPanelPage()`
  يحقن ألوان البراندنج كـ CSS variables + custom_css + فافيكون مخصص.
- اختبارات: `tests/Integration/AgencyCommissionIntegrationTest.php`
  (11 حالات / 47 assertion): احتساب العمولة (يدوي/مدفوع، نسبة مخصصة 15%/
  افتراضية 10%)، صفر عمولة بدون وكالة أو لعميل معلّق، idempotency، تغيير
  النسبة يطبق على الحجوزات الجديدة، وعزل صارم (تقرير/قوائم/تعليم مدفوع
  عبر الوكالات → 404).
- التحقق: **401/14043 OK**، lint 725 ملف، PHPStan 0، pint pass.

## البند 5 (مكتمل): اختبار الرحلة الكاملة للحجز (Documentation / Discovery)
اختبار توثيقي/اكتشافي واحد شامل `tests/Integration/FullBookingJourneyIntegrationTest.php`
يعيد بناء الرحلة الكاملة (موقع عام → حجز pending → دفع Stripe/Paymob → confirmed
→ CrmDeal won → عمولة الوكالة → فحص الإشعار → فشل دفع → إلغاء)، بدون أي استدعاء
فعلي لـ Stripe/Rest (webhooks تُنفَّذ على الـ services مباشرة بتوقيع صحيح). أي خطوة
فاشلة كانت ستوثَّق كفجوة — النتيجة: كل الـ 4 سيناريوهات خضراء.

### نتيجة اختبار الرحلة الكاملة (الخطوات العشر)

| الخطوة | الوصف | النتيجة |
|--------|-------|---------|
| 1 | حجز من الصفحة العامة (زائر غير مسجّل) → pending + source='website' + product_id صحيح + total = سعر الرحلة | ✅ نجحت |
| 2 | ربط تلقائي للـ CrmDeal المفتوحة لنفس العميل (crm_deals.booking_id) | ✅ نجحت |
| 3 | معاملة دفع pending (محاكاة محلية لجلسة Stripe/Paymob) | ✅ نجحت |
| 4 | Webhook نجاح Stripe (completed) → confirmed + succeeded + idempotent | ✅ نجحت |
| 5 | الصفقة المربوطة بتتقفل won تلقائيًا (closed_at يُسجَّل) | ✅ نجحت |
| 6 | عمولة الوكالة تُسجَّل تلقائيًا = total × commission_rate (pending) | ✅ نجحت |
| 7 | إيميل تأكيد الحجز | ✅ **مُصلحة** (بند Booking Confirmation Email): `SendBookingConfirmationJob` يتجدول تلقائيًا على طابور `email` من نقطتي التأكيد (يدوي + بعد الدفع)، ويبعت للعميل رقم الحجز/الرحلة/التاريخ/المبلغ — الاختبار يثبت الجدولة فعلًا |
| 8 | الرحلة نفسها عبر Paymob (webhook success=true) → confirmed + won + عمولة | ✅ نجحت |
| 9 | Webhook فشل (Stripe expired) → الحجز pending + المعاملة failed + لا عمولة + لا deal won خاطئة | ✅ نجحت |
| 10 | إلغاء بعد التأكيد (cancelBooking) | ✅ **مُصلحة** (بند Voided Commission): الحجز يتحول cancelled، وعمولة الـ pending تُلغى تلقائيًا (`voided`) داخل نفس الـ transaction؛ الـ `paid` لا تُعكس أبدًا (تنبيه لصاحب الوكالة)، والـ deal المربوطة تبقى `won` — `crm_deals` لا تُلمس عمدًا (قرار بشري موثق بالأسفل) |

### ملاحظة على الإلغاء وعدم لمس crm_deals (قرار بشري متعمّد)
عند إلغاء حجز مؤكد، نقوم بتصفية **العمولة فقط** (`pending` → `voided`). لا نرجع
الـ deal اللي اتقفلت `won` إلى `open`: تعديل حالة الصفقة التاريخية يفسد تقارير
المبيعات ويعكس قرار إغلاق اتخذ فعلًا، بينما العمولة مبلغ مالي تالٍ للتصفية.
الاسترداد/الرجع للصفقات قرار بشري/يدوي عند الحاجة (موثق في `CHANGELOG.md`).

التحقق (بعد بندي Voided Commission + Booking Confirmation Email): **471/14499 OK**
(كانت 457/14413 — أُضيف `BookingCancellationCommissionTest` بـ 3 حالات و
`SendBookingConfirmationJobTest` بـ 4 حالات + تحديث الخطوتين 7 و10)، lint 736 ملف، PHPStan 0.

## قرارات
- بوابة افتراضية = Stripe لو مفعّل (لا تغيير في السلوك القائم)؛ Paymob
  عند طلب صريح أو غياب Stripe.
- لم نلمس SubscriptionController (wallet top-up يفضل Stripe حاليًا).
