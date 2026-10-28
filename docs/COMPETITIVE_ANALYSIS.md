# التحليل التنافسي — Tourfecto AI CRM (2026-08-15)

مقارنة منهجية لموديول AI CRM داخل منصة Tourfecto ضد أبرز حلول CRM العالمية
(HubSpot, Salesforce, Pipedrive, Zoho CRM, Freshsales/Freshworks) مع مراعاة
خاصة لسوق المنطقة العربية (RTL, WhatsApp-first, مدفوعات محلية).

> **القيد المنهجي:** التحليل معتمد على الوثائق/الصفحات الرسمية العامة للـمنافسين
> (تم جلبها في هذه الجلسة) + مخزون الميزات الفعلي للموديول (تم مسحه من الكود).
> الأرقام التسويقية للـمنافسين (أسعار، أعداد عملاء) تُذكر كسياق فقط ولا تُستخدم
> كادعاء تفوق.

---

## 1. نطاق المقارنة

| البُعد | المنافسون | الموديول الحالي |
|---|---|---|
| السوق المستهدف | عالمي (متعدد اللغات) | عربي أولًا (ar/en)، مدمج في منصة سياحة/وكالات |
| نموذج النشر | SaaS سحابي | مدمج (Embedded) داخل منصة Tourfecto متعددة الموديولات |
| المصدر | مغلق | مفتوح المصدر داخل المستودع، بدون تبعيات خارجية |
| الثمن | Freemium / لكل مقعد شهريًا | مجاني ضمن اشتراك المنصة |

---

## 2. ملخص وضع الموديول لكل منطقة ميزات

الأسطورة: ✅ موجود · 🔶 جزئي/محدود · ❌ غير موجود

### 2.1 إدارة العملاء المحتملين (Leads)

| الميزة | HubSpot | Pipedrive | Zoho | Freshsales | الموديول |
|---|---|---|---|---|---|
| التقاط Leads (Web Form / API) | ✅ | ✅ | ✅ | ✅ | ✅ (Form Builder + رابط عام + إرسال عام يخلق Contact/Lead) |
| مصادر Leads قابلة للتخصيص | ✅ | ✅ | ✅ | ✅ | ✅ |
| Scoring قائم على قواعد | ✅ | 🔶 | ✅ (Zia AI) | ✅ (Freddy AI) | ✅ (قواعد قابلة للشرح، بدون AI) |
| **Scoring تنبؤي (ML)** | ✅ | 🔶 | ✅ | ✅ | ❌ |
| تحويل Lead → Deal | ✅ | ✅ | ✅ | ✅ | ✅ |
| تعيين Owner + توجيه تلقائي (Routing) | ✅ | ✅ | ✅ | ✅ | 🔶 (تعيين يدوي، بدون Rules تلقائية) |
| حالات مخصصة | ✅ | ✅ | ✅ | ✅ | ✅ (مصادر وحالات Lead) |

### 2.2 جهات الاتصال / الشركات (Contacts/Companies)

| الميزة | HubSpot | Pipedrive | Zoho | Freshsales | الموديول |
|---|---|---|---|---|---|
| 360 View موحّد | ✅ | ✅ | ✅ | ✅ | ✅ (Contact 360 مع Timeline) |
| كشف التكرارات + الدمج | ✅ | ✅ | ✅ | ✅ (Freddy) | ✅ |
| شرائح (Segments) | ✅ | ✅ | ✅ | ✅ | ✅ |
| **حقول مخصصة (Custom Fields)** | ✅ | ✅ | ✅ | ✅ | ❌ |
| **مراحل دورة حياة مخصصة للعملاء** | ✅ | 🔶 | ✅ | ✅ | 🔶 (حالات Lead فقط) |
| إثراء تلقائي (Enrichment) | ✅ | 🔶 | ✅ | ✅ | ❌ |
| علاقات متعددة (Primary/Secondary) | ✅ | ✅ | ✅ | ✅ | 🔶 (company_id واحد) |

### 2.3 الصفقات / خطوط الأنابيب (Deals/Pipelines)

| الميزة | HubSpot | Pipedrive | Zoho | Freshsales | الموديول |
|---|---|---|---|---|---|
| Pipelines متعددة | ✅ | ✅ | ✅ | ✅ | ✅ |
| Kanban Board | ✅ | ✅ | ✅ | ✅ | ✅ |
| منتج/كتالوج + سطور بنود | ✅ | ✅ | ✅ | ✅ (Product Catalog) | ❌ |
| تنبؤ (Forecast) | ✅ | ✅ | ✅ | ✅ | ✅ (إحصائي، "تقديري") |
| صفقات راكدة (Rotten/At-Risk) | ✅ | ✅ | 🔶 | ✅ | ✅ |
| **Win/Loss Analysis** | ✅ | ✅ | ✅ | ✅ (لسبب الخسارة) | 🔶 (نحفظ lost_reason لكن بدون تقرير) |
| Goals للأفراد/الفرق | ✅ | ✅ | ✅ | ✅ | ❌ |

### 2.4 الأنشطة (Tasks/Notes/Appointments)

| الميزة | HubSpot | Pipedrive | Zoho | Freshsales | الموديول |
|---|---|---|---|---|---|
| Tasks/Follow-ups | ✅ | ✅ | ✅ | ✅ | ✅ |
| Notes | ✅ | ✅ | ✅ | ✅ | ✅ |
| Appointments/Meetings | ✅ (Meeting Scheduler) | ✅ | ✅ | ✅ | ✅ |
| أنشطة مخصصة | ✅ | ✅ | ✅ | ✅ | ❌ |
| نشاط زمني موحّد (Timeline) | ✅ | ✅ | ✅ | ✅ | ✅ (100 حدث) |

### 2.5 الأتمتة (Automation)

| الميزة | HubSpot | Pipedrive | Zoho | Freshsales | الموديول |
|---|---|---|---|---|---|
| Workflows قائمة على أحداث | ✅ | ✅ | ✅ | ✅ | ✅ |
| Builder بصري (Schema) | ✅ | ✅ | ✅ | ✅ | ✅ (SCHEMA + مسبقة القالب) |
| قوالب أتمتة جاهزة | ✅ | ✅ | ✅ | ✅ | ✅ (Templates) |
| **أتمتة اتصالات خارجية (Email/WA/SMS)** | ✅ | ✅ | ✅ | ✅ (Sales Sequences) | ❌ (مقصود: إجراءات داخلية فقط) |
| Sequences متعددة الخطوات | ✅ | ✅ (Projects) | ✅ | ✅ | ✅ (تسلسلات بعدة خطوات مؤجلة + تسجيل Contact/Lead/Deal) |

### 2.6 التواصل (Communication)

| الميزة | HubSpot | Pipedrive | Zoho | Freshsales | الموديول |
|---|---|---|---|---|---|
| Email داخل الـCRM | ✅ | ✅ | ✅ | ✅ | ✅ |
| WhatsApp | 🔶 | 🔶 (Marketplace) | ✅ | ✅ | ✅ (Meta Cloud API) |
| SMS | 🔶 | 🔶 | ✅ | ✅ | ✅ (Twilio، HMAC موثّق) |
| **مكتبة قوالب رسائل** | ✅ | ✅ | ✅ | ✅ | ❌ |
| صندوق وارد موحّد | ✅ | ✅ | 🔶 | ✅ | ✅ (Conversations) |
| Webhooks واردة (Inbound) | ✅ | ✅ | 🔶 | ✅ | ✅ (WA/SMS/Email) |
| تتبع فتح البريد | ✅ | ✅ | 🔶 | ✅ | ❌ |
| **تعدد أرقام WhatsApp لكل تينانت** | 🔶 | 🔶 | ✅ | 🔶 | ❌ (رقم واحد منصّي، موثّق) |

### 2.7 الذكاء الاصطناعي

| الميزة | HubSpot | Pipedrive | Zoho | Freshsales | الموديول |
|---|---|---|---|---|---|
| مساعد AI | ✅ (Breeze) | ✅ | ✅ (Zia) | ✅ (Freddy) | ✅ (مساعد: SQL → Gemini) |
| ملخص عميل AI | ✅ | ✅ | ✅ | ✅ | ✅ |
| Next Best Action | ✅ | 🔶 | ✅ | ✅ (Deal Insights) | ✅ (قواعد) |
| **تنبؤ مغلق/توقع إيراد (ML)** | ✅ | 🔶 | ✅ | ✅ | 🔶 (إحصائي فقط) |
| كشف الغياب (Out-of-office) | 🔶 | ❌ | 🔶 | ✅ | ❌ |
| **وكلاء/أتمتة بـAI** | ✅ (Agents) | 🔶 | ✅ | ✅ | ❌ |

### 2.8 الفريق والصلاحيات

| الميزة | HubSpot | Pipedrive | Zoho | Freshsales | الموديول |
|---|---|---|---|---|---|
| أدوار مخصصة | ✅ | ✅ | ✅ | ✅ | ✅ (5 أدوار ثابتة) |
| عزل بيانات التينانت | ✅ | ✅ | ✅ | ✅ | ✅ (user_id + resolveTenantId) |
| **Leads/Deals عبر طبقة الأدوار** | ✅ | ✅ | ✅ | ✅ | 🔶 (موثّق: Leads/Deals على المنطق القديم) |
| دعوة أعضاء فريق | ✅ | ✅ | ✅ | ✅ | ❌ (يجب أن يكون له حساب بالفعل) |
| Audit Logs | ✅ | ✅ | ✅ | ✅ | ✅ (نشاط + AuditLog) |
| تفويض/إعداد SSO/2FA | ✅ | ✅ | ✅ | ✅ | ❌ (خارج نطاق الموديول) |

### 2.9 التقارير

| الميزة | HubSpot | Pipedrive | Zoho | Freshsales | الموديول |
|---|---|---|---|---|---|
| لوحة تحكم/إحصائيات | ✅ | ✅ | ✅ | ✅ | ✅ (مخزّنة 90 ثانية) |
| تقارير قابلة للتخصيص | ✅ | ✅ | ✅ | ✅ | ✅ (Report Builder: كيان + حقول + فلاتر + تجميع + حفظ) |
| Win/Loss Analysis | ✅ | ✅ | ✅ | ✅ | 🔶 |
| Sales Goals | ✅ | ✅ | ✅ | ✅ | ❌ |

### 2.10 الاستيراد/التصدير

| الميزة | HubSpot | Pipedrive | Zoho | Freshsales | الموديول |
|---|---|---|---|---|---|
| استيراد CSV بمرحلتين | ✅ | ✅ | ✅ | ✅ | ✅ (Preview → Commit) |
| استيراد خلفي (Async/Background) | ✅ | ✅ | ✅ | ✅ | ✅ (Job + Queue) |
| تصدير CSV | ✅ | ✅ | ✅ | ✅ | ✅ (UTF-8 BOM) |
| **الاستيراد من CRMs أخرى** | ✅ | ✅ | ✅ (Zwitch) | ✅ | ✅ (قوالب جاهزة: HubSpot/Zoho/Pipedrive/Freshsales) |
| سجل التكرارات وقت الاستيراد | ✅ | ✅ | ✅ | ✅ | ✅ (duplicate_candidates) |

---

## 3. الفجوات الأعلى أولوية (Gap Analysis)

مرتبة حسب: (الأثر التنافسي × التكلفة التقريبية × التوافق مع القيود المعمارية).

### 3.1 أولوية عالية (ضرورية للمنافسة الأساسية)

| # | الفجوة | المنافسون الذين يملكونها | الفجوة الحالية في الموديول |
|---|---|---|---|
| G1 | **مكتبة قوالب الرسائل** (Email/WhatsApp/SMS + متغيرات) | كلهم | الرسائل تُرسل كنصوص مبعثرة بدون قوالب محفوظة قابلة لإعادة الاستخدام | ✅ منفَّذ في المرحلة 12 |
| G2 | **الحقول المخصصة** (Custom Fields) على Contacts/Leads/Deals | كلهم | لا يوجد سوى الحقول الثابتة المعرّفة في الجداول | ✅ منفَّذ في المرحلة 12 |
| G3 | **كتالوج منتجات + سطور بنود للصفقات** (Product Catalog/Line Items) | كلهم (Pipedrive/Zoho/Freshsales بصراحة) | قيمة الصفقة تُدخل رقميًا فقط، بدون منتجات/كميات/سعر | ✅ منفَّذ في المرحلة 13 |
| G4 | **تقرير Win/Loss + Sales Goals** | كلهم | `lost_reason` يُحفظ لكن لا يوجد تقرير تحليلي؛ لا توجد أهداف | ✅ منفَّذ في المرحلة 12 |
| G5 | **التوجيه التلقائي للـLeads** (Auto-assignment Rules) | Freshsales/Pipedrive | تعيين يدوي فقط | ✅ منفَّذ في المرحلة 13 |

### 3.2 أولوية متوسطة (تمايز قوي)

| # | الفجوة | ملاحظات |
|---|---|---|
| G6 | **مراحل دورة حياة مخصصة للعملاء** (Contact Lifecycle) | تفصل "جديد/مؤهل/عميل/خامل/مفقود" عن حالة Lead | ✅ منفَّذ في المرحلة 13 |
| G7 | **إحصائيات + رسوم بيانية للتقرير** (بحسب الفترة، رسم بياني للـPipeline) | حاليا جداول/تiles فقط | ✅ منفَّذ في المرحلة 14 |
| G8 | **تتبع فتح البريد** (Email Open Tracking) | إضافة بكسل تتبع في Mailer | ✅ منفَّذ في المرحلة 14 |
| G9 | **دعوة أعضاء الفريق عبر بريد إلكتروني** | رفع قيد "يجب أن يكون له حساب" | ✅ منفَّذ في المرحلة 13 |
| G10 | **أنشطة/نتائج مخصصة** (Custom Activity Types) | مثل زيارات الموقع، مكالمات | ✅ منفَّذ في المرحلة 14 |

### 3.2ب فجوات مكمِّلة (المرحلة 15)

| # | الفجوة | ملاحظات |
|---|---|---|
| G11 | **التقاط Leads عبر Web Form** (Form Builder) | نموذج عام بحقول مخصصة + رابط slug + إرسال عام ينشئ Contact/Lead | ✅ منفَّذ في المرحلة 15 |
| G12 | **Sequences متعددة الخطوات** | تسلسل خطوات مؤجلة (مهمة/إيميل/واتساب/ملاحظة/إشعار) على Contact/Lead/Deal | ✅ منفَّذ في المرحلة 15 |
| G13 | **تقرير قابل للتخصيص** (Report Builder) | كيان + حقول + فلاتر + تجميع + ترتيب، يُحفظ ويُعاد تشغيله | ✅ منفَّذ في المرحلة 15 |
| G14 | **استيراد من CRMs خارجية** | قوالب جاهزة لرؤوس CSV الخاصة بـHubSpot/Zoho/Pipedrive/Freshsales | ✅ منفَّذ في المرحلة 15 |

### 3.3 أولوية منخفضة / خارج نطاق تنفيذ اليوم

- AI تنبؤي ML (يتطلب تدريب/بايبل - مكتوب صراحة أن الإحصائي مقصود).
- وكلاء AI مستقلين (Breeze Agents/Freddy) - تكلفة ضخمة.
- Mobile App أصلي - خارج نطاق الموديول.
- إثراء تلقائي من جهات خارجية (متطلب بيانات خارجية + ميزانية).
- تعدد أرقام WhatsApp (يتطلب OAuth لكل تينانت - موثّق كقيد).

---

## 4. توصيات خطة الترقية (معروضة على الأساس المعماري)

### المبادئ المقيِّدة (من سياق المشروع)
1. **Additive فقط**: إضافة ملفات/جداول/مسارات/مفاتيح، لا تعديل على منطق `CrmController` الأصلي.
2. **لا تبعيات خارجية** جديدة للاختبارات/الكود الجاري (كل الاختبارات بدون PHPUnit).
3. **عزل التينانت** عبر `user_id` + `resolveTenantId()` كما هو قائم.
4. **لا اختراع معلومات غير موجودة**: التكاملات غير المختبرة تظل موثّقة كـ"غير مختبرة".
5. كل ميزة تحتاج: Migration SQL + Model + Service + نقاط API في `CrmApiController` + مسارات + مفاتيح Lang (ar/en).

### الجولة المقترحة 1 (ذات قيمة/تكلفة ممتازة) — ✅ نُفِّذت بالكامل في المرحلة 12 (2026-08-15)
1. **G1 — Message Templates** (`crm_message_templates`):
   - جدول: id, user_id, channel (email/whatsapp/sms), name, subject, body, variables, created_by.
   - Service `CrmMessageTemplateService`: CRUD + `render(template, context)` لاستبدال المتغيرات `{{name}}`, `{{phone}}`, `{{company}}`.
   - نقاط API: `listTemplates`, `createTemplate`, `updateTemplate`, `deleteTemplate`, `templateVariables`.
   - ربط اختياري في `sendEmail/sendWhatsApp/sendSms` (تمرير template_id → render).
2. **G4 — Win/Loss + Goals**:
   - `CrmReportService::winLoss()` (فوز/خسارة حسب الفترة + أسباب الخسارة) + `CrmGoalService` (أهداف إيراد شهرية لكل مستخدم) مع `crm_sales_goals` جدول.
   - نقاط API: `winLossReport`, `salesGoals` (list/create/update).
3. **G2 — Custom Fields** (الأوسع أثرًا):
   - `crm_custom_fields` (meta schema) + `crm_entity_field_values` (JSON value لكل كيان).
   - Service `CrmCustomFieldService`: CRUD تعريف + get/set قيمة + دمج في استعلامات البحث.
   - يُنفَّذ بحذر: القيم تُقرأ/تُكتب كـJSON في سجل منفصل دون كسر الاستعلامات الأصلية.

**ملاحظة تنفيذ:** نقطة "الربط الاختياري في `sendEmail/sendWhatsApp/sendSms`" من G1
لم تُربط ببُعد — القوالب متاحة بالكامل كـ CRUD + render، لكن التكامل داخل دوال
الإرسال نفسها لم يُلمس (يجب تفعيله بعد اختبار الإرسال على قنوات حقيقية).

### الجولة المقترحة 2 (تمايز، أسهل)
4. **G3 — Product Catalog**: `crm_products` + `crm_deal_items`، قيمة الصفقة تُحسب = Σ (سعر × كمية).
5. **G5 — Lead Routing Rules**: `crm_lead_routing_rules` (شرط مصدر/دولة → owner round-robin).
6. **G6 — Contact Lifecycle**: عمود `lifecycle_stage` + قيم مخصصة + فلترة.
7. **G9 — Team Invite**: إعادة استخدام `WorkspaceInvite` الموجود.

### تنفيذ الجولة المقترحة 2 (المرحلة 13 — منفَّذة ✔)
- **G3 ✔**: `crm_products` + `crm_deal_items` (FK → crm_deals) مع `CrmProductService::recomputeDealValue()`
  التي تكتب Σ(line_total) في `crm_deals.value` — فقط عبر مسار الخدمة الجديد، بلا لمس `CrmController::createDeal` اليدوي.
- **G5 ✔**: `crm_lead_routing_rules` مع `CrmLeadRoutingService` — أول قاعدة نشطة مطابقة
  source/country/value تفوز؛ وضعان fixed وround_robin (توزيع على المالك + أعضاء الفريق مع `rotation_index`).
- **G6 ✔**: `ALTER crm_contacts ADD lifecycle_stage` (يتبع سابقة 000044/000051) + جدول
  `crm_lifecycle_stages` بخمس مراحل نظامية مبدوءة (lead/qualified/customer/inactive/churned).
- **G9 ✔**: `CrmTeamInviteService` يعيد استخدام `WorkspaceInvite` + `Mailer`؛ بريد مسجّل → إضافة مباشرة،
  غير مسجّل → دعوة + رابط `/crm/accept-invite?token=` يقبلها غير المسجّل بإنشاء حساب.
- **التسليم**: migrations `000009/000010/000011` + 4 Models + 4 Services + 24 دالة Controller
  + 24 مسارًا + ~95 مفتاح Lang ثنائي اللغة.

### تنفيذ الجولة المقترحة 3 (المرحلة 14 — منفَّذة ✔)
- **G7 ✔**: `CrmChartService` — بيانات جاهزة للرسوم (Chart.js) مباشرة من القاعدة:
  pipelineChart (توزيع الصفقات على المراحل)، revenueTrend (آخر N شهر)،
  winLossTrend (أعمدة Won/Lost)، leadSourceDistribution، dealStatusDistribution،
  lifecycleDistribution. 6 دوال Controller + 6 مسارات.
- **G8 ✔**: `CrmEmailTrackingService` — بكسل تتبع 1x1 يُضمّن في HTML الإيميل الصادر
  عبر `/api/crm/email-track/{token}.gif` (مسار عام بلا AuthMiddleware لأن عميل البريد
  بلا جلسة)؛ `recordOpen()` يسجّل أول/آخر فتح + العدد + IP + المتصفح. جدول
  `crm_email_trackings` + إحصاءات open_rate. Additive: `Mailer`/`CrmEmailService` لم يُلمسا.
- **G10 ✔**: `CrmActivityService` — أنواع أنشطة مخصصة (جدولان: `crm_activity_types`
  + `crm_activities`) مرتبطة بأي كيان (contact/lead/deal/company)؛ 6 أنواع نظامية مبدوءة
  (call/site_visit/follow_up/meeting/email/quote) + أنواع مخصصة لكل حساب.
  7 دوال Controller + 7 مسارات.
- **التسليم**: migrations `000012/000013` + 3 Models + 3 Services + 16 دالة Controller
  + 16 مسارًا + ~50 مفتاح Lang ثنائي اللغة.

### تنفيذ الجولة المقترحة 4 (المرحلة 15 — منفَّذة ✔)
- **G11 ✔**: `CrmWebFormService` — نماذج التقاط Leads (`crm_web_forms` + `crm_web_form_submissions`):
  حقول مخصصة (JSON) + slug عام + مسار إرسال بلا AuthMiddleware
  (`POST /api/crm/public/web-forms/{slug}/submit`) ينشئ Contact + Lead عبر `CrmLeadService`
  مع Honeypot بسيط + تسجيل IP/المتصفح + استدعاء `CrmLeadRoutingService` تلقائيًا.
- **G12 ✔**: `CrmSequenceService` — تسلسلات مبيعات (`crm_sequences` + `crm_sequence_enrollments`):
  خطوات مؤجلة بأنواع (task/note/email/whatsapp/notify) على Contact/Lead/Deal، مع
  تسجيل (enroll) + تنفيذ الخطوات المستحقة عند الطلب (processDue) + pause/resume/cancel.
  نفس قيد Automation: لا إرسال خارجي فعلي — email/whatsapp تُنشئ مهمة متابعة بنص مُصيَّر من القالب.
- **G13 ✔**: `CrmReportBuilderService` — تقارير مخصصة (`crm_saved_reports`): 5 كيانات
  (contacts/leads/deals/activities/tasks) + حقول/فلاتر/تجميع/ترتيب من قوائم بيضاء فقط
  (لا SQL حر) + تنفيذ فوري أو محفوظ. 8 دوال Controller + 8 مسارات.
- **G14 ✔**: `CrmExternalImportService` — استيراد من CRMs خارجية بلا لمس
  `CrmImportExportService` القائم: قوائم جاهزة لرؤوس أعمدة CSV الفعلية لكل نظام
  (HubSpot/Zoho/Pipedrive/Freshsales) مع معاينة (preview) قبل الاستيراد (commit)
  بنفس نمط المرحلتين.
- **التسليم**: migrations `000014/000015/000016` + 5 Models + 4 Services + 28 دالة Controller
  + 28 مسارًا + ~80 مفتاح Lang ثنائي اللغة.

---

## 5. الميزة التنافسية الطبيعية للموديول (لا يملكها المنافسون العامون)

- **مدمج داخل منصة Tourfecto**: Webhooks WhatsApp/SMS/Email فعّالة داخل نفس النظام،
  وإنشاء طلب مراجعة تلقائيًا عند ربح صفقة (`ReviewRequestService`).
- **ثنائي اللغة عربي أولًا** (RTL) — معظم المنافسين العالميين عربيتهم ضعيفة.
- **إدارة سمعة مرتبطة**: بيانات المراجعات/التقييمات تدخل في Dashboard و360.
- **بدون تبعيات خارجية**: يعمل في بيئة السيرفر الحالية بدون Composer.
- **AI يبدأ من بيانات حقيقية**: SQL → Gemini للصياغة فقط (fact-first)، بتكلفة Credits محكومة.
- **عزل تينانت صارم** عبر `resolveTenantId` مع تمييز الفاعل الحقيقي (`uid`).

---

## 6. منهجية التحليل

- مخزون الميزات: مسح كامل لـ Controllers/Services/Models/Jobs/Routes/Migrations/تستات
  في `/workspace`.
- بيانات المنافسين: الصفحات الرسمية (HubSpot CRM، Pipedrive Features، Zoho CRM،
  Freshsales Features) — جُلبت في هذه الجلسة (2026-08-15).
- كل ميزة منافِسة قُورنت 1:1 مع التنفيذ الفعلي في الكود (وليس مع الوعد التسويقي).
