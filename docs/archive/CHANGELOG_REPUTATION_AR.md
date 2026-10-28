# CHANGELOG — حماية السمعة وحدود الباقة (2026-10-18)

## الجديد
| الملف | الوصف |
|---|---|
| `database/migrations/2026_10_18_000001_email_reputation_guard.sql` | جداول: `email_account_limits`, `email_send_usage`, `email_reputation_events`, `email_global_suppressions` + أعمدة المستلمين (attempts/next_attempt_at/error_class/bounced_at/complained_at) + أعمدة إيقاف الحملة + فهارس + **توسيع ENUM الحالات** (sending/uncertain/partial/paused) لأن الكود كان بيستخدمها وهي مش في الـ migrations الأصلية |
| `app/Services/EmailMarketing/EmailReputationGuard.php` | قلب الحماية (تفاصيل تحت) |
| `app/Controllers/AdminEmailReputationController.php` | API الأدمن: نظرة عامة، إيقاف/استئناف حساب/حملة، الحدود، موافقة أول حملة، حظر عام، سجل تدقيق |
| `excerpts/routes_api.email_reputation.txt` | الـ routes المطلوب إضافتها |
| `tests/Unit/EmailReputationGuardTest.php`, `tests/Integration/EmailReputationGuardIntegrationTest.php` | 45 اختبار |

## EmailReputationGuard
- **حدود**: ساعي/يومي/شهري + Throttle لكل دقيقة. الأولوية: تخصيص الحساب > الباقة (`EMAIL_PLAN_LIMITS` + planResolver) > الافتراضي.
- **Warm-up**: 50→100→250→500→1000→2000→3500→5000 يوميًا للحسابات الجديدة. الحسابات اللي ليها تاريخ إرسال بتتعفى تلقائيًا (ما يحصلش تقييد مفاجئ للعملاء الحاليين).
- **حجز حصة atomic** (reserve/refund + `GET_LOCK`): حملتين لنفس العميل بالتوازي ما يتجاوزوش الحد.
- **إيقاف تلقائي**: bounce ≥5% أو complaint ≥0.3% (قابلة للضبط لكل عميل) → إيقاف الحملة؛ على مستوى الحساب (30 يوم) → إيقاف الحساب وكل حملاته + تنبيه. حد أدنى للعينة، و5 شكاوى مطلقة تكفي. بعد استئناف الأدمن بيتحسب baseline فلا يعاد الإيقاف على أرقام قديمة.
- **تصنيف الأخطاء**: temporary (يُعاد بـ backoff 5د/20د/80د حتى 3 محاولات) / invalid_recipient (Hard bounce: يتحظر العنوان تلقائيًا ويتسجل ارتداد) / blocked / permanent. المستلم `uncertain` لا يُعاد إرساله أبدًا.
- **حظر عام** (إيميل/دومين) مطبّق على الجمهور وعلى لحظة الإرسال.
- **سجل تدقيق** لكل إجراء. **مراجعة أول حملة** اختيارية (`EMAIL_REQUIRE_FIRST_CAMPAIGN_REVIEW`).
- عدّاد استهلاك للعميل: `GET /api/email-marketing/quota` (ومضاف لـ dashboard). إيقاف/استئناف يدوي من العميل لحملته.

## محور التسليم (إضافة 2)
- `EmailListHygieneService`: تقييم العنوان (reject: صيغة غلط/دومين مؤقت/اختياريًا بلا MX — risky: حساب وظيفي info@ أو خطأ كتابة gmial.com مع اقتراح التصحيح). فحص MX اختياري وعطل DNS المؤقت لا يسبب رفض.
- `DomainAuthChecker`: فحص SPF (سجل مكرر، +all/?all، حد 10 lookups، ptr) وDKIM (selectors شائعة أو محدد، مفتاح ملغى) وDMARC (السياسة، rua، pct) مع درجة 0-100 وتوصيات بالعربي. endpoint: `GET /api/email-marketing/domain-check?domain=&selector=`.
- `importContacts` بيطبّق التنضيف تلقائيًا (disposable + الحظر العام) ويرجّع `rejected`/`risky`/`reject_reasons`؛ تعطيله: `options['hygiene']=false`، فحص MX: `options['check_mx']=true`.
- لم أفرض منع الإرسال من دومين غير موثّق (قرار منتج: ممكن يضر عملاء شرعيين) — الفحص استشاري حاليًا.
- اختبارات: `tests/Unit/EmailDeliverabilityToolsTest.php` (13).

## محور الامتثال (إضافة 3)
- migration: `2026_10_19_000001_email_compliance.sql` (`email_consent_log`, `email_data_requests`).
- `EmailComplianceService`: سجل موافقات (المصدر/IP/نص الموافقة/الوقت)، تصدير بيانات مشترك (GDPR access)، محو مشترك (حذف البيانات الشخصية + إخفاء هوية سجل الحملات مع بقاء الإحصائيات + الاحتفاظ بالإيميل في قائمة المنع افتراضيًا)، تدقيق الطلبات بـ hash بدل الإيميل، وفحص جاهزية الحساب (اسم الشركة للتذييل القانوني + تحذير بالمشتركين بلا موافقة موثقة).
- `EmailListService`: بيسجّل الموافقة تلقائيًا عند الاشتراك/التأكيد/الإلغاء (تمرير اختياري: `consent_ip`, `consent_text`, `user_agent` في `$data` — محتاج تمريرهم من نموذج الاشتراك العام).
- endpoints: `GET /compliance`، `GET/DELETE /contacts/subscribers/{id}/{consent|export-data|erase}` (في ملف الـ routes).
- اختبارات: `tests/Integration/EmailComplianceIntegrationTest.php` (9). الإجمالي الآن 66 اختبار.
- ملاحظة: المشتركون القدامى ملهمش سجل موافقة؛ مفيش اختلاق لسجلات رجعية — التحذير بيظهرهم.

## واجهة الأدمن (إضافة 4)
- `public_html/assets/js/admin-email-reputation.js` + `css/admin-email-reputation.css`: لوحة RTL بثلاث تبويبات (نظرة عامة بمعدلات الارتداد/الشكاوى لكل عميل، تفاصيل عميل بعدّادات الاستهلاك + إيقاف/استئناف + تعديل الحدود، الحظر العام، سجل التدقيق). داكن/فاتح، متجاوبة، وكل نص قادم من الخادم بيتعمل له escape.
- التركيب: أضف `<div id="email-reputation-root"></div>` في صفحة أدمن + الـ css/js، ثم `EmailReputationAdmin.mount(document.getElementById('email-reputation-root'), {headers:{...}})` (headers فقط لو الـ API بتوكن مش كوكي).
- اتجرّبت في Chromium بـ API وهمي (تدفقات الإيقاف/الاستئناف/الحدود/الحظر + حقن XSS)؛ لم تُجرَّب على صفحات الأدمن الفعلية. عدّاد العميل: `js/email-quota-widget.js` + css (`EmailQuotaWidget.mount(el)`) بيعرض الاستهلاك وبانر الإيقاف وتنبيه warm-up (اتجرّب بنفس الطريقة).

## تعديلات على ملفات موجودة
- `EmailCampaignService::sendBatch`: preflight + reserve/refund + retry لكل مستلم + checkHealth + `retry_after`؛ `schedule()` بيرفض لو الحساب موقوف؛ الحملة `paused` غير قابلة للتعديل/الحذف.
- `SendEmailCampaignBatchJob`: بيحترم `retry_after` ومابيعيدش الجدولة لو paused/blocked (بدل لفّ كل ثانية).
- `ContactManagementService`: `recordDeliveryIssue` بيربط الارتداد/الشكوى بالحملة وبيشغّل مراقبة السمعة؛ **إصلاح**: webhook SendGrid كان بيعالج أول حدث فقط من الدفعة — دلوقتي بيعالجها كلها؛ **حماية replay** (نافذة 10 دقايق، `EMAIL_WEBHOOK_TOLERANCE_SECONDS`، 0 = تعطيل).
- `EmailMarketingController`: preflight في `sendCampaign`، + `quota` / `pauseCampaign` / `resumeCampaign`.
- `EmailDeliveryWebhookIntegrationTest`: الـ timestamp بقى `time()` بدل قيمة ثابتة قديمة (بسبب فحص replay).

## خطوات التركيب
1. شغّل الـ migration (الـ ENUM MODIFY: راجع أي قيم إضافية عندكم في الأعمدة دي قبل التشغيل).
2. أضف الـ routes من `excerpts/routes_api.email_reputation.txt` (+ صلاحيات الأدمن).
3. (اختياري) عرّف الثوابت: `EMAIL_DEFAULT_*_LIMIT`, `EMAIL_PLAN_LIMITS`, `EMAIL_BOUNCE_PAUSE_RATE`, `EMAIL_COMPLAINT_PAUSE_RATE`, `EMAIL_REQUIRE_FIRST_CAMPAIGN_REVIEW`.
4. ربط تنبيهات الأدمن: مرّر `notifier` لـ `EmailReputationGuard` (الافتراضي: Logger).

## حدود التحقق (مهم)
- الاختبارات اتشغّلت على **SQLite** بـ shim بسيط لـ PHPUnit/Database لأن MySQL وPHPUnit مش متاحين في بيئتي. شغّلها عندكم على MySQL الحقيقية.
- `sendBatch` نفسه (UPDATE…JOIN, SHA2, ExternalExecution) و`handleDeliveryWebhook` متراجعين بالقراءة وفحص `php -l` فقط، مش متنفذين end-to-end. جرّبهم على staging بحملة صغيرة.
- الواجهة (HTML) للوحة الأدمن ولعدّاد العميل لم تُكتب — الـ API جاهز.


---

## المرحلة 3 — النمو والتقارير (2026-10-20)

### A/B Auto-Winner (holdout)
- Migration: `2026_10_20_000001_email_ab_auto_winner.sql` (holdout_percent, auto_winner, test_hours, started_at, holdout_campaign_id, holdout_sent_at).
- `AbTestAutoWinnerService`: الاختبار على عينة عشوائية ثابتة، وبعد `test_hours` وانتهاء الإرسال يختار الفائز (دلالة إحصائية ≥95%، وإلا المتقدم، وإلا أ) ويبعته لباقي الجمهور مرة واحدة (قفل ضد التكرار). الإرسال بيعدي على EmailReputationGuard.
- Cron: `cron/process_ab_winners.php` كل 10 دقائق. التفعيل من `create()` بـ `holdout_percent` (≥10 مع auto_winner) و`auto_winner` و`test_hours`.
- بدون holdout: السلوك القديم كما هو.

### التقارير
- `EmailReportService`: ملخص، سلسلة زمنية يومية، أفضل ساعات (مع إزاحة توقيت)، مقارنة حتى 10 حملات، CSV آمن (BOM + حماية formula injection)، إيرادات عبر `revenueResolver` اختياري.
- مسارات جديدة: `/campaigns/{id}/insights`, `/campaigns/{id}/export.csv`, `/reports/compare` (الـ `campaignReport` القديم لم يُمس).

### الأتمتة: شرط وهدف
- خطوتان جديدتان: `condition` (has_tag/not_has_tag/in_list/not_in_list/opened_last_email/clicked_last_email + نقائضها) مع `else_position` للأمام فقط (يمنع الحلقات)، و`goal` (لو تحقق الشرط تنتهي المشاركة). لا migration (step_type نصي).
- ملاحظة: `else_position` في setSteps = index الخطوة في القائمة المرسلة، و-1 = إنهاء.

### الاختبارات
- جديد: AbTestAutoWinnerTest (5)، EmailReportServiceTest (5)، EmailAutomationBranchingTest (3). كلها للدوال النقية.
- orchestration (`finalizeDue`, `processEntry` الجديد، استعلامات التقارير) راجعتها بالقراءة و`php -l` فقط — شغّلها على Staging حسب `STAGING_RUNBOOK_AR.md`.
