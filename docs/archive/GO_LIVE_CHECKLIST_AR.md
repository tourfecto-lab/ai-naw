# قائمة جاهزية التشغيل — Tourfecto

## 🆕 رفع تحديث أكتوبر 2026 (اعمله بالترتيب ده)

التحديث ده اتجرّب فعليًا: قاعدة بيانات جديدة اتبنت من الـ migrations لوحدها (250 جدول)،
والموقع اشتغل في متصفح حقيقي: تسجيل عميل ← معالج الإعداد ← طلب إيداع ← موافقة الأدمن ←
اشتراك من الرصيد ← باقة نشطة. وكل الاختبارات الآلية نجحت (581 اختبار).

1. **نسخة احتياطية كاملة** للملفات وقاعدة البيانات (Hostinger ← Backups) قبل أي حاجة. عندك عملاء حقيقيين.
2. **ارفع الملفات** فوق القديمة. ملفات اتمسحت من التحديث ده ولازم تمسحها من السيرفر كمان لو موجودة:
   `public_html/web.php` (كان بيطبع كلمة سر) و`public_html/_internal_error_dashboard_9f21x.php` (ثغرة بتسمح بتنزيل `.env`)،
   و`public_html/errors/index.php` (صفحة اختبار بتكشف مسار السيرفر)، و`app/Integrations/IntegrationManager.php`، و`app/Controllers/HomeController (1).php`، وأي ملف اسمه فيه `~1`،
   والمجلدات القديمة: `app/Chat` و`app/Reputation` و`app/Subscription` و`app/routes/Models` و`app/app`.
   ارفع كمان ملف `.htaccess` اللي في المجلد الرئيسي للمشروع: بيقفل `.env` والكود لو المشروع اترفع بالغلط جوه المجلد العام.
3. **حدّث الـ autoload**: لو عندك SSH: `composer install --no-dev -o`. لو مفيش، ارفع مجلد `vendor/` بعد ما تعمل الأمر ده على جهازك.
4. **طبّق تحديثات قاعدة البيانات** (آمنة: إضافة بس، مفيش حذف بيانات، وتتكرر عادي):
   ```
   php database/repair_production.php --dry-run
   php database/repair_production.php --apply
   ```
   مفيش SSH؟ اعمل Cron Job مؤقت في Hostinger بالأمر التاني يشتغل مرة واحدة، وبعدها امسحه.
   المفروض آخر سطر يقول `Failed: 0` و`Missing tables after run: 0`.
5. **حساب الأدمن**: لو كلمة سر أي أدمن هي `123123123` (كانت مكتوبة في `web.php`) غيّرها فورًا. لإنشاء/ترقية أدمن بكلمة سر قوية:
   `php tools/create_admin.php you@yourdomain.com` (بيسألك على كلمة السر). **ماتستخدمش** `database/seeds/users_seed.sql` (كلمة سره `password`).
6. **بيانات الدفع اللي بتظهر للعميل**: من لوحة الأدمن ← المحفظة ← الإعدادات، حط IBAN واسم البنك واسم صاحب الحساب وإيميل PayPal.
   دلوقتي العميل بيشوف نص مؤقت زي "حط رقم الـ IBAN بتاعك هنا". وحط رقم واتساب الدعم في `.env` (`SUPPORT_WHATSAPP_NUMBER`) عشان أزرار "اشترك عبر واتساب" تشتغل.
7. **جرّب الرحلة بنفسك** بإيميل جديد: سجّل ← كمّل الإعداد ← اطلب إيداع ← وافق عليه من `/admin` ← اشترك من `/plans`.

### الدفع بالكارت (اختياري، بس بيزوّد الاشتراكات)
الدفع الحالي يدوي (تحويل + موافقة). لتفعيل الدفع بالكارت تلقائيًا: حط `STRIPE_ENABLED=true` و`STRIPE_API_KEY` و`STRIPE_WEBHOOK_SECRET`،
واعمل Webhook في Stripe على `https://دومينك/api/webhook/stripe`.

---

مبنية على فحص ملفات المشروع والداتابيز اللي رفعتها. اللي اتأكدت منه من الملفات مكتوب "مؤكد"، واللي لازم تتأكد منه على السيرفر مكتوب "تحقق".

## 🔴 لازم قبل ما تفتح للعملاء

1. **ملفات التشخيص في المجلد العام (مؤكد من الملفات).** اتمسحت كلها في تحديث أكتوبر 2026 (`deep_debug.php`, `show_columns.php`, `system_check.php`, `diagnose_onboarding.php`, `web.php`, `_internal_error_dashboard_9f21x.php`). **بعد الرفع جرّب روابطها، لازم ترجع 404.**

2. **تحقق إن الملفات الحساسة مش مفتوحة.** افتح `tourfecto.pro/.env` و`/composer.json` و`/storage/logs/app.log` و`/database/schema.sql`. لازم الكل يرجع 403 أو 404. لو أي حاجة فتحت، يبقى المجلد الرئيسي للموقع (document root) مش `public_html`، وده لازم يتصلّح فورًا.

3. **البريد الإلكتروني (مؤكد من .env).** `MAIL_HOST` متعبّي لكن `MAIL_USERNAME` و`MAIL_PASSWORD` فاضيين، يعني رسائل التأكيد وإعادة كلمة المرور على الأرجح مش بتوصل. في داتابيزك 18 من 22 عميل ما أكّدوش بريدهم، وده بيتماشى مع الفرضية دي لكنه مش دليل مؤكد. اعمل صندوق بريد من Hostinger وحط بياناته، وجرّب تسجيل حساب جديد بإيميلك.

4. **الـ cron (استنتاج من بيانات الطابور).** من غيره الإيميلات والمراجعات والمقالات المجدولة والفواتير الدورية مش بتشتغل. الأوامر تحت.

5. **نسخ احتياطي.** المشروع فيه إعداد نسخ احتياطي (`DB_BACKUP_ENABLED`) لكن ما اتأكدتش إنه شغّال. فعّل Backups في Hostinger كمان (يومي) وجرّب استرجاع نسخة مرة واحدة.

6. **غيّر المفاتيح اللي اتشاركت.** ملف المشروع اللي رفعتهولي فيه `.env` بمفاتيح حقيقية (Gemini وAnthropic وOpenAI وGoogle وكلمة سر الداتابيز). ما طبعتش قيمها، لكن أي ملف اتشارك لازم تعتبر مفاتيحه مكشوفة: أنشئ مفاتيح جديدة وألغِ القديمة. **ما تغيّرش `ENCRYPTION_KEY`** بدون خطة، لأنه بيفك تشفير التوكنات المخزّنة (هتفقد ربط الحسابات).

## 🟠 مهم في أول أسبوع

7. **`META_APP_SECRET` فاضي.** ربط فيسبوك وإنستجرام والإعلانات مش هيشتغل لحد ما تحطه.
8. **`JWT_SECRET` فاضي.** النظام بيشتق مفتاح مؤقت من `ENCRYPTION_KEY`. الأفضل تحط قيمة منفصلة عشوائية (32 حرف أو أكتر).
9. **مراقبة الأخطاء.** `SENTRY_DSN` placeholder. من غير مراقبة هتعرف بالمشاكل من العملاء. في الأدمن عندك دلوقتي: المهام، الأمان، سجل التدقيق، حالة النظام.
10. **الدفع.** مفيش Stripe، وPayPal placeholder. الدفع الحالي = إيداع محفظة بموافقة يدوية. ده كويس للبداية، بس راقب "إيداعات منتظرة" يوميًا.
11. **حسابات الأدمن.** الـ3 حسابات من غير 2FA. فعّل التحقق بخطوتين، وأضف الموظفين من `/admin/team` بأدوار محدودة.
12. **احذف النسخ القديمة المكررة** (`app/Subscription` و`app/Security` و`app/Reputation` و`app/Chat`) بعد ما تتأكد إن كل شيء شغّال. فيها استعلامات بمخطط قديم.

## 🟡 تحسينات

- استعلام في `GoogleAdsAPI.php` بيشاور على جدول `customer` مش موجود في قاعدتك. راجعه لو بتستخدم الإعلانات.
- الاختبارات: `cd tests && phpunit -c phpunit.xml` على قاعدة `tourfecto_test` (581 اختبار، كلهم ناجحين في تحديث أكتوبر 2026). ماترفعش `tests/` على السيرفر.
- حط مراقبة خارجية (UptimeRobot) على `/` و`/login` عشان تتنبّه لو الموقع وقع.

## ⏱ أوامر الـ cron (Hostinger ← Advanced ← Cron Jobs)

غيّر `/home/USER/domains/SITE` للمسار الحقيقي. المواعيد من التوصيات المكتوبة في رأس كل سكربت جوّه المشروع نفسه.

```
* * * * *     php /home/USER/domains/SITE/cron/process_queue.php
*/15 * * * *  php /home/USER/domains/SITE/cron/action_center_executor.php
*/15 * * * *  php /home/USER/domains/SITE/cron/revenue_action_executor.php
*/15 * * * *  php /home/USER/domains/SITE/cron/seo_content_engine.php
*/10 * * * *  php /home/USER/domains/SITE/cron/process_email_automations.php
0 * * * *     php /home/USER/domains/SITE/cron/seo_scheduled_reports.php
0 * * * *     php /home/USER/domains/SITE/cron/run_ads_alerts.php
0 * * * *     php /home/USER/domains/SITE/cron/run_ads_autopilot.php
0 * * * *     php /home/USER/domains/SITE/cron/process_review_requests.php
0 2 * * *     php /home/USER/domains/SITE/cron/run_billing_lifecycle.php
0 3 * * *     php /home/USER/domains/SITE/cron/seo_rank_tracking.php
0 3 * * *     php /home/USER/domains/SITE/cron/ci_keyword_rankings.php
0 4 * * *     php /home/USER/domains/SITE/cron/sync_google_reviews.php
0 4 * * *     php /home/USER/domains/SITE/cron/sync_tripadvisor_reviews.php
0 5 * * *     php /home/USER/domains/SITE/cron/generate_outreach_followups.php
0 6 * * *     php /home/USER/domains/SITE/cron/revenue_intelligence_scan.php
0 4 * * 1     php /home/USER/domains/SITE/cron/revai_benchmarks_rebuild.php
0 5 * * 1     php /home/USER/domains/SITE/cron/monitor_backlinks.php
0 8 * * 1     php /home/USER/domains/SITE/cron/ci_weekly_digest.php
```

ابدأ بأول سطر بس (الطابور)، وراقب `/admin/jobs` يومين، وبعدها ضيف الباقي واحد واحد. السكربتات اللي مفيهاش موعد واضح (`auto_seo_scheduler` و`monitor_competitors` و`process_ai_followups` و`apply_reply_rules` و`gbp_enqueue_background_sync`) افتح رأس كل ملف وخد الموعد المكتوب فيه. لو واحد منهم مش بيشتغل بدون مفتاح خارجي (مثلاً Meta أو Google)، سيبه لحد ما المفتاح يتحط.

## ✅ اختبار قبول سريع بعد الرفع (15 دقيقة)

1. سجّل حساب جديد بإيميل حقيقي. جاتلك رسالة التأكيد؟
2. اعمل onboarding كامل. خلص في أقل من دقيقتين؟
3. اعمل إيداع تجريبي في المحفظة. ظهر في `/admin/plans` وانت اعتمدته؟
4. ادخل بحساب دور "دعم" تجريبي. مش شايف الفوترة والإعدادات؟
5. افتح `/admin/security` و`/admin/jobs`. مفيش أخطاء ولا مهام عالقة؟
6. افتح الموقع على موبايل (عربي وإنجليزي، فاتح وداكن).
