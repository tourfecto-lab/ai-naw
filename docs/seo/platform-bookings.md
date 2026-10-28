# Native platform booking measurement

SEO Expert → Results now reads the platform's real booking payment and refund ledgers. A customer chooses the products belonging to the selected website once; later dashboard loads calculate the figures automatically. Search, Analytics, automatic impact monitors and optional WooCommerce receipts remain available.

`crm_products.website_id` identifies a Builder website, not an SEO website. Matching those numeric IDs would silently assign the wrong sales. The additive `seo_platform_booking_products` table records an explicitly reviewed selection, checks account ownership and permits one measurement website per product. Concurrent selections are serialized per customer. A rejected selection rolls back without deleting the previous selection. Search and pagination retain unsaved choices; switching websites clears choices, consent and prior figures and rejects late responses.

## What the figures mean

Two equal cohorts cover the last 28 complete UTC booking-creation days and the preceding 28 days. Their values are the **current** captured payments less successful refunds for those bookings, not payment-date cash flow or historical snapshots. A booking with several captured payments counts once. Successful refunds reduce net amounts; pending/processing refunds are displayed separately. Cancellation alone does not imply a refund. Invalid refund states are excluded and counted for review rather than filled with invented values.

Eligible captured transactions use the existing `stripe`, `stripe_operator`, `paymob` and `wallet` gateway identifiers. Failed/pending attempts, manual payments and unrelated wallet top-ups are excluded. A manually confirmed booking alone does not establish payment. Currency totals remain separate; no exchange rate is assumed. An unmapped site is unconfigured, not zero sales. These product-level figures do not identify a landing page or traffic channel and do not establish that SEO caused revenue. They are separate from per-page impact comparisons and the monthly SEO report.

No booking, product price, payment, refund, wallet, provider integration or financial event is changed by this feature. Only the reviewed measurement mapping is saved.

## Upload on tourfecto.pro

Back up the existing files and platform database. Extract the update archive locally and replace these files at the same paths in the **platform application**, preserving the existing directory layout:

- `app/Config/project_classmap.php`
- `app/Controllers/SeoImpactController.php`
- `app/Services/SeoImpact/SeoImpactService.php`
- `app/Services/SeoImpact/SeoPlatformBookingService.php` (new)
- `app/routes/api.php`
- `public_html/assets/js/seo-impact.js`

Run `database/migrations/2026_10_18_000001_seo_platform_booking_products.sql` once against the **tourfecto.pro application database**, using its database tool/phpMyAdmin SQL import. The file creates one additional mapping table and can be rerun safely; it does not alter financial tables. Do not run it against the customer site's database. No credentials are included in the archive. Before the table exists, the native booking section explains that measurement is unavailable and other results remain usable.

Reload PHP/OPcache and any persistent application workers, then refresh the browser asset cache. This update builds on commit `794c17c` and the earlier impact measurement schema. It requires no Nginx, WordPress plugin or `tourfecto-connect.php` change. Included tests, documentation and test output are review material and do not need production upload.

## Customer check after deployment

1. Sign in to the intended customer account, choose the correct website and open SEO Expert → Results.
2. Open “اختَر الرحلات الخاصة بهذا الموقع مرة واحدة”, search/select only the real products for that website, confirm the selection and save. Products assigned to another website are locked; remove the old measurement assignment before moving one.
3. Check the two displayed date windows and compare captured/refunded/pending amounts by currency with existing real platform records. Do not create a fake booking or charge to test this display.
4. Reload Results to confirm that the saved mapping is retained. Switching accounts/sites must not reveal the previous site's selection or amounts. A selected product with no eligible captured payments can legitimately report zero bookings; an unconfigured site cannot.

## Validation

The new database suite executes the actual service SQL against a guarded disposable MariaDB database: **23 assertions** for ownership, distinct Builder/SEO IDs, reviewed/idempotent/atomic mapping, payment/refund aggregation, duplicate booking prevention, equal periods, honest attribution and preservation of all financial records. The browser suite uses the actual Panel and impact scripts with offline responses: **13 assertions** for escaped names, locked assignments, consent, preserved search/pagination choices, CSRF JSON transport, currencies, pending refunds, cross-site races, mobile layout and absence of financial/provider writes.

Full run: **67 groups, 65 pass**. Required phases 1–6, connector, xpay and fx_bank pass; known unrelated baseline failures remain in plans_studio (40/47) and plan_gate (19/24). PHP lint checks 1,288 files without syntax errors. Actual output is included in `docs/seo/platform-bookings-test-output.txt`. These tests use fixtures and establish implementation behavior; this update still needs upload and a fresh production check before claiming native booking measurement works for the live customer account.
