# دليل التشغيل على بيئة تجريبية (Staging) — قبل الإنتاج

> الاختبارات اللي اتعملت هنا اشتغلت على SQLite + PHPUnit مبسّط، **مش MySQL حقيقي**. الخطوات دي هي اللي بتقفل الفجوة دي.

## 0) قبل أي حاجة
- خد نسخة احتياطية (dump) من قاعدة البيانات والكود.
- نفّذ على Staging فيها نسخة من بيانات الإنتاج (أو بيانات قريبة) وSMTP تجريبي (Mailtrap أو حساب صغير).

## 1) الـ Migrations (بالترتيب)
1. `2026_10_18_000001_email_reputation_guard.sql`  — راجع توسعة الـ ENUM (حالات: paused/uncertain/sending).
2. `2026_10_19_000001_email_compliance.sql`
3. `2026_10_20_000001_email_ab_auto_winner.sql`
- لو ظهر `Duplicate column` عند إعادة التشغيل = طبيعي، تجاهله.
- تحقق: `SHOW COLUMNS FROM email_campaign_recipients;` فيها attempts/next_attempt_at/error_class.

## 2) المسارات والـ Cron
- أضف محتوى `excerpts/routes_api.email_reputation.txt` إلى `routes/api.php`.
- Cron جديد كل 10 دقائق: `php cron/process_ab_winners.php`.
- (اختياري) ثوابت: `EMAIL_PLAN_LIMITS`, `EMAIL_REQUIRE_FIRST_CAMPAIGN_REVIEW`, `EMAIL_WEBHOOK_TOLERANCE_SECONDS`.

## 3) خطة الاختبار (كل بند: متوقع → فعلي)
| # | الاختبار | المتوقع |
|---|---|---|
| 1 | أرسل حملة صغيرة (5 مستلمين) | كلهم sent، أرقام الاستهلاك في /quota زادت 5 |
| 2 | اضبط حد يومي = 3 وأرسل 5 | يتبعت 3 والباقي يتأجل لبعد منتصف الليل UTC (مش failed) |
| 3 | أرسل لعنوان وهمي (user-unknown) | bounced + hard bounce يدخل suppression، بدون إعادة محاولة |
| 4 | وقّف SMTP مؤقتًا | temporary → backoff 5/20/80 دقيقة، أقصى 3 محاولات |
| 5 | حاكي ويبهوك complaint (توقيع صحيح) | المستلم complained؛ بعد تجاوز العتبة الحملة/الحساب paused |
| 6 | أعد نفس الويبهوك | لا يُحسب مرتين (dedupe)؛ طابع زمني قديم >10 دقائق يُرفض |
| 7 | أدمن: pause ثم resume لحساب | الحملات الموقوفة بسبب الحساب ترجع تلقائيًا؛ حملات الإيقاف اليدوي لا |
| 8 | حساب جديد (أول إرسال) | warm-up 50 ثم 100... والمراجعة الأولى لو مفعّلة |
| 9 | استيراد ملف فيه disposable/role/typo | rejected/risky ظاهرة في الرد |
| 10 | GDPR: export ثم erase لمشترك | التصدير يطلع بياناته؛ بعد الحذف الاسم/الإيميل مجهّل ولا يُرسل له |
| 11 | A/B بـ holdout 30% وtest_hours=1 | الاختبار على 70% فقط؛ بعد ساعة وانتهاء الإرسال cron يبعت الفائز للـ 30% (مرة واحدة فقط) |
| 12 | شغّل `process_ab_winners.php` مرتين متتاليتين | الحملة الثانية لا تتكرر (قفل holdout_campaign_id) |
| 13 | أتمتة: condition (has_tag) ثم goal | الفرع الصح يتنفذ، وgoal يُنهي المشاركة بدون إكمال الباقي |
| 14 | `/campaigns/{id}/insights` و`export.csv` | أرقام تطابق الـ DB؛ الـ CSV يفتح في إكسل بالعربي |

## 4) أشياء لازم تتأكد منها بنفسك (ما اتجرّبتش هنا)
- `sendBatch` (UPDATE…JOIN + SHA2) على MySQL حقيقي، و`GET_LOCK` للحجز الذري.
- الويبهوك مع مزوّد الإرسال الفعلي (شكل الـ payload والتوقيع).
- حمل حقيقي: حملة 5–10 آلاف على Staging ومراقبة زمن الـ batch والـ locks.
- ربط الإيرادات: مرّر `revenueResolver` في `EmailReportService` (من غيره الإيراد يظهر null).

## 5) الرجوع (Rollback)
- الـ migrations additive (أعمدة/جداول جديدة) — الكود القديم يشتغل معاها.
- للرجوع السريع: ارجع الكود القديم فقط؛ لا تحذف الأعمدة.
- لو حصل إيقاف غير متوقع لعملاء: `EmailReputationGuard::resumeAccount` من لوحة الأدمن، أو اضبط حدود أعلى مؤقتًا.
