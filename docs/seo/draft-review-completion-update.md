# Complete article draft review and preserve single-trip itineraries

Base 2966080, branch seo/content-publication-polish.

A customer review found that SEO Expert could edit an article's title and body while the stored FAQ answers and SEO description remained unchanged. This prevented correcting unsupported FAQ claims before publishing. The plan now exposes each owned content task's description and FAQ values, and the existing draft editor can save title, body, description and up to eight plain-text question/answer pairs together. Clearing both FAQ fields removes that question; FAQ questions and answers must also occur visibly in the body.

Task and quality records update in one transaction with the existing ownership, run status and optimistic version checks. Confirmed-profile changes, unsupported claims, invisible answers, HTML, excessive field sizes and published records prevent the draft edit. Existing clients that omit description/FAQ fields preserve those fields. Drafts without quality records retain their existing legacy path. Editing remains separate from approval and publication. Unsaved description/FAQ changes block approval; saving another task preserves pending edits in all these fields.

The collection guard is also corrected to check ItemList/CollectionPage at the document or @graph level. A nested ItemList describing the itinerary of a genuine TouristTrip does not make that page a collection. Existing collection protections remain in place.

Installation on the PLATFORM hosting:
1. Back up the files included in app and public_html/assets/js.
2. Upload both directories preserving their paths. Uploading app alone leaves the old editor interface in use.
3. Restart persistent PHP queue workers/reload OPcache and refresh the SEO Expert page to load the updated JavaScript.
4. Open the existing completed cycle, review the article's body, description and FAQ fields, save together, then approve only the reviewed tasks. No new paid cycle is required.

No SQL migration or customer connector replacement is needed. This archive includes the prior content-target and quality runtime files so their dependencies stay consistent. It does not change customer PHP hosting configuration or automatically publish a draft. The existing Phase 4 quality tables are required for the additional review fields; installations without them keep legacy behavior.

Offline checks: 36/38 groups pass; known baseline plans_studio 40/47 and plan_gate 19/24 remain failures. New database review test: 18 assertions. Real-browser editor test: 11 assertions, including outgoing save payload, explicit FAQ removal, unsaved-approval blocking and preserving edits on another task. Customer-quality regression suite: 27 assertions, including the nested itinerary case. All prior required phase, connector, research, plans, xpay, plan_gate and fx_bank suites were run. Full results included.

Live review outcome before deploying this editor update: 12 additional breadcrumb tasks were applied and verified using the existing cycle; existing specific descriptions were preserved; unsuitable schema/link proposals were left unapplied with review evidence. Corrected related-link drafts and a revised article were prepared for review. Search Console sitemap submission still needs the separately granted Google write scope. Live HTTP success or sitemap membership does not prove Google indexing or ranking improvement.

Rollback: restore the backed-up PHP and JavaScript files together; restart workers/reload OPcache. Existing customer tasks, content, schema and measurements are preserved; no database rollback is necessary.
