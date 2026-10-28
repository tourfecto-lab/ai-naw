# Generate breadcrumbs from verified page evidence

Base f110b0e, branch seo/content-publication-polish.

A policy URL such as /page/privacy previously generated Home > Page > Privacy, including a fabricated /page/ parent. That directory can return 404. The generator now includes intermediate parents only when the crawl observed a successful, non-maintenance HTTP 200 page on the same origin. The final response URL and the inspected heading/title supply the parent link and label. Neither an inferred directory nor a slash added to a real URL counts as evidence.

PageContext retains the observed HTTP status. SeoExpertService passes the existing inspected page records to SeoSchemaBuilder, so no additional network or paid provider calls are added. Missing, failed, cross-origin, query, fragment and credential-bearing parents are omitted. Old snapshots without an HTTP status conservatively produce Home > Current page. Breadcrumb positions remain consecutive, and long paths retain the current page within the existing six-item limit. Existing customer BreadcrumbList schema and single-trip schema behavior are preserved.

Install on PLATFORM hosting (tourfecto.pro): back up and upload the archive's app directory preserving paths. Deploy all three runtime files together, then restart persistent PHP workers and reload platform OPcache:

- app/Services/AutoFix/PageContext.php
- app/Services/SeoExpert/SeoExpertService.php
- app/Services/SeoExpert/SeoSchemaBuilder.php

No SQL migration, customer connector replacement or JavaScript update is required. The tests/docs folders are for review and do not need uploading to production hosting.

This changes newly generated proposals only. It does not rewrite, approve, activate or remove old tasks, current rules or public pages. In particular, the four previously withheld policy proposals retain their review history. Do not approve their old /page/ links on the assumption that uploading this archive rewrites them. This update does not start a new cycle or consume the customer's allowance.

Verification: 26 offline breadcrumb assertions cover missing/404 parents, exact URLs and labels, slash preservation, same-origin checks, maintenance, query/fragment/credential rejection, consecutive positions, current-page retention, existing breadcrumbs, single-trip schema, and HTTP evidence from the page reader. The actual database planning suite passes 26 assertions, including saving all four corrected policy proposals and retaining the verified /tours parent for a destination page. These database checks use a guarded disposable fixture, not production customer data.

All 40 suite groups were run after the implementation: 38 pass. Existing baseline failures remain plans_studio (40/47) and plan_gate (19/24). Phase 1–6, research, connector, xpay and fx_bank checks pass. PHP lint checks 1269 files. Full output is in breadcrumb-evidence-test-output.txt. The tests use fake transports or existing guarded fixtures; no live customer/provider generation is performed.

After deployment, review a future draft: /page/privacy must omit an unverified /page/ parent, while /tours/luxor may include the exact successfully inspected /tours URL. Existing approved articles and metadata should remain available. Local verification and an uploaded archive do not establish that production workers are running the change.

Rollback: restore the three backed-up runtime files together and restart workers/OPcache. No database rollback is required.
