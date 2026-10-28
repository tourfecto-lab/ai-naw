# Current follow-up beside historical monthly metrics

Base: `8a80e30`, branch `seo/content-publication-polish`.

The report's headings describe Google follow-up, excluded proposals and next actions **at report preparation**. Its task query nevertheless required `created_at <= end of the previous month`. Consequently a website whose pending work was created this month showed three empty follow-up sections even though the customer plan contained current tasks.

The pending-candidate query now selects the owned website's current pending/review tasks, retaining its existing 1,000-candidate and ten-items-per-section bounds. Historical GSC, rankings, verified tasks and impact queries keep their existing month boundaries. The change does not modify tasks, decisions, content, billing or the queue.

## Platform update

1. Back up `app/Services/Seo/SeoMonthlyReportService.php` on `tourfecto.pro`.
2. Replace it with the ZIP's file at the same path.
3. Reload PHP/OPcache using the hosting panel. Persistent processes that generate reports should load the updated class through the normal restart procedure.
4. Reopen the monthly report. Confirm that the Google-follow-up, excluded and customer-review sections match current task categories; the metrics heading must still show the previous calendar month.

Only one application file changes at runtime. No SQL, customer connector update, JavaScript deployment, paid cycle or article republication is required. The ZIP includes regression tests and the full test output for review; those files do not have to be uploaded to the website document root.

## Tests

The actual report query is exercised against the guarded disposable database with this-month pending tasks, a keep-current decision and two customers' new Google observations. Assertions require these records in the current follow-up sections, retain the review reason, enforce tenant separation and keep this-month verified work out of the previous month's historical results. This closes the gap in the earlier journey check, which advanced its report clock to next month and therefore did not expose the boundary error.

All phase 1–6, connector, plans, xpay, plan_gate and fx_bank suites are rerun in `current-report-follow-up-test-output.txt`. Provider/model responses and mail delivery are fake; no production email, purchase or publication is sent. Production account data is not included.

Result: **54/56 groups passed**, including all required phase 1–6, connector, xpay and fx_bank groups. The review/impact database suite passes 32 assertions, and the two-customer content journey passes 56 assertions, including five added checks for this report boundary. PHP lint passes all 1,282 files. Known billing failures remain `plans_studio` (40/47) and `plan_gate` (19/24).
