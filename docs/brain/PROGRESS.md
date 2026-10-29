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
| B2 | التنفيذ التلقائي المصرّح بيه: ربط القرارات بمحركات التنفيذ الموجودة (طيار السيو، خطة السوشيال الشهرية، `ActionCenterExecutor`، `AdAutopilotEngine` بموافقة) + قياس من الخدمة نفسها | جاية |
| B3 | ذكاء مشترك بين الشركات: تجميع نتايج القرارات حسب `decision_key` و`area` من غير هوية، بحد أدنى 5 شركات لكل رقم (k-anonymity)، وبموافقة الشركة (opt-in)، واستخدامه كوزن إضافي في `StrategicDecisions::decide` | جاية |
| B4 | ذكاء السوق: مصادر موثّقة (مثلًا World Bank API للمؤشرات، Eurostat/UN Tourism للطلب السياحي، Google Trends عبر مزود مرخّص). كل رقم بيتحفظ معاه مصدره وتاريخه. **محتاج موافقة على المصادر والتكلفة** | جاية |
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

## ملاحظات للجلسة الجاية
- **ميزانية DataForSEO الشهرية للمنصة خلصت** (`seo_monthly_budget_reached`). ده موقّف قياس الكلمات والمقالات والخرائط لكل العملاء، ومحتاج أدمن يرفعه.
- **مركز المنافسين:** الجدول `competitors` (مش `ci_competitors`).
- **مستشار المدير التنفيذي:** الجداول `ceo_growth_opportunities` و`ceo_risk_alerts` محدش بيكتب فيها تلقائيًا. B5 ممكن يكتب فيها عشان تظهر في اللوحة التنفيذية الموجودة.
- **الـ classmap:** `tools/build_project_classmap.php` بيعيد ترتيب الملف كله (أكتر من 300 سطر). ضيف الكلاسات الجديدة بإيدك في `app/Config/project_classmap.php`.
- **اختبار محلي من غير PHPUnit:** للاختبارات النقية فيه shim صغير اتعمل في الجلسة دي (في الـ scratchpad، مش في الريبو). الـ CI بيشغّل PHPUnit الحقيقي.
