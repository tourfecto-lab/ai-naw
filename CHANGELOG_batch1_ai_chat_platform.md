# Tourfecto — AI Chat & Customer Communication Platform
## تسليم نهائي موحّد (يجمع المراحل 1-6) — 2026-08-09

هذا هو **الملف الوحيد المطلوب رفعه**. يحتوي على كل الملفات الجديدة
والمعدَّلة من كل المراحل الست، بحالتها **النهائية** فقط (مفيش تكرار أو
نسخ وسيطة) - بديل عن الـ6 ZIPs المنفصلة اللي اتسلّمت أثناء العمل.

**النطاق**: AI Chat & Customer Communication Platform فقط - لا تعديل على
أي Module آخر (SEO/CRM/Ads/Analytics مستقلة) طبقًا لتعليمات الطلب الأصلي.

---

## 0) ملخص تنفيذي

من أصل 35 بندًا في الطلب الأصلي، **كل بنود الـBackend/API منفَّذة**. البند
الوحيد المستبعَد عمدًا هو **29 (UI/UX)** لأن عندكم Frontend منفصل بيستهلك
نفس هذا الـAPI. الجدول الكامل لحالة كل بند في القسم 8 آخر الملف.

**فجوات معروفة ومُقِرّ بيها صراحة** (لسه موجودة، مش اتحلّت في هذا التسليم):
1. **لا يوجد اختبار Runtime حقيقي** - كل الاختبار كان `php -l` + فحص
   تصادم كلاسات + مراجعة منطقية يدوية. لا قاعدة بيانات ولا مزود AI ولا
   حساب Meta حقيقي كانوا متاحين للاختبار الفعلي.
2. مؤشرات Analytics معيّنة تقريبية موثَّقة بوضوح (قسم 5 تحت).
3. عدّاد Follow-up مبسَّط (إجمالي مش لكل دورة صمت - قسم 4 تحت).
4. Payload شكل Messenger/Instagram/Email مبني على أفضل فهم من التوثيق
   العام، غير مُختبَر ضد حركة حقيقية.
5. `app/Chat/*` كود ميت قديم غير محمَّل - لُوحِظ ولم يُحذَف (قرار لكم).

---

## 1) الملفات الجديدة (36 ملفًا)

### قاعدة البيانات
- `database/migrations/2026_08_08_000001_create_ai_chat_platform_tables.sql`
  — 9 جداول جديدة بالكامل + عمود واحد إضافي على `chat_messages`.

### AI Provider Abstraction (بند 20)
- `app/Services/AI/Providers/AIProviderInterface.php`
- `app/Services/AI/Providers/GeminiProvider.php`
- `app/Services/AI/Providers/OpenAICompatibleProvider.php`
- `app/Services/AI/Providers/OpenAIProvider.php`
- `app/Services/AI/Providers/DeepSeekProvider.php`
- `app/Services/AI/Providers/KimiProvider.php`
- `app/Services/AI/Providers/AIProviderManager.php`
- `app/Config/openai.php`, `app/Config/deepseek.php`, `app/Config/kimi.php`
- `app/Models/AiUsageLog.php`

### Knowledge Base (بند 4، 13)
- `app/Models/AiKnowledgeBase.php`
- `app/Services/AI/KnowledgeBaseService.php`
- `app/Controllers/AiKnowledgeBaseController.php`

### Unified Inbox + AI Conversation Engine (بند 1، 2، 3، 8، 9، 10، 11)
- `app/Models/AiChatConversation.php`
- `app/Models/AiCustomerMemory.php`
- `app/Services/Chat/UnifiedInboxService.php`
- `app/Services/AI/AIConversationEngine.php`
- `app/Controllers/ChatInboxController.php`

### AI Sales Agent + Follow-up Automation (بند 5، 6، 7)
- `app/Models/AiLead.php`
- `app/Models/AiFollowup.php`
- `app/Models/AiFollowupRule.php`
- `app/Models/AiCustomTag.php`
- `app/Services/AI/LeadScoringService.php`
- `app/Services/AI/FollowUpAutomationService.php`
- `app/Controllers/AiLeadController.php`
- `app/Controllers/AiFollowupSettingsController.php`
- `cron/process_ai_followups.php`

### Analytics + Reply Suggestions (بند 12، 18)
- `app/Services/AI/AiAnalyticsService.php`
- `app/Services/AI/AiReplySuggestionsService.php`
- `app/Controllers/AiAnalyticsController.php`

### قنوات إضافية (بند 1، 23)
- `app/Services/Chat/MessengerAPI.php`
- `app/Services/Chat/InstagramAPI.php`
- `app/Services/Chat/EmailChannelAPI.php`

---

## 2) الملفات المعدَّلة (10 ملفات)

| الملف | التعديل |
|---|---|
| `public_html/index.php` | تحميل config الـProviders الجدد + دعم استجابة `_raw_text` لـMeta verification |
| `public_html/system_check.php` | إضافة config الـProviders الجدد لقائمة الفحص |
| `cron/bootstrap.php` | تحميل config الـProviders الجدد |
| `tests/bootstrap.php` | تحميل config الـProviders الجدد |
| `.env.example` | متغيرات Providers/Rate Limiting الجديدة |
| `app/routes/api.php` | كل مسارات AI Chat الجديدة (~30 مسار) |
| `app/Config/constants.php` | تفعيل Messenger + إضافة Instagram/Email في `CHAT_PLATFORMS`، وثوابت Rate Limiting |
| `app/Models/ChatMessage.php` | عمود `conversation_id` في `$fillable` |
| `app/Services/Chat/ChatManager.php` | **الأهم** - انظر قسم 3 |
| `app/Services/Chat/AutoReplyEngine.php` | معاملان اختياريان جدد، متوافق 100% مع الاستدعاء القديم |
| `app/Controllers/ChatController.php` | 12 دالة جديدة لقنوات Messenger/Instagram/Email |

---

## 3) أهم تصحيح في المشروع كله (اقرأه قبل الرفع)

`ChatManager::processIncomingMessage()` خطوة "إرسال الرد التلقائي" كانت
تستخدم دالة قديمة (`sendMessage()`) تعتمد على **مثيل WhatsApp واحد ثابت
للمنصة كلها** - رغم وجود `sendMessageForWebsite()` (Multi-tenant حقيقي
لكل موقع) في الكود الأصلي أصلًا **مع تعليق من المطور نفسه يوضّح إنها
البديل الصحيح**. يبدو أنها بُنيت ولم تُربَط بالتدفق الفعلي - خلل سابق
لتدخلنا.

**تم تصحيحه** ليستخدم `sendMessageForWebsite()` الآن متعددة القنوات
(WhatsApp/Messenger/Instagram/Email). هذا **ضروري** لعمل القنوات
الجديدة، ويُصحح أيضًا سلوك إرسال WhatsApp نفسه ليستخدم اتصال كل موقع
الصحيح بدل مثيل مشترك.

⚠️ **اختبروا إرسال WhatsApp Auto Pilot أولًا وقبل أي حاجة تانية** بعد
الرفع، وتأكدوا إن كل موقع نشط له اتصال UltraMsg متصل في
`platform_connections` (نفس الشرط المطلوب مسبقًا لعمل
`sendMessageForWebsite()` أصلًا). للتراجع السريع لو احتجتوه: في
`ChatManager.php`، رجّعوا استدعاء الخطوة 10 لـ`sendMessage()` بدل
`sendMessageForWebsite()`.

---

## 4) قرارات تصميم مهمة يجب مراجعتها

- **عدّاد Follow-up إجمالي وليس لكل دورة صمت**: `followup_number` يُحسَب
  من إجمالي المتابعات المُرسَلة تاريخيًا للمحادثة، مش بيتصفّر مع كل مرة
  العميل يسكت من جديد. قرار متعمَّد يمنع إغراق العميل بمتابعات لا
  نهائية عبر دورات صمت متكررة.
- **رسائل المتابعة Templates ثابتة، مش AI-generated** - تفاديًا لأي رد
  "مُختلَق" تلقائي بدون مراجعة بشرية.
- **AI Conversation Engine يطلب من الموديل رد بصيغة JSON منظّمة** (رد +
  ثقة + حاجة لتحويل + ملخص + وسوم + ذاكرة) بدل نص حر - لو الموديل ما
  التزمش بالصيغة، فيه مسار احتياطي (`parseDecision`) يتعامل مع النص
  الخام بثقة متوسطة بدل رفض الرد بالكامل.
- **قاعدة المعرفة الفارغة**: لو صاحب الشركة لسه ما أضافش أي محتوى
  Knowledge Base، الـAI يُعلَّم صراحة إنه "ميعرفش" ويوجَّه لطلب تحويل
  لموظف بدل اختراع معلومات - هذا سلوك مقصود ومطلوب من الطلب الأصلي.

---

## 5) ملاحظات صدق حول Analytics (بند 18)

بعض المؤشرات المطلوبة (Most Asked Questions، Top Customer Intent) تحتاج
تحليل نصي عميق (NLP) غير متاح باستعلام SQL تجميعي بسيط. بدل تقديم رقم
غير دقيق، استُخدِمت بدائل موثوقة وواضحة:
- **Top Customer Intent** → أكثر الوسوم (Tags) التلقائية تكرارًا.
- **Most Asked Questions** → لم تُنفَّذ كمؤشر منفصل (تحتاج تتبع أي عناصر
  Knowledge Base استُخدِمت فعليًا في كل رد - غير متوفر حاليًا).
- **متوسط زمن الرد** → تقدير عملي من الفارق بين كل رسالة واردة وأول رد
  صادر بعدها في نفس المحادثة.
- **Follow-up Success** → "هل العميل رد بعد المتابعة؟" وليس "هل تحوّل
  لحجز فعلي؟" (الأخير يحتاج ربط بنظام حجوزات خارج نطاق AI Chat).

---

## 6) تغييرات قاعدة البيانات

ملف واحد فقط، يُشغَّل **مرة واحدة**:
```
mysql -u USER -p DATABASE < database/migrations/2026_08_08_000001_create_ai_chat_platform_tables.sql
```
9 جداول جديدة بالكامل (`ai_knowledge_base`, `ai_conversations`,
`ai_customer_memory`, `ai_leads`, `ai_followup_rules`, `ai_followups`,
`ai_custom_tags`, `ai_usage_logs`, `ai_webhook_events`) + عمود واحد
إضافي (`conversation_id`) على `chat_messages` الموجود. **لا حذف أو
تعديل كاسر لأي بيانات موجودة.**

---

## 7) خطوات التركيب الكاملة (بالترتيب)

1. **نسخة احتياطية كاملة من قاعدة البيانات** قبل أي خطوة.
2. ارفعوا كل ملفات هذا الـZIP بنفس المسارات بالضبط (استبدال كامل للملفات
   المعدَّلة المذكورة في قسم 2).
3. شغّلوا الـmigration (قسم 6).
4. أضيفوا في `.env` الحقيقي:
   ```
   AI_PROVIDER_PRIORITY=gemini,openai,deepseek,kimi
   OPENAI_API_KEY=          (اختياري)
   DEEPSEEK_API_KEY=        (اختياري)
   KIMI_API_KEY=            (اختياري)
   AI_CHAT_RATE_LIMIT_MAX=20
   AI_CHAT_RATE_LIMIT_WINDOW_SECONDS=60
   ```
   (`GEMINI_API_KEY` الموجود بالفعل كافٍ لتشغيل الـAI فورًا بدون أي
   إعداد إضافي - باقي المزودين اختياريون لخاصية الـFallback).
5. **اختبروا WhatsApp Auto Pilot أولًا** (أهم خطوة - راجعوا قسم 3).
6. أضيفوا محتوى تجريبي حقيقي في Knowledge Base:
   `POST /api/ai-chat/websites/{id}/knowledge-base`
7. ابدأوا محادثة WhatsApp تجريبية، وتأكدوا إن الرد مبني فعليًا على
   المحتوى المُضاف.
8. راجعوا Unified Inbox: `GET /api/ai-chat/websites/{id}/conversations`
9. جرّبوا Human Handoff يدويًا، وتأكدوا إن الردود الآلية بتتوقف فعلاً.
10. لربط Messenger/Instagram: `POST /api/chat/connect/messenger`
    (أو `instagram`) بـ`website_id` و`access_token`، ثم استخدموا
    `webhook_url`/`verify_token` المرجَعين لتسجيل الـWebhook في Meta for
    Developers.
11. لتفعيل Follow-up Automation: `PUT /api/ai-chat/websites/{id}/followup-settings`
    ثم أضيفوا `cron/process_ai_followups.php` في cPanel Cron Jobs (كل 30
    دقيقة مقترح).
12. راجعوا Analytics: `GET /api/ai-chat/websites/{id}/analytics`

---

## 8) حالة كل بند من الطلب الأصلي (35 بندًا)

| # | البند | الحالة |
|---|---|---|
| 1 | Unified Inbox (WhatsApp+Messenger+Instagram+Email+Website Chat) | ✅ |
| 2 | AI Conversation Engine | ✅ |
| 3 | AI Memory | ✅ |
| 4 | Company Knowledge Base | ✅ |
| 5 | AI Sales Agent | ✅ |
| 6 | Lead Qualification | ✅ |
| 7 | Follow-up Automation | ✅ (عدّاد إجمالي - قسم 4) |
| 8 | Human Handoff | ✅ |
| 9 | AI Confidence | ✅ |
| 10 | Smart Conversation Summary | ✅ |
| 11 | Smart Tagging | ✅ |
| 12 | AI Reply Suggestions | ✅ |
| 13 | Tone & Brand Voice | ✅ |
| 14 | Multi-language | ✅ Architecture + رصد تلقائي |
| 15-16 | Search & Filters | ✅ |
| 17 | Notifications | ✅ (7 أحداث) |
| 18 | AI Analytics | ✅ (مؤشرات تقريبية موثَّقة - قسم 5) |
| 19 | Admin Dashboard | ✅ API فقط (Frontend منفصل عندكم) |
| 20 | AI Provider Architecture | ✅ |
| 21 | AI Cost/Usage | ✅ |
| 22 | Rate Limiting | ✅ قابل للتعديل عبر .env |
| 23 | Webhook Architecture + Idempotency | ✅ |
| 24 | Error Handling | ✅ مدمج في كل خدمة |
| 25-26 | Security & Multi-tenant | ✅ |
| 27-28 | Database & Performance | ✅ |
| 29 | UI/UX | ⏭️ خارج النطاق (Frontend منفصل حسب اتفاقنا) |
| 30 | لا كسر للمشروع الموجود | ✅ (كل تعديل موثَّق ومبرَّر - انظر قسم 3 لأهم استثناء يستحق اختبار) |
| 31 | تنفيذ فعلي + اختبار | ⚠️ لينت وفحص منطقي فقط - لا Runtime حقيقي (قسم 0) |
| 32 | تسليم ZIP واحد نهائي بالمسارات الأصلية | ✅ هذا الملف |
| 33 | CHANGELOG | ✅ هذا الملف |
| 34 | عزل النطاق (AI Chat فقط) | ✅ لا لمسة لأي Module آخر |
| 35 | الهدف النهائي الشامل | ✅ محقَّق على مستوى Backend/API |

---

## 9) إضافة: الدمج النهائي داخل المشروع (2026-08-15)

بعد مراجعة الريبو على GitHub، اتضح إن ملفات الموديول كانت موجودة لكن
**مش متكاملة فعليًا**: مسارات `/api/ai-chat/*` كانت مسجّلة في ملف
`app/routes/api_ADDITIONS.php` اللي **مفيش أي كود بيعمله `require`**،
والكلاسات مش محمّلة في الـ bootstrap اليدوي. الاتنين كانوا هيوقعوا
"Route not found" / "Class not found" بمجرد تشغيل الموديول. تم دمج كل ده
بدون أي كسر للموجود:

- `app/routes/api.php` — أُضيفت **25 مسارًا** للموديول في نهاية الملف:
  - Unified Inbox: `conversations` CRUD + `reply` + `handoff` +
    `resume-ai` + `reply-suggestions`
  - Knowledge Base: `index` + `store` + `preview` + `update` + `destroy`
  - Leads: `index` + `show` + `update`
  - Follow-up Automation: `show` + `update`
  - AI Analytics: `index`
  - ربط القنوات: `POST /api/chat/connect/messenger` + `instagram`
  - Webhooks (من غير AuthMiddleware): `messenger` + `instagram` (verify GET
    + delivery POST) + `email`
  - **لم تُمسَّ أي مسارات قديمة** — أُضيف بلوك واحد نظيف في النهاية.
- `public_html/index.php` — أُضيفت **25 كلاسًا** لـ `$optionalNewClassFiles`
  (نفس نمط `file_exists` الآمن الموجود): Providers بالترتيب الصحيح
  (Interface ← OpenAICompatibleProvider ← المزودين ← AIProviderManager)،
  Services، Models، و5 Controllers.
- `cron/bootstrap.php` — أُضيفت كلاسات الموديول المطلوبة لـ
  `process_ai_followups.php` (ChatManager/UnifiedInboxService/Providers/
  AiFollowup/... ) في `$optionalJobDependencyFiles`.
- `.env.example` — أُضيفت `AI_PROVIDER_PRIORITY` + `OPENAI_API_KEY` +
  `DEEPSEEK_API_KEY` + `KIMI_API_KEY` + `AI_CHAT_RATE_LIMIT_*` (فاضية،
  الوضع الحالي مش بيكسر: `GEMINI_API_KEY` كافٍ).
- `tests/route_registration_test.php` — اختبار تشغيل ذاتي يتأكد إن كل
  مسارات الموديول مسجّلة وبيماتشوا عناوين فعلية (25/25 نجح).
- ملاحظة: `app/Chat/*` و `app/routes/Models/*` نسخ قديمة/مكررة **غير
  محمّلة في أي مكان** — اتحقّق إن الموديول بيستخدم نسخ `app/Services/Chat/`
  و`app/Models/` فقط، فمفيش أي تعارض.

**التحقق**:
```
php -l app/routes/api.php public_html/index.php cron/bootstrap.php
php tests/route_registration_test.php   # 25 passed, 0 failed
# + فحص تحميل الكلاسات بالترتيب (كل الموديول يتحمّل بدون Class not found)
```

## 10) إضافة: تحليل المنافسين + تحسين Observability (2026-08-15)

تحليل استراتيجي لمنافسي AI Chat العالميين (Intercom Fin، Zendesk AI
Agents، Tidio/Lyro، Chatwoot، Gorgias، Wati/ManyChat) — النتيجة في:
`docs/COMPETITIVE_ANALYSIS_AI_CHAT.md`. أبرز المواضع المستلهمة:

- حلقة تعلّم مستمرة + مراقبة "What's working" عند Gorgias/Zendesk.
- RAG مرتبط بـ Knowledge Base (مش مجرد LLM عام) — موجود عندنا بالفعل.
- Copilot للموظف (Reply Suggestions) مش بديل له.

**التحسين المنفَّذ (Observability)**:
- `AIProviderManager::health(?int $websiteId)` — دالة جديدة بتُرجع:
  - المزودين المهيّئين + الموديل لكل مزود + موقعه في ترتيب الأفضلية.
  - ملخص آخر 24 ساعة من `ai_usage_logs` (نجاح/فشل/fallback/توكنز/تكلفة)
    لكل مزود وإجمالًا، مع `status` (healthy/degraded/no_data).
  - **لا تُرجع أي API Key إطلاقًا** (قراءة صريحة موثقة).
- `AiAnalyticsController::index` — يستدعي `health()` ويُعيد الحقل الجديد
  `provider_health` بجانب `dashboard` (متوافق، لم يُحذف شيء).
- فشل قراءة الملخص يُسجَّل فقط ولا يكسر الاستجابة (نفس نمط
  `logUsage` الآمن من الفشل).

## 11) إضافة: Learning Loop + Re-ranking + لغات دولية (2026-08-16)

**أ. Learning Loop (Resolution Learning Loop — Zendesk/Intercom Fin)**
- Migration جديدة `2026_08_16_000001_create_ai_learning_loop_tables.sql`:
  - `ai_resolution_events`: نتيجة كل محادثة عند حلها
    (ai_resolved / human_resolved / abandoned / reopened) + القناة + اللغة
    + سبب التحويل + آخر ثقة للـAI.
  - `ai_knowledge_gaps`: فجوات المعرفة (أسئلة لم يستطع الـAI الإجابة
    عنها فتحوّل لموظف) — مجمّعة بالسؤال بعد التسوية النصية، مع
    occurrence_count للأسئلة المتكررة، وحالة (new/acknowledged/added_to_kb/
    dismissed). نفس المحادثة تُسجَّل مرة واحدة فقط (UNIQUE).
- `LearningLoopService` (جديد):
  - `recordResolution()` + `recordResolutionForClosedConversation()` —
    تسجيل النتيجة عند إغلاق المحادثة (ChatInboxController::update).
  - `recordKnowledgeGap()` — تسجيل/تجميع فجوة معرفة مع منع التكرار.
  - `scanKnowledgeGaps()` — مسح المحادثات المحوّلة لأسباب معرفية واستخراج
    آخر رسالة عميل قبل التحويل كفجوة (Flywheel).
  - `getLearningInsights()` — معدلات الحل + أسباب التحويل + أهم الفجوات.
  - `updateGapStatus()` — إدارة الحالة مع عزل بيانات الموقع.
  - كل دواله محايدة اللغة (agnostic) وتعمل على أي لغة، وآمنة من الفشل.
- `AIConversationEngine` — عند التحويل لموظف لسبب معرفي
  (outside_knowledge_base/low_ai_confidence/ai_requested_handoff) يسجّل
  سؤال العميل كفجوة تلقائيًا.
- `AiLearningController` (جديد) + 3 مسارات:
  - `GET /api/ai-chat/websites/{id}/learning/gaps`
  - `POST /api/ai-chat/websites/{id}/learning/gaps/{gapId}/status`
  - `POST /api/ai-chat/websites/{id}/learning/gaps/scan`
- `AiAnalyticsController` — يُعيد `learning_loop` (resolution_events +
  ai_resolution_rate_percent + escalation_reasons + knowledge_gaps).

**ب. Re-ranking لقاعدة المعرفة (RAG — Intercom Fin Reranker)**
- `KnowledgeBaseService::rerankForQuery()` — ترتيب عناصر قاعدة المعرفة
  حسب صلة كل عنصر برسالة العميل: تطابق كلمات (عنوان أعلى وزنًا من محتوى)
  + وزن قسم (FAQ/Pricing/Tour أعلى) + أولوية. خوارزمية محايدة اللغة
  (عربي/إنجليزي/أي لغة) بدون أي خدمة خارجية.
- `buildContextForPrompt()` — معاملات جديدة اختيارية:
  `$customerMessage` + `$maxEntries`. عند توفّرهما تُستخدم إعادة الترتيب
  ويُحقن الأعلى صلة فقط (أدق + أوفر توكنز). **متوافق رجعيًا**: الاستدعاءات
  القديمة بس معاملين تعمل كما كانت تمامًا.
- `AIConversationEngine` — يمرر رسالة العميل مع حد 12 عنصرًا.

**ج. لغات دولية (المنصة دولية وليست عربية فقط)**
- القيم الافتراضية للغة غُيّرت من `'ar'` إلى `'en'` في:
  `KnowledgeBaseService::addEntry`، `AiKnowledgeBaseController::store`،
  `AIConversationEngine` (fallback)، `AiReplySuggestionsService`.
- اللغة ما زالت تُكتشف تلقائيًا من نص العميل (`detectLanguage`)؛ الافتراضي
  الجديد يُستخدم فقط عند غياب أي دليل لغة.

**التحقق**:
```
php -l app/Services/AI/LearningLoopService.php app/Services/AI/KnowledgeBaseService.php \
      app/Services/AI/AIConversationEngine.php app/Controllers/AiLearningController.php \
      app/routes/api.php public_html/index.php cron/bootstrap.php
php tests/route_registration_test.php   # 28 passed, 0 failed
# + فحص تحميل الكلاسات (27 كلاسًا بدون redeclare) + اختبار منطق الـRerank
#   (عربي/إنجليزي) + اختبار LearningLoop بقاعدة بيانات وهمية (فصل/فجوات/عزل)
```


---

## 12. واجهة المستخدم الاحترافية (2026-08-16) — Frontend طبقًا لتحليل المنافسين

استجابةً لتحليل المنافسين (`docs/COMPETITIVE_ANALYSIS_AI_CHAT.md`)، اتحوّلت
واجهة الموديول من "جداول بسيطة" إلى تجربة بمستوى Intercom/Zendesk — بدون أي
Endpoint جديد، كلها استهلاك للـ APIs الموجودة.

**أ. صندوق الوارد الموحّد Split-View (`/chat`)**
- تخطيط عمودين: قائمة محادثات (يمين) + نافذة محادثة تفاعلية (يسار) تُفتح
  فورًا عند النقر — بدون إعادة تحميل الصفحة.
- القائمة: صورة رمزية للعميل، اسم، قناة، حالة، غير مقروء، آخر نشاط.
- نافذة المحادثة: فقاعات (Bubbles) للوارد/الصادر، شارة "رد تلقائي AI"
  بنسبة الثقة، ملخص AI، تحويل AI↔موظف، تعيين، حالة/أولوية، وسوم، Lead panel،
  اقتراحات رد AI، ورد يدوي.
- تحديث تلقائي كل 20 ثانية للقائمة وكل 30 ثانية للمحادثة المفتوحة.
- `/chat/conversation/{id}` بقت رابطًا عميقًا (deep link) لنفس المحادثة.

**ب. التحليلات (`/chat/analytics`)**
- بطاقات KPI + مخطط Donut لتوزيع AI/موظف (Chart.js).
- **صحة مزودي الـAI**: كل مزود مهيّأ بنموذجه وترتيب الأفضلية وحالته
  (سليم/متدهور/لا بيانات) + ملخص آخر 24 ساعة (طلبات/فشل/Fallback/Tokens/تكلفة).
- **حلقة التعلّم**: معدّل حل الـAI + توزيع النتائج (حلّها AI/موظف/هجر/أعيد فتحه)
  + أسباب التحويل + أبرز فجوات المعرفة.
- جدول استخدام المزودين بنموذج وطلبات ناجحة/فاشلة وتكلفة.

**ج. صفحة التعلّم الجديدة (`/chat/learning`)**
- بطاقات KPI (معدّل حل الـAI + عدد الفجوات غير المعالجة).
- شرح مبسّط لكيفية عمل Flywheel.
- جدول فجوات المعرفة: السؤال، اللغة، سبب التحويل، عدد مرات التكرار، الحالة،
  آخر ظهور، وأزرار: ملاحظة / **أضِف للمعرفة** (تنشئ عنصر FAQ بقيمة الأولوية
  العالية وتغلق الفجوة تلقائيًا) / تجاهل / إعادة فتح.
- زر "إعادة مسح الفجوات" (يستدعي `POST /learning/gaps/scan`).

**د. قاعدة المعرفة (`/chat/knowledge-base`)**
- حقل **الأولوية** في نموذج الإضافة (يقود خوارزمية Re-ranking من المرحلة 11).
- زر **تعديل** لكل عنصر (محتوى + أولوية) عبر الـ PUT الموجود.
- الافتراضي للغة صار English (منصة دولية).

**هـ. القائمة الجانبية**
- مجموعة مستقلة "منصة الشات الذكي" بدل العنصر الأحادي القديم، فيها:
  صندوق الوارد / التحليلات / قاعدة المعرفة / التعلّم والفجوات / Leads /
  المتابعة التلقائية. كل صفحة بتستخدم مفتاح activeTab الخاص بها.

**التحقق**:
```
php -l app/Controllers/ChatController.php app/Core/Controller.php app/Lang/en.php app/Lang/ar.php
php tests/route_registration_test.php   # 28 passed, 0 failed
# + هارنس تحميل الكلاسات (Sidebar) يؤكد مجموعة "منصة الشات الذكي"
#   والـ active state على صندوق الوارد + تحميل كل الـ methods المطلوبة
```
