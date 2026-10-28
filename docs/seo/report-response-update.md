# Keep the monthly report HTML response out of API fall-through

Base db70567, branch seo/content-publication-polish.

Live deployment verification confirmed the content-journey UI categories and intact task/Google evidence. The report-language exclusion also reached production. A fresh request then exposed a second integration issue: LocalSeoController::report printed a complete HTML document and returned void. The Router converted that return into an empty _raw_text response; with PHP output buffering enabled and no UI translation buffer, send_response could replace the document Content-Type with text/plain. The browser displayed HTML source instead of rendering the Arabic report. Error HTTP status could likewise be replaced. The initial regression test exercised the translation hook but did not exercise this controller/dispatcher interaction.

The report controller now ends the response after printing the success or error document, and ends unauthenticated access with its existing 401 status. This matches the platform's other complete-document controllers. It does not change report assembly, authentication policy, site ownership, next-step categories, Google data, schedules, email delivery, billing or customer-site content. The exact Arabic-report exclusion from UI translation remains required and is included with this archive.

The strengthened regression test runs the actual LocalSeoController::report under a real local PHP HTTP server with output_buffering=4096. Data/market services are fakes. It models the dispatcher's post-controller _raw_text fall-through, which must never be reached for this complete response. It checks exact report bytes and HTML Content-Type for English/Arabic account UI via direct and bridge routes, preserves 503 and 401 statuses, and keeps neighboring UI translations unchanged. All 15 assertions pass.

Upload the archive's app directory to PLATFORM hosting (tourfecto.pro), preserving paths after backup. The two runtime files are:

- app/Controllers/LocalSeoController.php — additional response completion correction;
- app/Helpers/ui_autotranslate.php — the report-language exclusion already supplied in db70567, included for a coherent deployment.

Reload platform PHP OPcache and reopen the report in a new tab. No SQL, customer connector or Nginx changes are required. Tests/docs do not need uploading. Preparing this archive does not deploy it. After deployment, verify Content-Type text/html, html lang=ar and dir=rtl under an English account UI, plus the current Google follow-up/excluded/customer-review sections.

All 53 suite groups ran: 51 pass. Known baseline billing failures remain plans_studio (40/47) and plan_gate (19/24). All phase 1–6, research, connector, content-journey, xpay and fx_bank suites pass. PHP lint checks 1280 files. Full output: report-response-test-output.txt. No paid production cycle, content publication or real email was used for this correction.

This update supersedes the one-file deployment instruction in report-language-update.md. Rollback restores both backed-up runtime files together and reloads PHP; no stored data needs rollback.
