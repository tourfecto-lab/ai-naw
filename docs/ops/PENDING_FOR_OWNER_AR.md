# ما ينتظرك (قرارات وخطوات على السيرفر)

آخر تحديث: 2026-10-28. كل ما في المستودع على `main` وCI أخضر (1151 اختبارًا بلا إخفاقات + PHPStan + PHPStan الموسَّع).
**لم يُنشر أي شيء على الموقع الحي.** النشر يدوي ويبقى قرارك.

## 1) قبل أي نشر
- **انتظر ردّ مراجعتَي Meta وGoogle.** لا تغيّر OAuth scopes ولا redirect URIs ولا مسارات ويب-هوك Meta/واتساب ولا شاشات الربط والنشر.
- تأكد أن `.env` على السيرفر فيه `ENCRYPTION_KEY` (أو `SALT`). بعد تعديلات المرحلة 0، غيابهما يوقف تحقق ويب-هوك واتساب وMeta بدل أن يشتقّ سرًّا ثابتًا.
- **حذف الملفات القديمة على السيرفر:** المستودع حذف نسخًا مكررة (`app/app/`، و`app/Integrations/IntegrationManager.php`،
  و`app/Services/Integrations/Ota*.php` المسطّحة، وغيرها). رفع الملفات المتغيّرة فقط لا يحذف القديم من السيرفر. إن كان `vendor/composer`
  على السيرفر مبنيًا قبل الحذف فقد يشير لمسارات محذوفة؛ أعد توليده (`composer dump-autoload`) بعد رفع الكود، أو أبقِ النسخ القديمة حتى تتأكد.
- **crontab:** حُذفت النسخة `app/cron/process_email_automations.php` (مطابقة حرفيًا لـ `cron/process_email_automations.php`). إن كان أمر cron على السيرفر يشير إلى `app/cron/...` فأبقِ هذا الملف على السيرفر أو غيّر المسار إلى `cron/`.
- جرّب بعد النشر من لوحة العميل: حفظ إعدادات SMTP webhook وSite Connect (نمط CSRF صار مثبَّتًا في بداية المسار).

## 1.5) ملفات التشغيل التي تغيّرت (للرفع عند النشر، بعد رد المراجعتين)
11 ملف PHP + هجرة اختيارية (التفاصيل في `git log`، والباقي اختبارات/توثيق/أدوات لا تُرفع):
`app/Controllers/{AdminEmailReputationController, AiVisibilityController, ChatController, MetaWebhookController, WhatsAppCloudController}.php` ·
`app/Security/RequestOriginGuard.php` · `app/Services/AI/{FollowUpAutomationService, OpenAIImageClient}.php` ·
`app/Services/Payment/CurrencyService.php` · `app/Services/Seo/SeoProxyService.php` · `app/Services/Subscription/TrialPlanService.php`
(الأخيران من `OpenAIImageClient` و`CurrencyService` تعديل توثيق فقط). الهجرة `2026_10_28_000001_activity_logs_unified_columns.sql` تُشغَّل يدويًا بقرارك.
**ملاحظة Meta/واتساب:** `MetaWebhookController` و`WhatsAppCloudController` و`ChatController` ضمن هذه الملفات (تعديل: فشل صريح عند غياب المفتاح،
و`/api/chat/webhook` القديم يرد 410). ارفعها **بعد** رد مراجعة Meta فقط.

## 2) أمان (عليك أنت)
1. **ملف جلسة مسرّب في تاريخ GitHub** (`public_html/storage/sessions/sess_56afu…`): غيّر كلمة مرور المستخدم صاحب الجلسة وأعد توليد `api_token`.
   تنظيف التاريخ بـ `git filter-repo` ثم `force push` **لم يُنفَّذ** لأنه يعيد كتابة التاريخ كله ويحتاج موافقتك الصريحة.
2. **nginx:** تحويل `http://` إلى HTTPS، وإزالة ترويسة HSTS المكرّرة (واحدة من nginx وواحدة من PHP). الكود في `docs/ops/PHASE0_SERVER_HARDENING_AR.md`.
3. ويب-هوك واتساب القديم `/api/chat/webhook` يرد `410` افتراضيًا. إن كان لديك تكامل مخصص يستخدمه فعلًا: `LEGACY_CHAT_WEBHOOK_ENABLED=1` مؤقتًا ثم انقله للمسار الموقّع.

## 3) قاعدة البيانات (لا تُنفَّذ إلا بقرارك)
- `database/migrations/2026_10_28_000001_activity_logs_unified_columns.sql`: إضافة أعمدة `module/action/meta/...` إن كان `activity_logs` بالشكل القديم.
  idempotent (`ADD COLUMN IF NOT EXISTS`، صيغة MariaDB). **خذ نسخة احتياطية أولًا.**
- فجوات سلسلة الهجرات (قاعدة جديدة لا تُبنى كالإنتاج): `docs/ops/MIGRATION_CHAIN_GAPS_AR.md`. أهمها `subscription_plans.websites_limit` غير موجود فتفشل هجرات التسعير.
- الهجرة `2026_07_14_000007b_merge_modules_unified_schema.sql` **تحذف القيمة `'user'` من `users.role`** رغم أن تعليقها يقول العكس. لا تشغّلها على قاعدة فيها مستخدمون بدور `user`.

## 4) قرارات تحتاج رأيك
| الموضوع | الوضع | الخيار |
|---|---|---|
| تسجيل الدخول بـ Apple | `appleCallback` موجودة فقط في `app/AuthController.php` القديم ولا مسار يستدعيها | إكماله أو حذفه نهائيًا |
| `Mailer` لا يصنّف نتيجة الفشل | أي فشل (حتى «SMTP غير مُعدّ») يُسجَّل `uncertain` ويحتاج مراجعة يدوية | تصنيف الفشل المؤكد (قبل `DATA`) كـ `definitive_failure` |
| `HomeController` | `app/Config/project_classmap.php` (المرجع وقت التشغيل على السيرفر) يربط `HomeController` بالملف **`HomeController (1).php`** (اسم ناتج عن تنزيل مكرّر في ويندوز)، بينما `HomeController.php` الأقدم غير مستخدم. حذفته بالخطأ ثم **استعدته** وأضفت اختبارًا يحرس أهداف الـ classmap | بعد التأكد على السيرفر: أعد تسمية ملف (1) إلى `HomeController.php` وحدّث الـ classmap (`php tools/build_project_classmap.php`) |
| `AdsController` (Meta Ads) | يستدعي `App\Core\Database` (كلاس غير موجود) في فرع واحد | يُصلح بعد رد مراجعة Meta |
| `WhatsAppCloudAPI` مكرر | نسختان (`Services/Chat` الأحدث، `Services/WhatsApp` الأقدم) | حذف الأقدم بعد رد مراجعة Meta |
| `ReviewAutoReplyRunner::sendReply` | يقرأ `$validator` قبل تعريفه (شيفرة ميتة، لا أثر) | تنظيفه عند ملامسة الفوترة |
| 14 ملف اختبار قديم بمشغّل خاص | لا ترث `TestCase` وعند تشغيلها فعليًا **تفشل كلها** (الكود تغيّر منذ كُتبت). السليمان (`SsrfGuardTest` و`CiPermissionsTest`) يعملان الآن عبر `tests/Integration/LegacyScriptSuiteTest.php` | إعادة كتابة الـ14 (حوالي 6000 سطر) أو حذفها (تبقى 32 تحذيرًا من PHPUnit حتى ذلك) |

## 5) مؤجّل عمدًا حتى تنتهي مراجعتا Meta وGoogle
منع تكرار ويب-هوك Meta (`MetaWebhookController::isNew` يسجّل قبل المعالجة فتضيع الرسالة إن فشلت)، و`MediaPreflight` (تثبيت IP وIPv6)،
وتجديد توكنات Meta/YouTube، وحارس أسعار الذكاء الاصطناعي (التحقق من الأرقام في الرد)، وتنبيه الأحداث الفاشلة في صندوق واتساب،
وأي تغيير في الواجهة (نظام ألوان موحد، الموبايل، إمكانية الوصول) لأنها تمس الشاشات التي سجّلتها.

## 6) أعطال وُجدت وأُصلحت (للعلم، تحتاج نشرًا ليعمل الإصلاح)
- `AdminEmailReputationController::input()` غير معرّفة، فمسارات الأدمن الخمسة (إيقاف/استئناف الحساب، الحدود، مراجعة الاعتماد، إيقاف الحملة) كانت تنهار.
- `POST /api/ai-visibility/llms` (مسودة `llms.txt`) كان يستدعي `SeoMarketService::owned()` غير الموجودة فيرد 422 دائمًا؛ صار يستدعي `site()`.
- `TrialPlanService::adminProgress()` كانت ناقصة (استثناؤها يُبلع)، فعمود «التقدّم» في قائمة تجارب الأدمن كان فارغًا دائمًا؛ نُفّذت بنفس شكل البيانات الذي تتوقعه `admin-insights.js`.

## 7) بيئة tourfectoai.pro (نشر تلقائي)
مربوطة بـ GitHub عبر aaPanel (Deploy key للقراءة فقط + webhook على حدث push، فرع main، سكربت `git pull origin main`). كل push على `main` ينزل هناك تلقائيًا. الموقع الأساسي tourfecto.pro يدوي ومنفصل.

## 8) تطويرات الواتساب (على tourfectoai.pro فقط، لم تُرفع إلى tourfecto.pro)
- ملفات تلمس مسار Meta/واتساب (لا تُرفع قبل رد المراجعة): `WhatsAppCloudAPI` (رد/تفاعل/أزرار)، `ChatManager`، `ChatInboxController`، `WhatsAppCloudController` (تفاعلات العميل وسياق الرد)، `ChatController`.
- **لم تُجرَّب إرسالًا حقيقيًا:** الرد على رسالة، التفاعل، الأزرار/القوائم، الرسائل الصوتية. جرّبها بنفسك على رقم اختبار قبل النشر.
- هجرة **اختيارية** `2026_10_29_000001_chat_conversation_prefs.sql` (تثبيت/أرشفة/غير مقروء لكل موظف على الخادم). من غيرها الواجهة تحفظها في المتصفح ولا يحدث أي خطأ. خذ نسخة احتياطية قبل تشغيلها.
- الإدخال الجديد في `app/Config/project_classmap.php`: `ChatConversationPrefs` (سطر واحد).
