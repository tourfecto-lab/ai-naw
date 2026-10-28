# Guided SEO and Local SEO customer journey

Customers previously had to find the current cycle, interpret an optional missing WordPress connection, and separately save a GBP profile, compare citations and refresh results. They now have explicit next actions, a four-step SEO overview, automatic progress, and one reviewed confirmation that saves local business facts and starts their comparison.

## Customer and platform responsibilities

The customer confirms their real business facts and cost limits, grants account permissions, reviews the proposed changes and approves selected work. The platform collects available evidence, prepares the plan, executes approved tasks, verifies public changes, schedules outcome measurements and updates progress. Work outside connected permissions, missing facts and measured-data prerequisites remain explicit. Visibility/indexing/ranking improvements are not promised by a successful technical check.

- The SEO overview shows four steps with links to facts/costs, connections, the current plan and results. The next action reflects a running cycle, proposals needing review, customer tasks or available results. Opening Plan automatically opens the latest existing cycle; it never creates another cycle.
- Working cycles continue to refresh while the page is open. Polling pauses for unsaved customer text or approval selection, including after focus leaves the field. Late responses are ignored when another site/action replaces the current state. Finishing a cycle refreshes its stored overview without rerunning live connection probes.
- The three site/measurement connections have their own readiness summary. Missing optional WordPress login is not treated as a failed custom-PHP setup. Saved WordPress connections needing review remain visible, and all connection controls are still accessible. WordPress editor and Traveler draft tabs are labeled explicitly; SEO plan approval remains the execution entry point.
- Local SEO displays the connected Google profile for review and restores saved directory URLs. One confirmed action saves the profile and compares it against the site/directories. It does not create a paid Maps grid or backlink request. Results explain observed values, mismatches and unknown fields; a failed comparison preserves saved facts and offers a comparison-only retry.
- Local data reads are restricted to the selected customer/site and expose only displayable profile fields. Changing sites clears prior confirmation, paid consent and comparison results; late responses cannot populate the new site.
- Optional Maps measurement keeps explicit paid consent, real-coordinate validation, plan limits and provider-budget controls. Active grid results refresh automatically. Reports remain printable, and email delivery still needs an enabled schedule.

All existing controls and server authorization, CSRF, source-revision, billing and approval checks remain in place. Automatic work does not infer physical premises, invent coordinates/facts, approve content or send customer messages.

## Deploy to the platform

The combined ZIP includes the earlier JSON-header, GBP reconnect-selection and coordinate-independent NAP fixes. It can replace the previous NAP patch; install this combined bundle once.

Back up and replace the files under the archive's `app` and `public_html` folders on **tourfecto.pro**, preserving their paths. This bundle changes these runtime files:

- `app/Controllers/LocalSeoController.php`
- `app/Services/GoogleBusiness/GbpSyncService.php`
- `app/Services/GoogleBusiness/LocalRankGridService.php`
- `public_html/assets/js/local-seo.js`
- `public_html/assets/js/international-seo.js`
- `public_html/assets/js/seo-expert.js`
- `public_html/assets/js/seo-workspace.js`
- `public_html/assets/css/local-seo.css`
- `public_html/assets/css/seo-workspace.css`

Reload PHP/OPcache if changed PHP files are cached, restart persistent workers through the existing hosting process as applicable, purge changed JS/CSS assets if cached, then reload the browser. No SQL migration, Nginx edits or customer connector/plugin update is required. Tests and documentation are review files and do not need to be uploaded to production.

## Verification

The production controller markup, actual Panel helper and customer scripts are exercised in an offline browser. The guided-journey suite covers current-cycle opening, automatic progress, preserved unsaved input, optional WordPress controls, no automatic approval/payment, real-data preview, UTF-8 Arabic instructions, one-click confirmation/comparison, partial failures, retries, escaped facts, cross-site late responses and mobile layout. Browser visual checks use the actual panel styles without downloading external fonts.

The profile controller's fake-dependency tests additionally verify tenant-scoped saved profile reads and that internal identity/coordinate fields are not returned in the display profile. Prior regression suites retain JSON transport, connection selection, null-coordinate protection, all feature phases and billing coverage. Full commands and outcomes are retained in `guided-customer-journey-test-output.txt`; unchanged baseline billing failures are reported separately there.

Final validation: 61 groups executed, 59 pass. The guided browser suite passes 26 assertions; profile tests 23, real JSON transport tests 23, and GBP connection selection tests 8. Required phases 1–6, connector, xpay and fx_bank pass. Existing baseline failures remain plans_studio (40/47) and plan_gate (19/24). PHP lint checks 1284 files and the diff whitespace check passes.

After deployment, select the correct website, review the four SEO steps and open its current plan. Then open Local SEO, review the displayed Google facts and directory URLs, confirm them and inspect the comparison. This is a free data comparison; run optional paid checks only after reviewing their own usage and prerequisites. Production deployment and live completion of the new local flow still need verification after file upload.
