# Refresh an old unpublished breadcrumb proposal without another cycle

Base 0c33186, branch seo/content-publication-polish.

The verified-parent generator fixes new proposals, but older withheld breadcrumb tasks could not be edited or regenerated. There is now a dedicated review action for a pending or needs_client metadata proposal containing only one BreadcrumbList. The action reads the current public page, refreshes a bounded set of known parent pages, and rebuilds the breadcrumb on the server. It uses neither AI nor a new generation cycle.

The existing edit endpoint accepts {refresh_breadcrumbs:true,version:N}. Client-supplied schema is ignored on this path. Eligibility requires an owned, never-activated task with no published URL, external post or previous applied task. Existing business schema, including business types nested in the old proposal, is excluded. The target must be an exact live HTTP 200 marketing URL on the selected website, with unchanged public schema and current confirmed business facts. These checks run again before committing the updated proposal/version. A concurrent plan edit cannot be overwritten after network reads.

The updated task becomes pending and is_active remains false. The prior value and review evidence are retained. The run remains draft/completed; no apply job, new run, generation allowance or automation activation is created. Publication still needs the normal review/approval step. Existing published tasks, page content, rules and article metadata are untouched.

On the task card, eligible proposals expose Refresh breadcrumb proposal / أعد بناء مسار التنقل للمراجعة. Unsaved edits elsewhere in the plan block refreshing before any request. A failed refresh keeps the current proposal visible for review. If the public schema or confirmed facts changed, review that change instead of forcing this old proposal through.

Install on PLATFORM hosting (tourfecto.pro): back up and upload app and public_html from the archive preserving paths. Restart persistent PHP workers/reload platform OPcache and refresh the SEO Expert page. The archive includes the previous generator changes for a coherent deployment:

- app/Services/AutoFix/PageContext.php
- app/Services/SeoExpert/SeoSchemaBuilder.php
- app/Services/SeoExpert/SeoExpertService.php
- public_html/assets/js/seo-expert.js

No SQL migration or customer connector replacement is required. Tests/docs do not need uploading to production. Before this change, the production seo-expert.js was fetched read-only and matched the repository file byte-for-byte; this archive preserves its prior features and adds the scoped review action.

Verification: the new database suite passes 22 assertions covering ownership, stale versions, active/verified tasks, mixed/nested business schema, cross-site URLs, changed facts, unavailable/maintenance/redirected pages, changed schema, concurrent edits, saved proposals/history, ignored client schema, and no publishing/usage side effects. The real JavaScript browser suite passes 11 assertions covering eligible buttons, unsaved edits, versioned requests, server-generated values, review-only messaging, failed refreshes and no JavaScript errors. The generator and database-planning suites continue to pass 26 assertions each.

All 42 suite groups were run: 40 pass. Known baseline billing failures remain plans_studio (40/47) and plan_gate (19/24). All phase, research, connector, xpay and fx_bank checks pass. PHP lint checks 1270 files. Complete output is in breadcrumb-refresh-test-output.txt. Offline transports and guarded disposable database fixtures were used; no production paid generation was run.

Deployment has not yet been confirmed. The four old customer tasks have not been refreshed or published by preparing this archive. After upload, inspect the fresh task values and approve only the corrected proposals whose live page evidence still matches. Do not start another paid cycle solely to refresh these tasks.

Rollback: restore the backed-up runtime files and restart workers/OPcache. A completed review action is an ordinary pending proposal with its old value retained in evidence history; rolling back code does not automatically reverse reviewed task data or publish it.
