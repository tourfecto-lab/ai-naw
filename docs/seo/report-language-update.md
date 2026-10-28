# Preserve the Arabic monthly report document

Base 02b51fa, branch seo/content-publication-polish.

The deployed content-journey UI and next-step categories worked. A real authenticated HTML report check exposed an integration defect outside the report renderer: when the account UI language was English, the front controller's automatic UI translation buffer rewrote the Arabic report's html language/direction to en/ltr and could translate known report phrases. Testing SeoMonthlyReportService::html alone did not exercise that buffer.

The exact normalized route /api/local-seo/report is now excluded from platform UI translation, alongside the helper's existing exclusions for customer content and exports. This printable document keeps its own explicit Arabic language, RTL direction and content regardless of account UI language. Both normal routing and the hosting-compatible API bridge normalize to that route. Neighboring local-SEO interface/API routes retain their previous translation behavior. Authentication, ownership, report assembly, JSON APIs, customer connector, wallet and stored task data are unchanged.

Deployment: upload only app/Helpers/ui_autotranslate.php from the ZIP to PLATFORM hosting (tourfecto.pro), preserving the path after backing up that file. Reload platform PHP OPcache. The content-journey runtime files from 02b51fa are already the required base; this is a one-file runtime correction. No SQL, customer connector replacement or Nginx change is required. Tests/docs do not need uploading to production. Preparing the archive does not deploy the fix.

Regression validation uses a real local PHP HTTP server and the production translation hook. The report bytes must equal the renderer output under English and Arabic UI settings, via both direct routing and the API bridge. Normal English UI still translates its document attributes; normal Arabic UI stays RTL; a similarly named route is not excluded. Nine assertions pass.

All 53 suite groups ran: 51 pass. The two known baseline billing failures remain plans_studio (40/47) and plan_gate (19/24). All phase 1–6, research, connector, content-journey, xpay and fx_bank checks pass. PHP lint: 1280 files. Full output is in report-language-test-output.txt. Tests use guarded disposable databases and fake provider transports. No live email or new paid generation was sent.

After uploading, reopen the authenticated monthly report with an English account UI. Confirm the document still has lang=ar and dir=rtl and the current Google follow-up/excluded/customer-review sections remain present. This does not refresh Google indexing observations or prove ranking improvements.

Rollback: restore the backed-up helper and reload PHP. No data rollback is needed.
