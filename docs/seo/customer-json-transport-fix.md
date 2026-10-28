# Local and international SEO requests and GBP reconnect selection

Local SEO write actions displayed `Website not found` even with the correct website selected. Both the Local SEO and International SEO clients serialized their bodies as JSON without setting `Content-Type: application/json`. PHP treated the browser's `text/plain` request as form input, so the selected website, source confirmation and other fields were missing.

Both clients now explicitly set the JSON content type. Requests still use the existing Panel helper, authentication, CSRF header, API route bridge and server ownership checks. Existing customer facts, consent requirements and features are preserved.

The customer journey also exposed a stale GBP connection record masking a newer connected record. `GbpSyncService::findConnection` now selects the newest connected record for the same user, website and platform. If none is connected, it returns the latest historical state for existing reconnect guidance. It preserves every saved connection and never uses another tenant's records.

## Upload to the platform hosting

1. Back up the three existing files.
2. Extract the ZIP and replace these files in the **Tourfecto platform**, preserving the folders:
   - `public_html/assets/js/local-seo.js`
   - `public_html/assets/js/international-seo.js`
   - `app/Services/GoogleBusiness/GbpSyncService.php`
3. Reload the browser with Ctrl+F5. If an asset/CDN cache is enabled, purge these two JavaScript URLs.
4. Reload the platform PHP service/OPcache if it caches changed PHP code. Restart persistent queue workers through the normal hosting process if they keep PHP classes loaded.
5. Select the website and retry the intended action. Complete its actual prerequisites: Local SEO requires a confirmed GBP profile, Maps measurements require confirmed coordinates and paid consent, and sitemap submission requires the separate Google write permission.

This patch does not require a database migration, Nginx changes or a customer connector/plugin replacement. The ZIP also includes this document, tests and their output; only the three files above are runtime updates. Missing GBP coordinates remain a separate configuration prerequisite; this patch does not invent them or start paid measurements.

## Verification

`tests/Automation/seo_customer_json_transport_browser_test.cjs` uses real browser fetch, production controller markup and the actual `Panel.fetchJSON` helper. Its fake HTTP endpoint follows `Controller::parseInput`: only an application/json body is decoded. Before the fix, the first NAP assertion fails with the same missing-website behavior observed live. After the fix, 22 assertions pass, including selected-site scope, JSON fields, CSRF, API routing, paid consent, human review, sitemap controls and no write retries. No real provider calls or customer data are used.

Run with Node and Playwright installed:

```sh
node tests/Automation/seo_customer_json_transport_browser_test.cjs
```

Set `PLAYWRIGHT_MODULE_PATH` if Playwright is installed outside the local Node resolution path. The test uses `/usr/bin/chromium`.

`tests/Automation/gbp_connection_selection_database_test.php` runs actual Model queries against the guarded disposable database. Its first assertion fails before the selection fix and all 8 pass after it. It covers older failed records, multiple connected records, later unsuccessful reconnects, tenant/site/platform isolation, fallback and retained history. It does not refresh tokens or call Google.

All 58 validation groups were run; 56 pass. Phases 1–6, PHP connector, xpay and fx_bank pass. Existing baseline failures remain in plans_studio (40/47) and plan_gate (19/24); these billing suites are unchanged. The whitespace check was rerun successfully after the generated log was finalized. Full commands and output are retained in `customer-json-transport-test-output.txt`.
