# Conservative address evidence and current citation tasks

The live customer journey after `4c09a80` succeeded: a single reviewed confirmation saved the current Google Business facts and automatically completed citation comparison. Phone and website matched. The website's Organization schema supplied only a country for its address, which the previous comparator incorrectly labeled as a conflict with the complete GBP address. The observed business names also differed and remain a review item; this patch does not rename a business or invent an address.

## Changes

- Country-only structured addresses stay unknown. Agreeing partial structured fields remain unknown when other supplied fields are missing, while explicit observed street/postal conflicts still count as mismatches.
- A country-only schema no longer prevents extraction of a literal footer address. Mixed structured and unstructured addresses are handled conservatively rather than asserting a conflict from incomparable field sets.
- Fresh successful observations supersede older changed task evidence, preserving history and one actionable task per source. Unknown replacement fields are not claimed to be resolved. Fully observed matching data resolves current issues. A failed source fetch preserves the last observed task evidence.
- The customer data endpoint returns pending citation tasks; superseded historical rows remain in the database. All updates and reads keep the existing website/user scope. Repeated evidence refresh updates its observation timestamp without adding duplicates.

## Hosting update

On **tourfecto.pro**, replace the two application files from the ZIP at the same relative paths:

- `app/Controllers/LocalSeoController.php`
- `app/Services/GoogleBusiness/NapConsistencyService.php`

This follows the already-uploaded guided journey update `e491ef7` and includes the PHP recovery behavior of `4c09a80`. Reload PHP/OPcache or persistent workers as required by the hosting setup. No SQL, Nginx, JavaScript or customer connector changes are required. Tests and documentation in the ZIP are optional on hosting and can stay in development.

After deployment, open Local SEO for the selected customer-owned site and use **إعادة مقارنة البيانات المحفوظة**. Profile confirmation already succeeded in the live audit, so no reconnect or new save is necessary. Country-only address evidence should show unknown, the current observed name difference should remain for review, and obsolete evidence should not duplicate the pending task. Do not start paid Maps/backlink tools to verify this update.

## Validation and limits

The new offline parser/comparator regression reproduced the country-only false conflict before the change and passes 13 assertions afterward. The actual guarded database Phase 5 suite passes 49 assertions, including changed evidence, repeated checks, unknown fields, tenant isolation, retained history, source failure and verified resolution. The existing controller/NAP fake regression passes 35 assertions.

Full suite output is in `docs/seo/nap-address-evidence-test-output.txt`: 62 groups, 60 pass. Required phases 1–6, connector, xpay, fx_bank and PHP lint (1,285 files) pass. Known unrelated billing baseline failures remain plans_studio (40/47) and plan_gate (19/24).

Live verification of the previous upload proved the save-plus-comparison journey with no wallet change, zero Maps runs and no paid link-gap call. Only the customer's website was checked; no optional directory URLs had been entered. Maps remains unavailable until there are confirmed real coordinates, and no location was guessed. This new evidence-classification patch is not yet verified on production; upload and recheck it before claiming the address warning is corrected there.
