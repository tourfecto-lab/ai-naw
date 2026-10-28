# Confirm business facts independently of Maps coordinates

A connected Google Business profile can expose a genuine name, phone, address and website while returning no latitude/longitude. Local SEO previously refused to confirm the profile, preventing citation/NAP comparison as well as Maps measurement.

Profile confirmation now saves the actual Google facts and the customer's approved directory URLs even when coordinates are absent. The response explicitly reports `maps_grid_ready=false`, and the customer sees that name/phone/address comparison is available while a Maps grid still needs confirmed coordinates. This does not classify a business as a storefront or service-area business or create an address/location claim.

`LocalRankGridService::gridForProfile` checks that both coordinates are explicitly numeric and valid. Starting a grid uses this guard before queueing or provider calls. Missing/null values cannot become a fabricated grid at 0,0; an explicitly supplied genuine 0,0 remains valid. Existing grid generation, plan limits, paid consent, queue idempotency and provider budget checks remain active.

## Upload to tourfecto.pro

The previous JSON request/GBP reconnect fix (12c39ea) must already be installed; this was confirmed during the customer audit.

Back up and replace these three platform files, preserving their folders:

- `app/Controllers/LocalSeoController.php`
- `app/Services/GoogleBusiness/LocalRankGridService.php`
- `public_html/assets/js/local-seo.js`

Reload PHP/OPcache if the hosting caches changed PHP classes, and restart persistent queue workers through the existing hosting process. Purge the changed JavaScript asset if cached, then refresh the browser with Ctrl+F5. No SQL migration, Nginx change or customer connector/plugin update is needed. Tests, this document and the full test log are included for review and do not need deployment.

To verify, open Local SEO, select the website, review the connected Google Business profile and confirm ownership of its facts/directory profiles. When Google supplies no coordinates, the confirmation should succeed with the clear Maps prerequisite message. Run the name/phone/address comparison; missing or ambiguous source fields remain unknown. Only start a paid Maps grid after real coordinates and paid usage are confirmed.

## Offline validation

- `local_nap_without_coordinates_test.php`: actual profile controller with fake Google/Database dependencies; 19 assertions. It reproduces the original rejection before the fix and verifies factual persistence, missing/invalid coordinates, explicit zero coordinates, confirmation, connection and tenant guards.
- `seo_phase5_database_test.php`: 40 assertions, including actual guarded database checks that a coordinate-less confirmed NAP profile creates no grid run, worker job or provider call. Valid grids still make exactly the expected 50 requests with fake transport.
- `seo_customer_json_transport_browser_test.cjs`: 23 assertions using real Panel/browser requests, including the new explanation for a confirmed NAP profile without Maps coordinates and no automatic grid creation.

All 59 validation groups were run; 57 pass. Phases 1–6, connector, xpay and fx_bank pass. Existing unchanged baseline failures remain plans_studio (40/47) and plan_gate (19/24). PHP lint checks 1284 files. Full commands and output are in `nap-coordinate-independence-test-output.txt`. No production Google/provider requests are made by these tests.
