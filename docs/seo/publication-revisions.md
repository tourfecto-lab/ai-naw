# Revising an article published by the PHP connector

Base: `35d5e6a`, branch `seo/content-publication-polish`.

SEO Expert could publish and verify a PHP connector guide but could not correct the published prose through its normal draft editor. Editing a verified task directly would erase its original evidence. This update adds a separate preview and revision workflow for an existing connector-owned article at the same URL.

## Customer workflow

1. In the site's saved cycle, open **Review and correct published article / مراجعة وتصحيح المقال المنشور** on the active article.
2. Edit its plain text/Markdown and, when stored, its SEO description. Preview the correction. Previewing changes no task, public page, queue or payment record.
3. Read the exact proposed text, existing URL and number of FAQ answers that will be removed because they no longer appear visibly. Confirm saving the reviewed correction.
4. Saving creates a distinct current task at the same public URL. The previous task, original text, verification status/timestamps, image and link settings remain in history. It is explicitly marked as superseded and is not reverified against the new text or listed as unresolved current work.
5. The connector receives the latest active source after its normal cache refresh (typically two minutes). The normal verification job is queued after three minutes. The new task remains awaiting verification until its full approved text is observed. Saving is not an immediate indexing, ranking or sales improvement claim.

## Validation and preservation

- Only an active same-origin `/guides/{slug}` or exact `/?tf_article={slug}` publication without a WordPress post ID is eligible. WordPress posts retain their native editor flow; operational paths, ambiguous query strings and foreign targets are rejected.
- Preview tokens expire after15 minutes. HMAC signatures bind the authenticated account, site, source task, run version, source body/quality and exact proposed content. Uses the existing application encryption secret with a dedicated signing context; no new key configuration is required.
- The server rechecks ownership, the current confirmed business profile, source eligibility, run version and stored quality data inside the site lock/transaction. Explicit reviewed consent is required. Existing price, duration, credential/cancellation/child-pricing and article validations remain enforced. Unknown policies are not silently confirmed.
- Saving uses existing task and content-quality tables; no new migration or external publishing request. A legacy article without a research brief is explicitly tagged as an existing-publication revision, with confirmed customer facts and known internal URLs. No keyword volume, provider research or new paid AI generation is invented.
- Retrying a completed save with the same signed preview returns its existing new task and queues no duplicate work, including after public verification. Revision provenance survives verification.
- The previous publication date is retained across revisions. Previous source rows stay in the database, but the connector compiles schema only from the current article's quality source. Removed FAQ answers cannot return through an older quality row. Existing image and related-link settings are carried forward.
- The new task uses the existing automatic7/14/28-day impact-monitor mechanism when the measurement tables are installed. Old comparison evidence is retained. Before/after comparisons remain observational.
- Moved workspace form controls are disabled during pending actions. This prevents typing into a task while an in-progress view reload can replace its inputs. Claim warnings also show a sentence once when it matches multiple risk patterns.

## Hosting / تعليمات الرفع

على استضافة المنصة **tourfecto.pro**، ارفع الملفات الثمانية التالية في نفس مساراتها، فوق النسخ الموجودة، وارفع المجموعة كاملة:

- `app/Config/project_classmap.php`
- `app/Services/AutoFix/EdgeRules.php`
- `app/Services/Seo/SeoMonthlyReportService.php`
- `app/Services/SeoExpert/SeoPublicationRevision.php` **ملف جديد، لا تنس رفعه**
- `app/Services/SeoExpert/SeoExpertService.php`
- `app/Services/SeoExpert/SeoRecommendationReview.php`
- `app/Services/SeoExpert/SeoTaskFollowUp.php`
- `public_html/assets/js/seo-expert.js`

التحديث يفترض تثبيت `35d5e6a`، وهو تحديث مراجعة الحقائق السابق. احتفظ بنسخة احتياطية من الملفات المستبدلة. لا يوجد SQL جديد، ولا تحتاج تبديل ملف `tourfecto-connect.php` أو إضافة العميل. حدّث كاش PHP/OPcache عند الحاجة وكاش المتصفح/CDN بعد رفع المجموعة كاملة. الاختبارات وملفات التوثيق للمراجعة ولا يلزم رفعها للتشغيل. الحزمة لا تحتوي بيانات حسابات أو مفاتيح ربط أو نصوص عميل خاصة.

بعد الرفع افتح الدورة التي بها المقال، ثم «مراجعة وتصحيح المقال المنشور». المعاينة لا تنشر شيئًا. «حفظ التصحيح على نفس الرابط» يغيّر المقال الحالي مع حفظ النسخة السابقة، والتحقق الفعلي يأتي بعد تحديث الكاش. يمكن الرجوع إلى نص أي نسخة سابقة في سجل المهام، لكن لا يوجد زر استرجاع تلقائي في هذا الإصدار؛ يمكن معاينة نسخة مراجعة أخرى باستخدام النص المطلوب.

## Tests

`seo_publication_revision_test.php`:19 pure assertions for eligible targets, HMAC/account/source/expiry binding, visible FAQ removal and retained claim/price/duration checks. `seo_publication_revision_database_test.php`:29 actual-service assertions on marker-guarded disposable MariaDB cover preview with no mutations, authorization/stale facts, rejected tampering, retained history/date/image/links, one current same-URL revision, idempotent retries, automatic impact mappings, superseded-source filtering, actual connector output and normal verification. `seo_publication_revision_browser_test.cjs`:15 actual Panel/SEO/workspace Chromium assertions cover moved-control busy state, escaped preview, explicit consent, invalidation after edits, exact CSRF/versioned save, history, pending verification and Arabic mobile layout. The existing claim browser suite adds one assertion for duplicate-sentence collapse (15 total).

All test fixtures are offline. No provider purchase, new AI cycle, SMTP/email, booking or financial operation is used. Full required phase1/2/3/connector/plans_studio/xpay/plan_gate/fx_bank and phase4/5/6 regression groups are included in `seo-publication-revision-test-output.txt`. Final results: **78 groups, 76 passed**. The unchanged baseline failures remain `plans_studio`40/47 and `plan_gate`19/24. Required phases1–6, connector, xpay and fx_bank pass. All63 new revision assertions pass (19 pure +29 actual-service SQL +15 browser); the extra duplicate-warning browser assertion also passes. PHP lint checked1,299 files successfully, and JavaScript syntax and diff whitespace checks pass. Production application of the corrected article awaits upload of this complete runtime package; saving the three existing customer metadata drafts did not publish them or change the live article.
