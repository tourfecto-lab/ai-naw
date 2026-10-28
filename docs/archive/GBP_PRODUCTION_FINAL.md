# GBP_PRODUCTION_FINAL.md
**Tourfecto — Google Business Profile Module**
**Round 8 (2026-08-14) — Professional Finalization pass, on top of Rounds 1–7**
**Version: v1.4.2** (still not v1.5.0 — see Definition of Done section)

---

## ⚠️ Read this first
This is Round 8 of an extremely large finalization request (50+ phases, `A` through `BD`). **This round did not complete all 50+ phases.** It audited the request, picked the highest-value CRITICAL/HIGH items with genuine correctness or security impact, fixed those with real code, and is explicit below about everything left as an OPEN ITEM. Per the request's own rule, this report never says "تم" for something not actually implemented, and uses the required vocabulary throughout: `REVIEWED`, `STATIC VERIFIED`, `EXECUTION VERIFIED`, `LIVE GOOGLE VERIFIED`, `NOT AVAILABLE`.

**Environment constraint, unchanged since Round 1**: no PHP interpreter, no MySQL, no Docker, no network access to Google in this environment. Every item below is `STATIC VERIFIED` (comment-aware brace/paren balance check + manual code trace) at best — nothing is `EXECUTION VERIFIED` or `LIVE GOOGLE VERIFIED` unless explicitly marked otherwise.

---

## Architecture (unchanged)
PHP 8-style OOP, MySQL/PDO, no framework — matches the existing project convention. This round did not introduce Laravel/Node/Python or any new framework, per the instructions. No existing route, table name, or column was renamed. `GoogleReviewSyncService::getValidAccessToken()` (pre-existing, heavily reused method) was extended with a locking mechanism rather than rewritten — its external signature and return type are unchanged.

---

## Feature Table (Before → After → Status → Test)

| Feature | Before (Round 7) | After (Round 8) | Status | Test |
|---|---|---|---|---|
| Google API retry policy | No retry at all; single attempt regardless of transient failure | Retries GET/PATCH/DELETE (idempotent only) up to 3x with exponential backoff on 429/500/502/503/504; POST never auto-retried (avoids duplicate creates) | **Fixed** | STATIC VERIFIED |
| Connect timeout | Only total `CURLOPT_TIMEOUT` (30s); a hung DNS/TCP handshake could block 30s | Separate `CURLOPT_CONNECTTIMEOUT` (10s) added | **Fixed** | STATIC VERIFIED |
| JSON response validation | `json_decode()` result used without checking for decode failure | Explicit `json_last_error()` check; returns `GOOGLE_UNAVAILABLE` on non-JSON response instead of silently treating it as an empty array | **Fixed** | STATIC VERIFIED |
| Token refresh race condition | Two concurrent processes (e.g. manual "Sync Now" + background job) could both refresh the same token; the second `save()` could silently overwrite the first process's other field updates with stale in-memory values | `SELECT ... FOR UPDATE` inside a real DB transaction, with double-checked locking (re-check expiry after acquiring the lock before refreshing) | **Fixed** | STATIC VERIFIED |
| Error code vocabulary | Custom codes (`EXPIRED_TOKEN`, `INVALID_CREDENTIALS`, `LOCATION_NOT_FOUND`, etc.) — no downstream consumer depended on the exact names (verified via grep) | Renamed to the standard set: `AUTH_REQUIRED`, `TOKEN_EXPIRED`, `PERMISSION_DENIED`, `NOT_FOUND`, `INVALID_ARGUMENT`, `RATE_LIMITED`, `GOOGLE_UNAVAILABLE`, `NETWORK_ERROR`, `QUOTA_EXCEEDED`, `NOT_SUPPORTED`, `INTERNAL_ERROR` | **Fixed** | STATIC VERIFIED |
| Health Check | None existed | `GbpHealthCheckService` — checks OAuth config, Maps config, all required DB tables, queue worker liveness (via `jobs.completed_at` heartbeat proxy vs. due-but-unprocessed count), last successful/failed sync, AI availability. New endpoint `GET /api/gbp/health` (Admin-only) | **Added** | STATIC VERIFIED |
| Live Google Test Harness | Did not exist | `tests/Integration/GoogleLiveTest.php` — gated behind `GBP_LIVE_TEST=true`; read-only tests (OAuth config, connection, token refresh, account discovery, profile read, **attributes discovery — first live check of the Round 7 rewrite**, insights, reviews) skip (not pass) without real credentials; write operations (photo upload, post create/delete) require a second explicit gate (`GBP_LIVE_TEST_ALLOW_WRITES=true`) and are intentionally left as a stub pending a real test account, rather than writing untested cleanup logic against a real customer-adjacent account | **Added** (harness only) | STATIC VERIFIED — actual execution is `LIVE GOOGLE TEST NOT AVAILABLE IN CURRENT ENVIRONMENT` |
| Audit log coverage | Missing post create/edit/delete/cancel/AI-analysis entries (flagged as open item at end of Round 7) | All entries from the spec's list now wired, including the four that were missing | **Fixed** (carried over from mid-Round-7 continuation) | STATIC VERIFIED |
| Attributes (BOOL/ENUM/URL) | Rewritten in Round 7 based on documentation only | Unchanged this round — still awaiting live verification. `GoogleLiveTest.php`'s `testAttributesDiscovery()` is the first mechanism that will actually verify it | **Unchanged** | LIVE GOOGLE TEST REQUIRED |
| EVENT/OFFER real post types | Publishes as `STANDARD` regardless of UI selection (disclosed since Round 4) | **Not changed this round** — see Open Items | **Open** | Not attempted |
| AI credit atomic deduction | `require_ai_credits` middleware checks balance but no deduction/reserve/refund cycle found anywhere in the codebase (pre-existing, app-wide condition, not GBP-specific) | **Not changed this round** — building a reusable atomic credit service touches shared Wallet/Subscription infrastructure outside this module's boundary; flagged, not attempted, per "لا تنتقل لأي Module آخر" | **Open** | Not attempted |
| Photo/Post reconciliation (Google as source of truth) | Local `gbp_photos` cache populated on upload; no reconciliation against Google if a photo is deleted directly on Google's side | **Not changed this round** | **Open** | Not attempted |
| Multi-tenant isolation | Reviewed multiple times across Rounds 1–7; every GBP query scopes by `website_id` AND `user_id` together | Re-confirmed by trace this round (Phase E) — no new code path found that breaks this pattern | **Reviewed, unchanged** | STATIC VERIFIED (code trace, not a live two-tenant HTTP test) |

---

## CRITICAL / HIGH / MEDIUM / LOW (this round's audit)

**CRITICAL (fixed this round):**
- Token refresh race condition (data-corruption risk under real concurrent access)
- No JSON validation on Google responses (silent-failure risk)

**HIGH (fixed this round):**
- No retry policy at all (any transient Google 5xx killed the operation outright)
- No connect timeout (hung connections could block for the full 30s)

**HIGH (still open, not fixed this round):**
- EVENT/OFFER posts silently downgraded to STANDARD without the UI making this unmistakably clear on every single post creation attempt (a static disclaimer exists, but the spec wants this enforced structurally, not just disclosed)
- AI credit deduction is not atomic anywhere in the app (pre-existing, cross-module)
- Photo/post reconciliation against Google as source of truth not implemented

**MEDIUM (not addressed this round):**
- Review sync incremental/pagination/deduplication review (Phase U) — not audited this round
- Audit log retention/cleanup job (Phase AD) — not built
- Database index review across the 5 GBP tables (Phase AR) — not performed
- Rate limiting specifically for GBP-sensitive endpoints beyond the existing global `RateLimitMiddleware` (Phase AG) — not added
- Accessibility pass (Phase AL) — not performed
- Full responsive/browser UI pass (Phase AK) — cannot be performed without a browser

**LOW:**
- CSP/security headers review for GBP-specific needs (Phase AE) — the existing global CSP already allows `https:` broadly for scripts/connects, which covers Google domains; no GBP-specific tightening attempted or needed

---

## Definition of Done — checklist status
Using the exact checklist from the request:

- [x] OAuth hardened (reviewed, unchanged architecture — no new gap found)
- [x] Token refresh hardened (retry-safe path, real error classification)
- [x] Token refresh race protected (**this round**)
- [x] Tenant isolation verified (STATIC VERIFIED via code trace, not live)
- [x] IDOR verified (STATIC VERIFIED, Rounds 6–7)
- [ ] CSRF verified — not explicitly re-audited this round
- [ ] Rate limits verified — only global middleware confirmed, no GBP-specific limits added
- [ ] Attributes complete — LIVE GOOGLE TEST REQUIRED
- [x] Hours complete (Round 3, unchanged)
- [x] Profile update safe (Google-failure does not update local state — verified by code trace)
- [x] Photos async (Round 6)
- [x] Photos retry — queue-level retry exists (`MAX_ATTEMPTS=3`); no dedicated user-facing "retry this failed photo" button
- [ ] Photos reconciliation — not implemented
- [x] Posts create/edit/delete/cancel (Round 6)
- [x] Posts scheduling safe (idempotency guard, Round 7)
- [x] Standard Posts
- [ ] Event Posts properly supported — still STANDARD
- [ ] Offer Posts properly supported — still STANDARD
- [x] Review sync (pre-existing, reused)
- [ ] Review deduplication — not re-audited this round
- [ ] Review reply idempotency — not re-audited this round
- [x] Insights real-data only (Round 3)
- [x] Insights cache isolated (cache key includes connection id)
- [x] AI insights real-data only (Round 3)
- [x] AI credit check (middleware, Round 7)
- [ ] AI credit deduction atomic — open, cross-module
- [ ] AI failure refund/release — open, cross-module
- [x] Queue retry (pre-existing `QueueManager`)
- [x] Queue dead-letter (`MAX_ATTEMPTS=3` → `failed`, pre-existing)
- [ ] Queue monitoring — Health Check adds a system-wide view; no GBP-only breakdown by job type
- [ ] Cron monitoring — covered by Health Check's queue-worker heartbeat check
- [x] Audit logging (complete as of this round)
- [ ] Audit retention — not implemented
- [x] Error classification (standardized this round)
- [ ] Google quota protection — caching exists (Insights 2h cache); no explicit quota-budget tracking
- [x] Health check (**this round**)
- [ ] Responsive UI — cannot verify without a browser
- [ ] Accessibility basics — not addressed
- [ ] Empty states — reviewed in earlier rounds, not re-audited
- [ ] Loading states — skeleton states exist (Round 5); not re-audited this round
- [ ] Security review — partial (token/secret leakage, IDOR checked in Round 7; SSRF/XSS/open-redirect not re-checked this round)
- [ ] Database indexes reviewed — not performed
- [ ] Database constraints reviewed — not performed
- [ ] Integration tests expanded — not expanded to the 30-item list this round
- [x] Live Google test harness added (**this round** — harness only, not executed)
- [ ] Deployment guide updated — DEPLOY_INSTRUCTIONS.md carries Round 7 content; the 20-step checklist from Phase BA not yet applied
- [ ] Rollback guide added — not written this round
- [x] Documentation updated (this file)

**Conclusion: the module is not Production Complete.** Multiple unchecked items remain, several of which (Attributes, EVENT/OFFER, AI credit atomicity, full test matrix, live testing) are explicitly acknowledged as requiring either a live Google account, a broader cross-module change, or a running PHP/MySQL/browser environment this session doesn't have.

---

## Environment variables (new/changed this round)
- `GBP_LIVE_TEST` (optional, default off) — enables `tests/Integration/GoogleLiveTest.php`
- `GBP_LIVE_TEST_WEBSITE_ID`, `GBP_LIVE_TEST_USER_ID` — required if the above is set
- `GBP_LIVE_TEST_ALLOW_WRITES` (optional, default off) — additional gate for write-operation tests (not yet implemented, stubbed as SKIP)

## New files this round
- `app/Services/GoogleBusiness/GbpHealthCheckService.php`
- `tests/Integration/GoogleLiveTest.php`
- `GBP_PRODUCTION_FINAL.md` (this file)

## Modified files this round
- `app/Services/Reputation/GoogleBusinessAPI.php` — retry policy, connect timeout, JSON validation, error code renaming
- `app/Services/Reputation/GoogleReviewSyncService.php` — token refresh race protection
- `app/Controllers/GbpProfileController.php` — `health()` endpoint
- `app/routes/api.php` — `GET /api/gbp/health`
- `app/Services/GoogleBusiness/GbpContentService.php`, `GbpAIInsightsService.php` — completed audit log wiring (post create/edit/delete/cancel, AI analysis)

## Open Items requiring External Verification (per the instructions' own rule — not left as "fixable in code but unfixed")
- **LIVE GOOGLE TEST REQUIRED**: Attributes API (Round 7 rewrite), Media API paths (Round 5 fix), Performance API metrics, OAuth end-to-end flow — none of these have ever been exercised against a real Google account.
- **PHP runtime required**: `php -l` on every modified file — not possible here; only comment-aware static balance checking was performed.
- **MySQL required**: all 5 migrations (create + alter statements) — never executed; column/index correctness unconfirmed beyond manual SQL review.
- **Browser required**: Phases AK (responsive UI), AL (accessibility), AJ (UX flows), AM/AN (empty/loading states) — none can be verified without a running instance and a browser.

## Genuine Open Items (code-fixable, not yet fixed — distinct from the External Verification list above)
- EVENT/OFFER real Google topic-type support
- AI credit atomic deduction/refund service
- Photo/post reconciliation against Google as source of truth
- Audit log retention/cleanup job
- Database index/constraint review for the 5 GBP-specific tables
- GBP-specific rate limiting beyond the existing global middleware
- Expanded integration test suite (the 30-item list in Phase AT)
- Rollback guide (Phase BB)
- 20-step deployment checklist (Phase BA) applied to DEPLOY_INSTRUCTIONS.md
