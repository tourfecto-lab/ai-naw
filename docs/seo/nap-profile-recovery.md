# Recover Local SEO profile checks after a saved GBP sync error

A business profile could load successfully from Google while the one-click Local SEO confirmation returned `Connect Google Business first`. In the current customer audit, the stored connection had an error from another GBP operation, but a fresh profile request succeeded. Reconnecting the customer was not justified by that saved flag alone.

The confirmation and citation comparison now retry saved `connected` and `error` records using the existing owner/site-scoped connection selector. Both OAuth token validation and a live Google profile response must succeed. Disconnected, pending and absent records remain blocked. No cached profile substitutes for a failed Google request, no global connection status is changed, and comparison still rejects a changed confirmed branch. Coordinates and paid measurement controls are unchanged.

## Hosting update

This small patch follows the guided customer journey update `e491ef7`, already uploaded by the owner. On **tourfecto.pro**, replace these files at their corresponding application paths:

- `app/Controllers/LocalSeoController.php`
- `app/Services/GoogleBusiness/NapConsistencyService.php`

Reload the PHP service/OPcache or persistent workers if required by the hosting configuration. This patch requires no SQL, Nginx, customer connector or JavaScript changes. The ZIP also includes an offline regression and this documentation; test files are for development and need not be uploaded to production.

Open `/local-seo?website_id=<your-own-site-id>`, review the actual Google Business facts, select the confirmation box and click **تأكيد البيانات وفحصها** once. A completed result requires both the profile save and the automatic citation comparison to succeed. Missing fields remain unknown. Do not start paid Maps/backlink checks as part of this verification.

## Verification

The expanded offline test `tests/Automation/local_nap_without_coordinates_test.php` reproduced the saved-error confirmation failure before the production change and passes **35 assertions** afterward. It runs actual controller and citation code with fake Database, token and Google responses, without SQLite or network calls. It covers successful retry, token/profile failures, inactive connections, tenant scope, unchanged facts, missing coordinates, unavailable source fields and confirmed branch identity.

The full required suites and existing customer workflow regressions are recorded in `docs/seo/nap-profile-recovery-test-output.txt`. Known unrelated billing baseline failures remain in plans_studio (40/47) and plan_gate (19/24).

The live upload audit verified all six updated JS/CSS files byte-for-byte, the four-step overview and automatic opening of the existing plan. The live NAP attempt exposed the saved-error guard and did not save a profile or run the comparison. Wallet balance remained unchanged and no paid Maps/link-gap operation was called. This PHP recovery patch must be deployed and the live action rechecked before claiming that the complete NAP flow succeeds.
