# العقل الاستراتيجي — التقدّم وخطة الاستكمال

اقرا ده الأول في أي جلسة جديدة. المراجعة الكاملة للمنصة في `docs/brain/AUDIT_AR.md`.

## البيئات (اتأكدت منها من `docs/ops/PENDING_FOR_OWNER_AR.md` §7)
- **التطوير:** `tourfectoai.pro`. أي push على `main` بينزل هناك لوحده (aaPanel + `git pull origin main`).
- **الموقع الأساسي:** `tourfecto.pro`. الرفع عليه يدوي ومنفصل، وماحدش يلمسه من هنا.
- **مش متأكدين:** إذا كانت قاعدة بيانات التطوير منفصلة عن الأساسي ولا لأ (`.env` على السيرفر مش في الريبو).
  `docs/ops/META_MESSAGING_REVIEW_PACKAGE_AR.md` بيقول إن `.env` بتاع tourfectoai.pro فيه `APP_URL=https://tourfecto.pro`.
  لحد ما صاحب المشروع يأكد العزل، أي تغيير في قاعدة البيانات لازم يفضل **إضافة بس** (جداول جديدة، من غير ALTER على جداول موجودة).

## طريقة الشغل
- فرع لكل مرحلة ← PR ← CI لازم ينجح (syntax، PHPStan، PHPUnit) ← دمج squash في `main` ← التأكد على tourfectoai.pro.
- الصرف (DataForSEO، توليد ذكاء اصطناعي مدفوع، إعلانات) ماينفعش يتشغّل من العقل لوحده. العقل بيقترح، وصاحب الشركة يوافق في الخدمة نفسها.
- عزل الشركات: أي استعلام في `app/Services/Brain` لازم يكون فيه `user_id` (و`website_id` لما يكون فيه موقع)، والموقع بيتأكد إنه ملك المستخدم.

## المراحل
| المرحلة | المحتوى | الحالة |
|---|---|---|
| B1 | عقل لكل شركة: الفهم (`CompanyBrainService`)، والقرارات بأدلة (`StrategicDecisions`)، والحفظ والقياس قبل وبعد والتعلّم على مستوى الشركة (`BrainDecisionStore`)، وصفحة `/brain` | **اتعمل** (‎#9) |
| B2 | التنفيذ (`BrainExecutor` فوق `ActionExecutor` الموجود): أي قرار يتوافق عليه بيتحول لمهمة في CRM بتاريخ حسب أثره. القرار عالي الأثر بيبعت إشعار. التنفيذ بيتسجل في `action_executions`، ونفس القرار مابيتكررش خلال 30 يوم. الفرص والمخاطر بتتكتب في `ceo_growth_opportunities` و`ceo_risk_alerts` و`cc_ai_tasks`، اللي اللوحة التنفيذية بتعرضها. التشغيل الذاتي (`brain_settings.autonomy`) مقفول افتراضيًا، ولما يتفتح بيوافق وينفّذ لوحده القرارات اللي مافيهاش صرف. فيه cron اختياري `cron/brain_cycle.php` | **اتعمل** |
| B2+ | التنفيذ جوه الخدمات نفسها (تشغيل طيار السيو، وإضافة بوستات لخطة السوشيال، وتوصيات الإعلانات). كل واحد فيهم بيصرف حصة أو فلوس، فبيتعمل بزرار موافقة مخصوص | جاية |
| B3 | ذكاء مشترك بين الشركات (`SharedIntelligence`): اختياري ومقفول افتراضيًا (`brain_sharing`). نتايج القرارات بتتجمّع حسب نوع القرار من غير رقم الرحلة، وأي نسبة مابتظهرش غير لو جاية من 5 شركات أو أكتر. اللي مش مشارك مابيشوفش النسب. الوزن بين 0.8 و1.2 | **اتعمل** |
| B4 | ذكاء السوق (`MarketIntelligenceService`). المصادر مجانية ومن غير مفاتيح: GOV.UK (تحذيرات السفر)، وECB (الدولار والإسترليني قدام اليورو)، وEurostat (ليالي الأجانب في الوجهات الأوروبية بس)، وNager.Date (الإجازات)، وWorld Bank (السياح الداخلين ونمو اقتصاد الأسواق). كل رقم في `market_signals` بمصدره وتاريخه. السجل `market_fetch_log` لكل مصدر ونطاق. فيه تحديث مصدر واحد في كل زيارة، و`cron/market_intelligence.php` اختياري | **اتعمل** |
| B4+ | Google Trends عبر DataForSEO: مدفوع، ومحتاج ميزانية DataForSEO تترفع الأول | جاية |
| B5 | توصيات النمو: باقات جديدة من الرحلات اللي طلبها بيزيد، وأسواق جديدة من الأسواق اللي بيظهر فيها طلب، وتسعير من أسعار المنافسين المتابَعين (`competitors`) والطلب الحالي | جاية |
| B6 | لوحة أدمن: مزوّدي الذكاء الاصطناعي (`AIOrchestrator`، دلوقتي من `.env`)، والتكلفة والحدود (`AIGovernor` و`api_usage_logs`)، وحدود تنفيذ العقل لكل باقة | جاية |

## B1: الملفات
- `app/Services/Brain/CompanyBrainService.php`: بيجمع صورة الشركة من الخدمات الموجودة. أي مصدر مش متاح بيرجع `null`.
- `app/Services/Brain/StrategicDecisions.php`: منطق نقي من غير قاعدة بيانات.
  - القرار = أثر × وزن الهدف × وزن التعلّم.
  - المقاييس بتتحدد بمسار جوه الصورة (`reputation.unanswered_negative`، `trip.{id}.recent`).
- `app/Services/Brain/BrainDecisionStore.php`: جدول `brain_decisions`. فيه الحالات `proposed`، و`approved`، و`done`، و`dismissed`، و`resolved`.
  - القياس بيحصل بعد 7 أيام من "اتنفّذ"، أو لما سبب القرار يختفي من البيانات.
  - لو مصدر البيانات مش متاح، القرار مش بيتقفل.
- `app/Services/Brain/BrainSchema.php` + `database/migrations/2026_10_29_000003_strategic_brain.sql`: نفس الجدول، الأول وقت التشغيل والتاني كهجرة.
- `app/Controllers/BrainController.php`:
  - `GET /brain`
  - `GET /api/brain?website_id=`
  - `POST /api/brain/decisions/{id}` (مع CSRF)
- `public_html/assets/js/brain.js` و`public_html/assets/css/brain.css`.
- القايمة: "العقل الاستراتيجي" تحت "نظرة عامة" (`Controller.php` في `$home`)، وكمان تبويب في قسم النتائج.
- الاختبارات: `tests/Unit/StrategicDecisionsTest.php`، و`tests/Integration/BrainDecisionStoreIntegrationTest.php` (فيه اختبار العزل بين الشركات).

## B3/B4: التأكد
- المحللات اتختبرت بعينات بنفس شكل الردود الموثّق (`tests/Unit/MarketIntelligenceTest.php`).
- بيئة Claude مش بتوصل للمصادر دي، فالرد الحقيقي بيتشاف على tourfectoai.pro من حالة المصادر في رد `/api/brain` (`snapshot.market.sources`).
- الوجهة والأسواق بتتحدد من نص الشركة نفسه (`MarketIntelligenceService::detect`). لو مفيش أسواق مكتوبة: GB وDE وUS وIT وFR، والصفحة بتوضح إنها افتراضية.

## ملاحظات للجلسة الجاية
- **ميزانية DataForSEO الشهرية للمنصة خلصت** (`seo_monthly_budget_reached`). ده موقّف قياس الكلمات والمقالات والخرائط لكل العملاء، ومحتاج أدمن يرفعه.
- **مركز المنافسين:** الجدول `competitors` (مش `ci_competitors`).
- **مستشار المدير التنفيذي:** الجداول `ceo_growth_opportunities` و`ceo_risk_alerts` محدش بيكتب فيها تلقائيًا. B5 ممكن يكتب فيها عشان تظهر في اللوحة التنفيذية الموجودة.
- **الـ classmap:** `tools/build_project_classmap.php` بيعيد ترتيب الملف كله (أكتر من 300 سطر). ضيف الكلاسات الجديدة بإيدك في `app/Config/project_classmap.php`.
- **اختبار محلي من غير PHPUnit:** للاختبارات النقية فيه shim صغير اتعمل في الجلسة دي (في الـ scratchpad، مش في الريبو). الـ CI بيشغّل PHPUnit الحقيقي.
