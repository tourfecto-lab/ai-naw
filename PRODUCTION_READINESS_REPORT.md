# TOURFECTO GBP MODULE — PRODUCTION READINESS REPORT
**Date:** 2026-08-14
**Round:** 7 (Production Finalization pass, on top of Rounds 1–6)
**Version:** **v1.4.1** (not v1.5.0 — see §J and the Version Decision note below; live Google testing and a full execution-based test pass remain outstanding)

---

## ⚠️ Critical environment disclosure (read first)
This environment has **no PHP interpreter, no MySQL/database, no Docker, and no network access to Google's APIs**. Every claim in this report is based on:
- Manual code review
- Cross-referencing Google's official API documentation (via web search)
- Static balance/structure checks (brace/paren counting, comment-aware)

**No code in this module has ever been executed.** Per the finalization instructions' own rules (§23/§24), this report does not claim any test was "run" — it distinguishes between "reviewed" and "executed" throughout. Where the instructions require `LIVE GOOGLE TEST REQUIRED` or `LIVE GOOGLE TEST NOT AVAILABLE`, those exact phrases are used below.

**Also confirmed via screenshot in this conversation:** as of the last deployment check, none of this module's code had been deployed to the live server yet. This report describes what has been *written and reviewed*, not what has been *confirmed running in production*.

---

## A. What existed already (before this Round 7 pass)
- OAuth connect/disconnect/callback flow (`ReputationController`) — pre-existing, not rebuilt.
- Review sync (`GoogleReviewSyncService`) — pre-existing, reused (not duplicated) by the new Sync Engine.
- Post generation/scheduling (`GbpContentService`, `PublishGbpPostJob`) — pre-existing, extended.
- Rounds 1–6 of this module: Setup Wizard, Connection Center, Location Dashboard, Profile Management, Photos (async upload as of Round 6), Reviews filters, Insights & Analytics, AI Insights & Recommendations, Multi-tenant isolation, Events, Background Sync via queue, Posts Edit/Delete/Cancel.

## B. What was fixed this round (real bugs, not style changes)
1. **Attributes API was fundamentally incomplete and partly wrong.** It never called Google's real `attributes.list` metadata endpoint, so there was no legitimate way to know which Attribute IDs apply to a given business category — it could only handle a hardcoded BOOL label list. Also had a field-name bug (`attributeId` used where Google's actual schema requires `name` in the write payload). **Fixed**: now calls `GET /v1/attributes?parent=locations/{id}` first to discover real, category-specific attributes (BOOL, REPEATED_ENUM, and URL types, with real enum option values/labels from Google — no guessing), merges with current values from `GET /v1/locations/{id}/attributes`, and the write path uses the correct `name` field. If Google doesn't list an attribute for the update request, the service now explicitly returns `"Not available for this business category"` instead of attempting it.
2. **No idempotency guard in `PublishGbpPostJob`.** Under the queue's own stale-lock retry (5-minute timeout), a slow Google API call could cause the same post to be picked up and published twice. **Fixed**: the job now checks for `published`/`processing` status before doing any work.
3. **Missing `.htaccess` protection** on `public_html/uploads/gbp_photos/` — the project has this exact hardening pattern elsewhere (`storage/uploads/.htaccess`) but it was absent for GBP photo uploads. **Fixed**, scoped only to this module's own upload directory.
4. **AI credit gating gap**: `POST /api/gbp/content` (post generation) and `GET /api/gbp/ai-insights` both call `GeminiClient` but had no `SubscriptionMiddleware:require_ai_credits`, unlike every other AI-consuming route in the codebase. **Fixed** — both routes now use the same middleware pattern as `/api/ai/article` etc.
5. **N+1 query in `listContent()`** (introduced in Round 6): fetched the latest schedule per post in a loop (up to 30 extra queries per page load). **Fixed** with a single batched `IN (...)` query.

## C. What was added this round
- `GbpAuditLogger` + `gbp_audit_log` table — logs connect, disconnect, sync, profile_update, attributes_update, photo_upload, photo_delete, post_create, post_update (schedule/edit/cancel), post_delete, post_publish (success and failure), and ai_analysis, with a hard blocklist that strips any key containing `token`/`secret`/`password`/`authorization` before it's ever written, as an additional safeguard beyond manual code discipline.
- **All items from §16's explicit list are now wired**, including post create/edit/delete/cancel and AI analysis (these were flagged as a remaining gap earlier in this same round and have since been closed).
- Fixed a real bug caught while wiring `deleteContent()`'s audit log: `Model::delete()` clears the in-memory model's attributes after a successful delete, so reading `website_id` from the model *after* calling `delete()` would have logged `null` — fixed by capturing it beforehand.

## D. What was tested (reviewed, not executed)
- **Static syntax/structure check**: comment-aware brace/paren balance verification on every file touched this round (10 files) — all passed. This is *not* equivalent to `php -l`, which was unavailable.
- **IDOR review**: spot-checked photo, post, and profile endpoints — all scope queries by both `website_id` AND `user_id` together (not `user_id` alone), meaning a spoofed `website_id` belonging to another tenant simply matches no row and fails safely. No gap found in this review pass.
- **Token/secret leakage review**: traced every place an OAuth exception message could reach a log or a user-facing response — confirmed `GoogleOAuthClient`/`GoogleReviewSyncService` never include token values in thrown exception messages (only Google's `error_description` field, which is documentation text, not a secret).
- **Error-message sanitization review**: confirmed the app's global exception handler (`public_html/index.php`) already sanitizes uncaught exceptions based on `APP_DEBUG`, and all GBP-specific error paths return curated Arabic messages, not raw exceptions — **provided `APP_DEBUG=false` in production**, which was not independently verified (no `.env` access).
- **Queue dead-letter/timeout review**: confirmed the existing project-wide `QueueManager` already has `MAX_ATTEMPTS=3` and no infinite retry — this pre-existing infrastructure satisfies §11 without needing GBP-specific changes.
- **Rate limiting review**: confirmed `RateLimitMiddleware` is applied globally (all routes, including GBP's) when `RATE_LIMIT_ENABLED` is set — no GBP-specific gap.

## E. What could NOT be tested (and why)
- Any actual HTTP request/response cycle (no PHP runtime).
- Any actual SQL execution — migrations, `IN(...)` query correctness, foreign key behavior (no MySQL).
- Any actual Google API call — OAuth flow, `attributes.list` response shape, Media API upload, Performance API metrics (no credentials, no network).
- Full test matrix requested in §23 (Admin / Tenant A / Tenant B / Unauthorized user, run against a real database) — not possible without a database.
- Browser-based UI/UX pass (§17) — desktop/tablet/mobile rendering, broken buttons, JS console errors, horizontal overflow — not possible without a browser+running app. (A screenshot earlier in this conversation confirmed the module wasn't deployed at that time; no more recent screenshot has been provided.)

## F. `LIVE GOOGLE TEST NOT AVAILABLE IN CURRENT ENVIRONMENT`
Per §24's explicit instruction: no Google credentials are available here, so no live OAuth/API test was attempted or claimed. The Attributes rewrite in particular (§B.1) is the highest-risk area to verify live, since it's based on documentation review rather than an observed real response.

## G. Migrations required (apply in this exact order)
1. `2026_08_09_000042_create_gbp_module_tables.sql` — `gbp_sync_logs`, `gbp_photos`, `gbp_insights_cache`
2. `2026_08_11_000043_add_queue_job_id_to_gbp_scheduled_posts.sql` — adds `queue_job_id` column
3. `2026_08_11_000044_add_async_upload_status_to_gbp_photos.sql` — adds `status`/`error_message` columns
4. `2026_08_14_000045_create_gbp_audit_log.sql` — `gbp_audit_log` (new this round)

All additive only. No `DROP TABLE`/`DROP COLUMN` used anywhere, per §19/§1.

## H. Cron/Queue commands required
- Existing `cron/process_queue.php` must be scheduled (e.g., every 1 minute) — this is what actually executes `PublishGbpPostJob`, `GbpBackgroundSyncJob`, and `GbpPhotoUploadJob`. Without it, posts/photos/background sync will sit in `pending` status indefinitely.
- Optional additional entry: `cron/gbp_enqueue_background_sync.php` (e.g., every 6 hours) if queue-based background sync is wanted alongside the existing `sync_google_reviews.php` (left untouched).

## I. Environment variables required
No *new* variables introduced this round. Reuses existing: `GOOGLE_MAPS_API_KEY`, `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `GOOGLE_OAUTH_REDIRECT_URI` (readable from Admin → System Settings first, `.env` fallback). **Recommendation**: verify `APP_DEBUG=false` in the production `.env`, since several code paths in this report rely on that flag to avoid leaking internal error details (see §D).

## J. Remaining limitations / open items
- **REPEATED_ENUM/URL Attribute writes are unverified against a real account** — the read/discovery path (`attributes.list`) is new and unverified end-to-end.
- **EVENT/OFFER post types** still publish as `STANDARD` (disclosed in the UI since Round 4) — real support needs the additional required Google fields (event schedule, coupon code) that the UI doesn't collect yet.
- **No full browser-based UI/UX pass performed** (§17) — cannot be done without a running instance.
- **No live execution of the test suite** (`tests/Integration/GbpModuleTest.php`) — written and reviewed, never run.
- **AI credit deduction**: adding the `require_ai_credits` gate (§B.4) stops *ungated* usage, but a broader review found no explicit credit-deduction call in the wider codebase's AI controllers either — this appears to be a pre-existing condition across the whole app, not something introduced by or specific to the GBP module, and fixing the general credit-accounting system is out of this module's scope per the finalization instructions (§ "ممنوع الانتقال إلى أي Module آخر").

### Version decision
Per §27's explicit rule — since live Google testing remains `NOT AVAILABLE IN CURRENT ENVIRONMENT` and the full execution-based test matrix in §23 was not run — this module is tagged **v1.4.1**, not v1.5.0. It should not be described as "Production Complete" until a real deployment + test pass (as recommended repeatedly throughout this conversation) has actually happened.
