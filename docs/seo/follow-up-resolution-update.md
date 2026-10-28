# Review decisions and exact article impact comparisons

Base: `26adab6`, branch `seo/content-publication-polish`.

An unapplied proposal previously remained customer work even after a reviewer decided the current page was better. Separately, a PHP article could switch from `/guides/slug` to `/?tf_article=slug` during publication verification while its three impact monitors kept measuring the original, unavailable URL. Query routes were rejected by the measurement rules, and the automatic backfill skipped tasks with three existing mappings.

## Resulting behavior

- Unapplied title/description proposals and operational-page indexing suggestions can record **Keep the current page**, with a reason, date and reviewer. This excludes the suggestion from execution/customer work without changing its stored status, verification date, original evidence or proposed value. It does not claim a successful edit or indexing result. **Reopen** restores review and retains the prior decision in a bounded history. Approval still rejects excluded proposals. Applied, published and verified tasks cannot use this review action. Existing site content, robots and canonical settings are untouched by review.
- Existing CSRF-protected task completion routing accepts the versioned review operation. Ownership, run state, eligibility and plan version are checked in a transaction. A generic completion request cannot erase a keep-current choice. No publication job, cycle usage or impact measurement is created merely by recording a review decision.
- Impact page identity accepts the connector's exact `tf_article=slug` query route, scoped to the owned website. Other query parameters, repeated parameters, encoded/array values, credentials and fragments remain rejected. Different articles and the homepage have different hashes. GSC retains the complete exact URL; GA4 uses `landingPagePlusQueryString` for query routes and its existing `landingPage` filter for clean URLs. A query article is not measured as all homepage traffic. Booking landing identities can retain the supported article route, without creating or enabling a booking connection.
- Automatic backfill also detects existing content mappings whose URL differs from the current publication URL. It repairs the three 7/14/28-day mappings and change event URL, preserving the original recorded change day. The repair creates no duplicate mappings or pending jobs. Comparisons still referenced by another task remain active. Unreferenced old automatic comparisons remain stored as `superseded`, with their historical results; their queued collection becomes a no-op. They cannot be refreshed as if still current.
- Queued comparisons now expose and display their equal before/after windows, the window length and due date before metrics exist. The change day is excluded and collection waits for the full window plus three days for provider data. Before/after remains observational, not proof of causation. Older historical comparisons remain readable beyond the one-year creation limit.

## Deploy on the platform

1. Back up the corresponding files on `tourfecto.pro`.
2. Extract the ZIP outside the website document root. Copy its `app/` and `public_html/` changes into the matching platform paths. Serve the updated `seo-expert.js` and `seo-impact.js` from the existing assets directory; do not create another nested public directory accidentally.
3. Reload the platform PHP service/OPcache. Restart any persistent queue workers using the existing hosting procedure. No customer WordPress/PHP connector reinstall, database migration or Nginx change is needed.
4. The existing `seo_auto_impact` scheduled job repairs stale mappings on its next run (registered every ten minutes). The existing **Recheck public pages** verification also invokes the same backfill. Do not create a new paid cycle or republish the article to repair monitoring.
5. In **Measure edit outcomes**, reload the dashboard. Confirm three current comparisons for the exact published `?tf_article=` URL, the original change date and distinct 7/14/28-day schedules. The old `/guides/` records should be labelled historical/superseded when they are no longer shared.
6. In the relevant task card, open **Review decision without editing the page**, enter the factual reason, and choose **Keep the current page**. It moves to excluded history, not verified results. Use **Reopen proposal for review** to reverse the decision.

The scheduling and worker processes still have to run on the host. Queued schedules are not evidence of future collection or improved clicks/bookings. A new deployment requires a live check before declaring the production repair complete.

## Validation

`tests/Automation/seo_follow_up_resolution_database_test.php` exercises actual review, approval, report projection, mapping repair, shared/historical comparisons, queue idempotency, due dates, ownership and booking identity against the guarded disposable MariaDB fixture. `seo_impact_query_route_test.php` uses fake Google transports to verify exact GSC/GA4 requests, supported route boundaries and data-latency dates. `seo_follow_up_resolution_browser_test.cjs` checks the review/reopen controls, versioned CSRF payload, preserved rollback, counts/filters and queued/superseded monitor UI.

The complete phase 1–6, connector, plans, xpay, plan_gate and fx_bank checks are captured with per-command exit codes in `follow-up-resolution-test-output.txt`. Existing unrelated billing failures are reported separately. No real email, provider purchase, booking or publication is performed by these tests. Customer sessions and raw production responses are excluded from the repository and ZIP.

Result: **54/56 groups passed**. New checks: **30 database + 17 route/provider + 16 browser assertions passed**. All 1,282 PHP files passed lint. Known billing failures remain `plans_studio` (40/47) and `plan_gate` (19/24), matching the prior baseline. Required phase 1–6, connector, xpay and fx_bank groups passed.
