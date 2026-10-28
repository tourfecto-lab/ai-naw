# SEO commercial-claim evidence review

Base: `d59d204`, branch `seo/content-publication-polish`.

A customer audit found proposed metadata promising free cancellation and licensed guides, plus a previously published guide stating child discounts without those policies in its saved, confirmed business facts. Technical publication verification only establishes that the approved text is visible. It does not establish that a booking policy or credential is true.

## Behavior

- A shared offline guard checks observed English/Arabic cancellation promises, guide credentials and child-pricing statements. A flagged statement requires the same complete sentence and scope in the confirmed shared profile's `business_facts`. Normalized case/formatting is accepted; removing limitations or changing child ages is not.
- Audience, goals, competitor text, imported page text and one product's `offers` cannot establish a general booking policy. Confirmation questions/instructions remain possible. A question or request inside the facts is not affirmative evidence.
- New metadata suggestions are filtered before task creation. Article generation and existing article/draft validation check titles, descriptions, content and FAQ answers through the same guard, preserving the existing price, duration, family-suitability, visible-FAQ, link and structured-data validations.
- Explicit SEO Expert approval rechecks the stored draft and its owned quality record. A forged selection or `reviewed=true` cannot bypass the check. A failed batch rolls back earlier approvals and queues no execution. Previously queued approved work is checked before public-page reads, connector activation or publication; customer evidence and existing check timestamps are preserved when it is returned for review.
- Already published, verified or in-flight publication history is preserved. Existing publication recovery/idempotency behavior is retained; a warning does not republish, delete or roll back live content. Uncertain/in-flight outcomes require their existing reconciliation process.
- Details show a separate factual-review warning, the exact flagged excerpts and a simple correction step. Unsafe pending metadata starts unchecked. It remains editable. The Needs my input filter includes historical factual-review warnings while retaining technical status, dates, URLs, rollback and other actions. Manually selecting unsafe work gives correction guidance before the request; the backend independently enforces the rule.
- Current pending factual reviews and their required action also appear in the monthly next-plan section. Historical monthly metric/task boundaries are preserved; this is not a new audit of every past report.

## Customer steps / خطوات العميل

1. افتح خبير SEO واختر موقعك ثم افتح الدورة الحالية، واضغط «اللي يحتاج تدخلي».
2. لو ظهرت «معلومة تجارية محتاجة تأكيد»، اقرأ العبارة المحددة. لو غير مؤكدة، احذفها من الاقتراح واضغط «حفظ التعديل»، ثم راجع واعتمد الباقي. لا تحتاج دورة مدفوعة لتعديل اقتراح حالي.
3. لو السياسة صحيحة فعلًا، سجّلها بجملتها الكاملة وحدودها في معلومات النشاط المشتركة، وأكدها. تغيير نسخة معلومات النشاط يظل محتاجًا خطة تستعمل النسخة الجديدة وفق نظام المراجعة الموجود؛ هذا التحديث لا يبدأ دورة أو يخصم رصيدًا بنفسه.
4. لو العبارة موجودة في محتوى منشور بالفعل، راجع الصفحة الأصلية وصححها أو أكد سياستها. ظهور التنبيه لا يحذف المقال ولا يغيّر سجل التحقق التقني تلقائيًا.

## Hosting / الرفع

على استضافة **المنصة tourfecto.pro**، ارفع الملفات التالية في نفس مساراتها، فوق النسخ الموجودة:

- `app/Config/project_classmap.php`
- `app/Services/AI/ArticleGenerator.php`
- `app/Services/Seo/SeoMonthlyReportService.php`
- `app/Services/SeoExpert/SeoClaimEvidence.php` (جديد، لا تنس رفعه)
- `app/Services/SeoExpert/SeoExpertService.php`
- `app/Services/SeoExpert/SeoPlanQualityGate.php`
- `app/Services/SeoExpert/SeoRecommendationReview.php`
- `app/Services/SeoExpert/SeoTaskFollowUp.php`
- `public_html/assets/js/seo-expert.js`

لا يوجد SQL جديد ولا تحديث مطلوب لملف ربط العميل أو إضافة WordPress. يفترض التحديث وجود تحديثات الفرع حتى `d59d204`. احتفظ بنسخة احتياطية من الملفات المستبدلة، وارفع المجموعة كاملة، ثم حدّث كاش PHP/OPcache إذا كانت الاستضافة لا تحدثه تلقائيًا، وكاش المتصفح/CDN. JavaScript يستخدم `asset_v` الموجود بالفعل. ملفات `tests/` و`docs/` للمراجعة والتوثيق؛ **لا تحتاج رفعها للتشغيل**. لا توجد بيانات عميل أو مفاتيح ربط في الحزمة.

## Limits and validation

This is a deliberately conservative guard for observed claim patterns, not a multilingual semantic fact checker or proof of truth. Unrecognized paraphrases, other prices/policies, destination claims and page-specific booking conditions still need editorial review and dated evidence. Exact sentence matching can flag legitimate paraphrases. It does not validate that a customer-entered policy is actually legal, current or fulfilled. No global factual-quality score or verified-business badge is inferred from `no_flagged_claims`.

The review of saved customer data reproduced the observed flags locally without altering its tasks or publication. No new paid cycle, AI/provider purchase, outbound email, booking or payment occurred. Search-impact windows are still waiting for their scheduled observations; no ranking or revenue improvement is claimed.

New tests: `seo_claim_evidence_test.php` (38 assertions), `seo_claim_evidence_database_test.php` (18 actual-service assertions on marker-guarded disposable MariaDB), `seo_claim_evidence_browser_test.cjs` (14 actual Panel/Chromium assertions). These cover exact/scoped facts vs negations, questions, offers and age changes; English/Arabic findings; title/description/body/FAQ checks; forged approval and transaction rollback; legacy queue blocking before HTTP; unchanged history/evidence; safe correction and normal approval; monthly action assembly; escaped snippets and Arabic mobile presentation. The existing brand-retention unit fixture now uses ordinary local guides instead of introducing an unrelated unconfirmed credential; its four tests/seven assertions are retained.

Full regression output and exit statuses: `seo-claim-evidence-test-output.txt`. The required phase1/2/3/connector/plans_studio/xpay/plan_gate/fx_bank suites and phase4/5/6 regression groups are included. Final results: **75 groups, 73 passed**. Required phases1–6, connector, xpay and fx_bank pass. The same pre-existing baseline failures remain `plans_studio`40/47 and `plan_gate`19/24; they concern trial/pack/chatbot allowances and wallet expectations. They are not changed or represented as passing. PHP lint checked1,296 files successfully; JavaScript syntax and `git diff --check` pass. All70 new assertions pass (38 pure +18 actual-service SQL +14 browser).
