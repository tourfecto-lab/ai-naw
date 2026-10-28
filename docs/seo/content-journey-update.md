# Two-customer content journey and accurate next steps

Base ec54a2a, branch seo/content-publication-polish.

The new integration test runs two independent customers through production keyword research parsing/cache/persistence, target selection, real content-brief construction, plan generation orchestration, human draft review, approval, PHP connector publication, EdgeRules compilation, HTTP rendering and publication verification. One fixture offers Luxor services to the UK English search market; the second offers Istanbul services to the Saudi Arabic market. Fake provider responses also include a higher-volume unsupported destination. Each customer selects its supported service and market, preserves its language, and publishes only on its own domain.

DataForSEO transport/ledger, competitor HTTP transport, generation and usage settlement are fakes. Database operations use the guarded disposable MariaDB. The generated standalone connector runs through a real local PHP HTTP server. Five competitor outlines and People Also Ask are parsed into each brief; competitor prices never become customer facts. Review rejects unsupported prices and invented internal URLs. Publication is checked for approved text/title, reviewed description, canonical, FAQPage/Article JSON-LD, actual inline links, reciprocal pillar links, native theme and sitemap. Foreign website, market, brief and draft access are rejected. Changing customer one's profile invalidates its old brief without blocking customer two. Monthly report data remains scoped by owner/site. No live provider, customer publication, booking, email or wallet debit is performed by this test.

The first fixture setup used a reserved non-public test hostname, which the existing public-page guard correctly rejected. The fixture now uses public example hostnames mapped by its test HTTP adapter. Production SSRF/public-host restrictions were not relaxed. Its local HTTP server explicitly represents a TLS-terminated customer site, so connector-generated HTTPS canonicals are tested correctly.

SeoExpertService accepts an optional ContentBriefService as a fifth constructor argument. Existing callers are unchanged; production still constructs the normal service. This narrow dependency injection supplies fake research transports without replacing the brief builder, planner, database, publisher or verifier in the integration test. The model response remains a fake: the test proves orchestration and guards, not live model editorial quality or live provider availability. Both complete journeys use the PHP connector path; existing WordPress publisher/signature/schema tests continue to run separately in the full suite.

## Follow-up distinction

The current UI and monthly next plan previously lumped discovered, not-yet-crawled pages into customer repair work. A derived follow_up field now distinguishes:

- verified: existing dated verification, not measured ranking improvement;
- in_progress: approved changes or publication verification;
- waiting_google: a connected inspection from the last 24 hours explicitly reports discovered_not_crawled, indexed=false, NEUTRAL, no last crawl and no conflicting fetch/robots block;
- needs_check: missing, stale, invalid/future or unavailable Google observation;
- needs_review: unknown URLs, crawled-not-indexed, fetch/robots/noindex/canonical problems and other real review work;
- excluded: retained unsuitable proposals or missing page evidence.

Waiting is not proof that an indexing request was submitted, that the current public page is accessible, or that Google will index it. The card says to keep the page accessible and linked and monitor crawling. An unavailable latest attempt cannot reuse an older successful inspection to invent fresh certainty. The connected refresh button remains available. Waiting pages are shown under Google follow-up and omitted from the customer-completion form/count/filter. They are never counted as verified. Database statuses, evidence, timestamps and run versions are not changed by viewing the classification.

The monthly report separates current Google follow-up and excluded proposals from its customer-review next plan. These sections explicitly describe the state at report preparation; historical calendar-month GSC/ranking/impact metrics retain their existing period semantics. Up to 1,000 pending candidates are classified, capped to 10 displayed per section so excluded tasks do not crowd out the customer list. Owned run snapshots are fetched once per distinct run, rather than duplicated in every task row. Raw snapshots, profiles and connection evidence are not included in the projected report rows. No email was sent to a real recipient during this work.

## Install

Upload the ZIP's app and public_html directories to PLATFORM hosting (tourfecto.pro), preserving paths after taking a backup. Reload platform PHP OPcache and restart persistent queue workers, then hard-refresh SEO Expert. No SQL migration, Nginx change or customer WordPress/PHP connector replacement is required. Runtime files:

- app/Config/project_classmap.php
- app/Services/Seo/SeoMonthlyReportService.php
- app/Services/SeoExpert/SeoExpertService.php
- app/Services/SeoExpert/SeoTaskFollowUp.php
- public_html/assets/js/seo-expert.js

Tests/docs do not need uploading to hosting. The deployed JavaScript was checked against base ec54a2a before packaging. Open an existing completed plan: Google follow-up, customer action, verified results and excluded history should have separate filters/counts. Old observations request a connected refresh; do not start a paid content cycle just to update those observations. Preparing this archive does not deploy it or verify the new UI on a customer account.

Validation: journey integration 53 assertions, next-step/report logic 28, real JavaScript browser 17, all passing. All 52 suite groups ran; 50 pass. Known baseline billing failures remain plans_studio (40/47) and plan_gate (19/24). All phase 1–6, research, connector, xpay and fx_bank suites pass. PHP lint checks 1279 files. Full output is in content-journey-test-output.txt. Provider output quality and eventual Google indexing still require live operational monitoring; these fixture results do not claim a ranking uplift or superiority over every SEO expert.

Rollback: restore backed-up runtime files together, reload PHP/workers and refresh the page. No data rollback is required because the new follow-up category is derived without rewriting saved tasks or Google proof.
