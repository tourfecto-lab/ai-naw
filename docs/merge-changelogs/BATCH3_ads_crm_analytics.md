# دمج الدفعة الثالثة — ads/crm-leads/analytics
### 2026-07-14

## قرارات عدم التكرار
- `ai-ads-management-hub`: جدول `ad_accounts` (اتصال OAuth) اتجوهل —
  `platform_connections` الموجود يغطيه بالظبط.
- `ai-crm-leads-hub`: جداول `tenants`/`users`/`customers`/`leads` الخاصة
  به اتجوهلت بالكامل — `agencies`/`users`/`crm_contacts`/`crm_leads`
  الموجودة تغطي نفس المفهوم. `lead_scores` اتجوهل (عمود `score`
  الموجود في `crm_leads` يكفي). `activities` اتجوهل لصالح
  `activity_logs` الموحّد.
- `ai-crm-leads-hub`: `whatsapp_messages`/`email_messages` **لم تُدمج
  عمدًا** - تحتاج تكامل حقيقي (WhatsApp Business API / SMTP) غير متوفر؛
  إضافتها فارغة كانت هتبقى واجهة بلا وظيفة.
- `ai-analytics-insights-hub`: دمجنا بس 4 جداول (`analytics_traffic`,
  `analytics_conversions`, `analytics_device_breakdown`,
  `analytics_country_breakdown`) من أصل 12. الباقي (`landing_pages`,
  `social_insights`, `local_performance`, `keyword_rankings`,
  `user_behavior`, `generated_reports`...) **مؤجَّل عمدًا** لأنها جداول
  "استقبال بيانات" محتاجة مصدر حقيقي (Google Analytics API) يغذّيها.

## ملفات جديدة
- **Migrations**: `2026_07_14_000010` (توسعة الإعلانات)،
  `2026_07_14_000011` (توسعة CRM - صفقات/مراحل/مهام/اجتماعات/ملاحظات،
  فيها INSERT لـ 6 مراحل افتراضية جاهزة)، `2026_07_14_000012` (تحليلات).
- **14 Model جديد** (AdCopy, AdKeyword, AdAudience,
  AdBudgetRecommendation, AdPerformanceReport, AdOptimizationLog,
  CrmPipelineStage, CrmDeal, CrmTask, CrmMeeting, CrmNote,
  AnalyticsTraffic, AnalyticsConversion + Service واحد).
- **`AdCopyGenerationService`**: توليد نصوص إعلانية حقيقي بالذكاء
  الاصطناعي (نفس GeminiClient الموحّد).

## ملفات معدَّلة
- **`AdsController`**: زر "توليد ✨" جديد بجانب كل حملة يولّد 3 نصوص
  إعلانية (A/B/C) فعليًا بالذكاء الاصطناعي.
- **`CrmController`**: 4 endpoints جديدة (مراحل المسار، قائمة/إنشاء
  الصفقات). **لوحة Kanban بصرية للصفقات لسه معملتش** (الـ backend كامل
  وشغّال، لكن الواجهة حاليًا API فقط - JSON list، مش سحب وإفلات
  بصري). لو عايزها، دي خطوة تالية واضحة.

## 🐛 خطأ اكتشفته وصلحته بنفسي أثناء البناء
كنت هستخدم `Model::where(['agency_id' => null])` لجلب مراحل المسار
الافتراضية - اكتشفت إن الـ `where()` الأساسي في المشروع بيولّد
`agency_id = ?` مع NULL كقيمة، وده تعبير SQL **دايمًا كاذب** (`x = NULL`
لا يساوي أبدًا `true` في MySQL - لازم `IS NULL`). صلحتها فورًا باستخدام
SQL خام قبل ما توصلك كخطأ في الإنتاج.

## الخطوة التالية
كل الملفات دي **مُدمَجة بالفعل في نسخة العمل الكاملة** التي عندي. لو
عايز الموقع الكامل المُحدَّث (زي آخر مرة)، قولّي وأبعتلك zip واحد شامل
كل الدفعات التلاتة + هذه.
