# Plain keyword inputs and site-scoped research

Base: `9a9f7fb`, branch `seo/content-publication-polish`.

Legacy AI analysis explicitly requested keyword strings such as `Luxor tours (Transactional, High)`. Tracking these strings sends the intent/priority annotation to a search provider as query text, so a paid result may describe the wrong query. A configured provider also does not mean that tracked words already have measured demand or rankings.

This update changes the AI prompt to request plain phrases, rejects recognized trailing annotations in new tracking input, and validates all rank batches and explicit research seeds before any provider call or quota reservation. The matcher recognizes only complete trailing `(intent, priority)` labels in English or Arabic, including combined intents. Real qualifiers such as `(private guide)` or `(Cairo, Egypt)` remain intact. It is a conservative repair for the known legacy format, not a general parser for every possible AI output.

The keywords page shows the original phrase and a suggested clean phrase. **راجعت العبارة — تنقية بدون قياس** asks the customer to review the exact before/after values. Its authenticated, CSRF-protected endpoint checks the original phrase again and locks the owned site/row. It changes only `keyword`; row ID, page mapping, market and other fields stay intact. It performs no research, ranking, queueing, email or billing action.

Any tracked measurement, including zero, GSC timestamp, rank history, research provenance or canonical collision prevents repair. Nothing is deleted or merged. For such a record, retain its history and add the clean phrase separately. A SQL read/write failure does not report a successful correction. The endpoint is `POST /api/seo/data/keywords/{id}/repair` with `website_id`, `market_id`, `expected_keyword` and strict boolean `reviewed:true`.

The SEO workspace's keyword-research link carries the current `website_id` and follows site changes. The keywords page resolves that ID against the account's returned websites; an unavailable ID shows a selection message instead of falling back silently. Delayed market/keyword reads from a previous selection cannot overwrite the current site's view. Admin market management and existing research, ranking and gap tools remain available.

## Hosting / الرفع

ارفع الملفات السبعة التالية في نفس مساراتها على استضافة المنصة **tourfecto.pro**، مع الاحتفاظ بنسخة احتياطية من الملفات المستبدلة:

- `app/Controllers/SeoDataController.php`
- `app/Services/AI/PromptBuilder.php`
- `app/Services/Seo/DataForSeoRankingSource.php`
- `app/Services/Seo/DataForSeoResearchService.php`
- `app/Services/Seo/RankTrackingService.php`
- `app/routes/api.php`
- `public_html/assets/js/seo-workspace.js`

التحديث مبني على النسخة `9a9f7fb` السابقة. ارفع المجموعة كاملة وحدّث كاش PHP/OPcache والمتصفح/CDN عند الحاجة. لا يوجد SQL جديد ولا يلزم تحديث ملف ربط العميل أو إضافة WordPress. ملفات tests وdocs للمراجعة ولا تحتاج رفعها للتشغيل. لا يوجد تعديل تلقائي للكلمات القديمة أثناء الرفع.

بعد الرفع افتح **خبير السيو ← الفحص ← بحث الكلمات**. الموقع المختار ينتقل معك تلقائيًا. راجع العبارات المقترحة ثم نقِّ الكلمات غير المقاسة. البحث وقياس الترتيب بعد ذلك عمليتان منفصلتان وفق الاستخدام والتكلفة، وليستا جزءًا من التنقية المجانية. وجود أحجام بحث أو ترتيب يحتاج طلبًا حقيقيًا معتمدًا؛ لا تُنشأ أرقام بديلة عند غياب القياس.

## Validation

- `tests/Automation/seo_keyword_input_test.php`: actual controller with mock Database and fake provider/ledger; phrase matching, stale/foreign/CSRF/consent guards, zero/provenance preservation and no paid call for contaminated inputs, including a later batch.
- `tests/Automation/seo_keyword_input_database_test.php`: marker-guarded disposable MariaDB; actual SQL, ownership, field preservation, historical absence/research retention, canonical collision and forced SQL-failure rollback.
- `tests/Automation/seo_keyword_input_browser_test.cjs`: actual rendered controller markup/inline script and Panel transport in Chromium; requested site, exact review/cancel/save, CSRF, server reread and unavailable-site recovery.
- Guided journey regression also verifies keyword links before and after a site switch.
- Complete required suites and results: `docs/seo/seo-keyword-input-test-output.txt`. Known existing billing-suite failures remain reported separately; this update does not alter billing rules.

No customer credentials, private account results or connector keys are included. Offline tests do not establish deployment or ranking improvement; deployment must be checked after hosting replacement.

Final validation: 81 groups completed, 79 passed. New checks: 31 mock/provider + 10 actual SQL + 11 browser = 52 assertions passed; guided customer journey now28 assertions. Required phase1/2/3, connector, xpay and fx_bank pass; phase4/5/6 and all other customer regressions pass. Unchanged existing failures: plans_studio40/47 and plan_gate19/24. PHP lint checked1301 files with no syntax errors; JavaScript syntax and git diff checks pass. No deployment or paid live keyword request was performed.
