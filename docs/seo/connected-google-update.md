# Check an existing SEO task against current Google data

Base 0120c6e, branch seo/content-publication-polish.

The customer audit found a completed sitemap submission still shown as an unresolved task, and discovered URLs described as fetch failures because PAGE_FETCH_STATE_UNSPECIFIED was interpreted as an error. The parser now keeps unspecified fetch state neutral so Google's discovered/unknown coverage remains meaningful. Genuine fetch errors, robots blocks and noindex retain their existing precedence.

Existing manual sitemap/indexing task cards now offer Check current Google status / افحص الحالة من Google الآن. The existing done endpoint accepts {connected_check:true,version:N}. It reads listSitemaps or URL Inspection with the customer's existing connection. It never submits sitemaps, requests indexing, writes customer pages or starts generation. Read-only Search Console permission is sufficient; no paid-provider or wallet call is made. Existing automatic impact-monitor policy remains: a verified task can queue observational 7/14/28-day comparisons, which do not prove that the check itself caused an SEO improvement.

Submission is verified only when Google lists the exact requested sitemap without reported errors. Pending processing and warnings remain visible; submission does not imply indexing. Indexing is verified only by a current successful Google URL Inspection verdict. Discovered/unindexed pages remain needs_client. Missing connections/API failures are not_measured and preserve earlier dated proof, rather than inventing a negative observation. A recent failed attempt is shown separately. Up to ten preceding connected observations and existing customer review notes are retained.

Checks enforce task ownership, website and Google property scope, URL-prefix boundaries, current run version and draft/completed run state. Operational pages such as /quiz remain excluded pending an indexing-intent decision. Query URLs used by connector articles are supported. Provider errors/credentials are not exposed. Task, evidence, run version and website identity are rechecked after the network call so concurrent customer edits cannot be overwritten. Unsaved browser edits block the action before any request. Old legacy indexing task titles use neutral wording on the card; the historical proposal remains stored.

Install on PLATFORM hosting (tourfecto.pro). Back up and upload the archive's app and public_html directories preserving paths. Reload platform PHP OPcache/restart persistent workers and hard-refresh SEO Expert. No SQL migration or replacement of the customer's PHP/WordPress connector is required. Tests/docs do not need uploading. The live platform JavaScript matched base 0120c6e before preparing this update.

Runtime files:
- app/Controllers/SeoExpertController.php
- app/Services/SearchConsole/GoogleSearchConsoleAPI.php
- app/Services/SeoExpert/SeoConnectedTaskVerifier.php
- app/Services/SeoExpert/SeoExpertService.php
- app/Config/project_classmap.php
- public_html/assets/js/seo-expert.js

After upload, open the existing completed cycle for the selected site. Check its sitemap task first, then each marketing-page indexing task. Review the newly returned Google coverage and last crawl. Do not create another paid cycle solely to update these observations. The new action is not yet deployed or executed against the production customer account by preparing this archive. The previous accepted sitemap result is historical and does not establish today's processing status.

Offline validation: parser/helper 22 assertions, database 16, real JavaScript browser 11, all passing. Cases include exact sitemap matches, pending/errors, unavailable responses, malformed inspection, wrong domain/property/port, query URLs, customer ownership, stale versions, concurrent plan/evidence/website changes, historical proof preservation, unsaved draft protection and no publication/generation/wallet side effects. All 45 suite groups were run: 43 pass. Known baseline billing failures remain plans_studio (40/47) and plan_gate (19/24). All phase 1–6, research, connector, xpay and fx_bank suites pass. PHP lint: 1273 files. Complete output: connected-google-test-output.txt. All provider transports in tests were fake; database fixtures were guarded disposable databases.

Rollback: restore the backed-up runtime files and reload platform PHP. Saved observations remain historical task evidence; restoring code does not undo observations or publish anything.
