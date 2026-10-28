# تحويل JSX إلى صفحات PHP متوافقة — Reputation Overview + Executive Command Center
### 2026-07-15

## السياق
اتبعتلي ملفين React جاهزين (`ReputationDashboard.jsx`, `executive-command-center.jsx`)
لكن الموقع بالكامل server-rendered PHP، مفيش React/JSX ولا build pipeline
في أي حتة. القرار: تحويلهم لصفحتين PHP حقيقيتين بنفس نمط الموقع
(`renderPanelPage` + جدول `p-*` classes + Chart.js)، متوصلين بيانات حقيقية
من قاعدة البيانات - مش بيانات وهمية (MOCK_REVIEWS/seedSeries) زي الملفين
الأصليين.

## قرارات عدم التكرار
- الـ JSX الأصلي لصفحة السمعة كان تكرار جزئي لصفحات `/reputation/reviews`
  و`/reputation/stats` الموجودة أصلًا - فبدل صفحة موازية، اتعملت
  `/reputation/overview` كصفحة "نظرة شاملة" مكمّلة (مؤشرات + اتجاه أسبوعي
  لكل منصة + تنبيهات + اقتراحات تحسين + Approve/Edit/Dismiss) مش بديلة.
- الـ JSX الأصلي لـ "Executive Command Center" كان فيه 15 موديول KPI
  (Revenue, GA, GSC, Meta/Google Ads ROAS, WhatsApp...) - `mock` بالكامل.
  اللي اتوصل فعليًا بأرقام حقيقية بس اللي عنده مصدر بيانات موجود في
  القاعدة: تقييم السمعة (`reviews`)، صفقات/عملاء CRM (`crm_deals`/
  `crm_leads`)، إنفاق الإعلانات (`ad_campaigns.spend`)، رسائل الشات،
  تقارير AI، المنافسون المتابَعون، وكالات White-Label. الإيرادات
  المباشرة وGoogle Analytics/Search Console **اتسابت كبطاقة "مش متصلة
  بعد" واضحة** بدل أرقام وهمية - مفيش جدول orders/مدفوعات ولا اتصال
  GA/GSC حقيقي في الموقع حاليًا (ده بالظبط سبب إن موديول Analytics في
  الدفعة اللي فاتت اتأجل جزئيًا لنفس السبب).
- منصة "Facebook" في الـ JSX الأصلي مش موجودة في `reviews.platform`
  ENUM الفعلي (اللي فيه tripadvisor/google_business/booking/expedia/
  trustpilot/other) - اتشالت من كل الأماكن بدل ما تتحط كمنصة وهمية.

## ملفات جديدة
- **Migration** `2026_07_15_000013_add_reputation_reply_status.sql`:
  عمود `reviews.reply_status` (pending/approved/dismissed) - إضافي بس،
  من غير لمس `reply_sent` الموجود. مُضاف كمان لآخر
  `_PENDING_TO_RUN_ON_SERVER.sql` عشان تقدر تشغّله دفعة واحدة.
- **`ReputationController::showOverview`** (`GET /reputation/overview`) +
  **`getOverviewData`** (`GET /api/reputation/overview-data`) +
  **`dismissReply`** (`POST /api/reputation/review/{id}/dismiss`).
  الاعتماد والتعديل بيستخدموا الـ endpoints الموجودة أصلًا
  (`sendReply`/`updateReply`) - مفيش تكرار منطق.
- **`DashboardController::executive`** (`GET /dashboard/executive`) +
  حالة `executive` جديدة في `renderDashboardPanelBody` + `loadExecutive()`
  في الـ JS - بيستخدم الـ endpoints الموجودة فعليًا
  (`/api/dashboard/stats`, `/api/crm/pipeline-stages`, `/api/crm/deals`,
  `/api/crm/leads`, `/api/ads/campaigns`, `/api/ai/competitors`) +
  `/api/reputation/overview-data` الجديد. صفر نداءات API جديدة إضافية
  غير reputation overview.
- كلاسات `.p-tab` / `.p-tabs` في `panel.css` (فلاتر السمعة) - مش موجودة
  في الملف الأصلي، إضافة بسيطة.

## ملفات معدَّلة
- `app/Core/Controller.php`: عنصر سايد بار جديد "نظرة عامة على السمعة".
- `app/Controllers/DashboardController.php`: تبويب "لوحة القيادة
  التنفيذية" جديد في السايد بار + العنوان + الجسم + الـ JS.
- `app/routes/web.php` / `app/routes/api.php`: تسجيل كل الـ routes الجديدة.

## الخطوة التالية
لسه فاضل من قائمة الموديولات اللي مبعوتلي إياها: `ai-pricing-engine`,
`ai-automation-builder`, `ai-competitor-intelligence-hub` (تحديث),
`ai-tourism-advisor-module13`, `lead-intelligence-module`,
`customer-success`, و`module15-reputation-backend` (اللي شكله تكرار
كامل لـ `app/Reputation` الموجود، محتاج تأكيد منك قبل ما نتجاهله رسميًا).
قولّي تحب نبدأ بأنهي واحد.
