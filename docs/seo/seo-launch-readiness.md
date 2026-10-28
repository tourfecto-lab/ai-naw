# SEO customer launch update

This update prepares a controlled customer pilot. A stored connection, an enabled worker, an applied edit, Google indexing and a measured business outcome are separate states. The checklist uses stored evidence and identifies the next step; it does not assign a fabricated readiness score or guarantee results.

## Customer journey

1. Select the correct website and confirm its services, destinations, audience and offers in Business Hub. Test the editing connector, Search Console and Analytics for that website. Each account and website keeps its own credentials, facts, markets and records.
2. Open Scan → keyword research. Select the audience country and language. An unmeasured default market can be corrected after review without deleting keyword IDs or page targets. A market with measurement/history cannot be relabelled as another country. Keep it and add another market within the plan allowance.
3. Review the free usage quote before demand, discovery, rankings or competitor-gap measurement. It lists the market, words/domain, maximum units and estimated wallet maximum. Confirm an explicit cap. Discovery tracks at most five real provider words; exact demand measures up to five saved words. Missing data remains unknown; measured zero and measured top-100 absence remain distinct. A gap needs actual competitor evidence and a customer SERP check.
4. Review the plan-generation allowance and the separate article-brief wallet limit, then start a cycle. The default brief wallet limit is zero. A brief needs measured demand, current confirmed facts, competitor pages and real questions. If research is blocked, the technical plan can continue with an explicit content-research review state. An interrupted paid brief is not retried automatically without a cached result.
5. Review proposals and facts before applying/publishing. Existing plans remain accessible when the monthly cycle limit is reached; reviewing them consumes no new generation allowance. Follow verified changes, pending customer actions and actual impact windows in Results.
6. Preview the monthly report, optionally save PDF through browser printing, and explicitly select/approve a report recipient and schedule. Opening the page or saving a schedule sends no email immediately. Saved schedules and last successful sends are shown separately; SMTP acceptance still does not establish inbox delivery.

## Wallet boundaries

- Generation retains its existing per-cycle/monthly policy and plan reservation.
- A manual keyword operation uses one shared wallet cap across all its provider requests. Current price, plan allowance, market and inputs are bound to the reviewed quote. Changed quotes require review again. Mid-operation price increases cannot exceed the approved cap; a partial failure does not fabricate completed measurements or silently retry the paid request.
- Article briefs have a separate one-cycle cap, default zero, saved in the cycle snapshot before queue execution. Scheduled cycles use zero brief wallet overage. Plan allowance and cached briefs remain usable.
- Default/background/local DataForSEO calls use the saved `max_data_wallet_monthly` policy for their website, default zero. Plan units remain usable after the wallet cap is exhausted. New automatic charges carry `DataForSEO auto website:{id}` notes and share the UTC calendar-month cap across workers. Separately reviewed manual/brief charges carry a manual marker and use their own cap. Historical unscoped charges are preserved, not retroactively attributed to a website or added to the new automatic-policy total.
- Global admin DataForSEO provider budget and optional local/backlink provider switches remain independent. A configured provider does not prove credentials, provider credit or a successful measurement. AI-visibility provider billing has its own existing controls; this update's DataForSEO cap does not govern it.

## Deployment on the PLATFORM hosting

Back up the replaced platform files and database first. This archive is an incremental update on commit `3c269d2`; it is not a full application installation.

1. In the **platform** database, apply `database/migrations/2026_10_19_000001_seo_launch_prerequisites.sql` before replacing the application entrypoints. It only creates missing pricing, billing/service reservation, external-execution and report-schedule tables and inserts missing seed prices. Re-running it preserves configured prices, disabled prices and customer rows. It does not import test data or repair an incompatible pre-existing schema.
2. Upload every `app/`, `cron/` and `public_html/` file in this archive to the same relative path in the platform release. Include both entrypoints, `app/Config/project_classmap.php`, the new PHP services and `seo-service-readiness.js`. Use a staged release/maintenance window so the classmap cannot reference missing files mid-upload.
3. Clear application/PHP OPcache and any CDN/browser caches after the complete upload. Run a PHP syntax check and the normal Nginx configuration test before a service reload if configuration was touched separately. This package changes no Nginx configuration.
4. Open SEO Expert with the intended website selected. Confirm the stored-evidence card loads, keyword quote is free, and the background worker heartbeat is current. Verify that a mobile customer can reach the current saved plan and required step without starting another paid cycle.
5. Review and save existing automation policies if wallet overage is wanted for automatic/local data. Missing monthly policy defaults to zero. Keep it zero for the one-cycle test account unless a separate recurring allowance has been approved.

**No customer WordPress plugin or PHP connector replacement is needed for this update.** Tests and documentation are review artifacts and do not need to be uploaded to production web paths. Archive checksums are in `SHA256SUMS.txt`.

## Operator launch checks

- Confirm the publishing worker and real Cron schedule are active: ranking/enrichment, SEO Expert continuations, Google/GBP sync, decay, impact collection and report jobs. One recent worker heartbeat proves only that worker's heartbeat, not every Cron entry.
- Run the required regression suites with disposable databases and fake transports. The production bootstraps must select canonical `app/Services/Subscription/` classes even if an installed Composer classmap contains retired duplicate paths. No vendor map edit or price/allowance modification is needed.
- Validate real DataForSEO credentials, credit, global budget and a small reviewed customer measurement. Stop on an unknown network outcome and inspect saved evidence before retrying.
- Exercise one generation cycle under an explicit allowance and brief cap. Inspect the saved plan, unsupported-claim gate, FAQ/schema, themed publishing, internal links and sitemap. Reversible publication still requires a reviewed factual proposal and live verification.
- Configure SMTP and sending-domain SPF/DKIM/DMARC. Send one separately consented real report to a test recipient and check delivery. Do not claim delivery from a stored schedule or fake mailer test.
- Verify backups and restoration, role/tenant isolation, connection revocation/reconnect, and mobile usability before expanding the pilot.

## Limits that remain evidence-dependent

Search Console indexing requests do not guarantee indexing or rankings. Impact monitors require real 7/14/28-day windows and available GSC observations; an applied task is not proof of improvement or causality. Revenue requires actual mapped platform bookings/payments; external checkout revenue remains unverified without its adapter. Non-PHP custom sites need a developer adapter. Google Maps grids require a real confirmed business location; coordinates are never invented. Optional AI visibility, backlinks and outreach require real sources and their own approvals/usage controls. Customer facts, countries, products, currencies and permissions must never be copied from another account.

The offline test transcript is `docs/seo/seo-launch-test-output.txt`. Live deployment, successful paid-provider execution, mailbox delivery and eventual SEO outcomes must be recorded separately after they have actually happened.

## Provider-budget preflight and operator inspection

The live customer check after the first upload verified the new assets and APIs, but its demand measurement was blocked by the global provider budget before quota or wallet consumption. The operator confirmed a USD 1 global cap; spent/held figures still require the protected operator view. No new generation cycle was started without the prerequisite research.

A free quote now checks whether the global provider ledger permits a new USD 0.10 reservation and explains a known platform-side block before showing paid consent. This is an advisory snapshot, not a reservation or a promise that all later requests will fit: the atomic ledger still checks each real request under its global lock. Cached records and already-completed measurements remain distinct from new paid work. Customers receive availability/reason only, never global financial figures or another tenant's ledger.

The Super Admin settings page now shows the current UTC month, cap, actual recorded provider cost, additional held funds, remaining budget and pending/unknown request counts. Reading it makes no provider request, changes no budget and clears no reservation. Unknown outcomes must be reconciled with the provider before an operator decides whether to adjust a cap; this update never deletes or zeroes them. These are Tourfecto's recorded costs/reservations, not a real-time DataForSEO account balance. No new SQL is required beyond the initial launch migration.

## Reopening a rejected article without another paid cycle

If factual validation rejects an article, its original text is still retained in the saved plan. The owner can now see the exact flagged claim and choose **Reopen draft for correction — no new usage**. Recovery matches the original proposal in the same owned plan, checks the current company revision and market, and preserves the task ID plus its original manual-review record and source hash. It makes no provider/model call, reserves no allowance, debits no wallet and publishes nothing.

The recovered body, title, search description and FAQs use the existing editor. Unconfirmed claims continue to block approval and publication. Correcting the draft uses the same fact/FAQ/link checks as other reviewed drafts. Duplicate recovery requests return the existing draft and cannot overwrite subsequent edits. A failed evidence write rolls back the conversion. Plans containing no approved work now remain in the review stage even when an automation policy is active.

Deployment requires `app/Services/SeoExpert/SeoExpertService.php`, its new `SeoRejectedContentDraft.php` helper and `public_html/assets/js/seo-expert.js` together; no SQL or customer connector update is needed.

### First-request schema preparation

A fresh recovery request must prepare the existing SEO schema guard before opening the task/evidence transaction. Market ownership validation remains inside that transaction, but its runtime deployment fallback must not run there. The earlier journey fixture had already warmed the guard and missed this production-only ordering error; the regression now resets the guard before recovery, reproduces the original failure and verifies the fixed first-request path. This correction changes only `app/Services/SeoExpert/SeoExpertService.php` at runtime; no additional SQL, browser asset or customer connector update is required after 8766e0f. The focused rerun and original failing reproduction are retained in `seo-first-request-test-output.txt`; the earlier broader run remains separately recorded.
