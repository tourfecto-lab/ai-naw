# Tourfecto — Business Control Center — Changelog

**Date:** 2026-08-14 (initial), 2026-08-15 (scope update + competitive phase + Team Management/RBAC)
**Scope of this delivery:** Phases 1–7 (Audit, User/Business Profile separation, Locations,
Services, Target Markets, AI Business Context, Brand Settings) + competitive phase (AI Audit
readiness scoring, business overview dashboard, atomicity fix) + **Phase 10–11 (Team
Management + RBAC)** of a 30-phase request. See "Not done yet" at the bottom for the remaining
scope.

---

## ⚠️ Critical correction before anything else

The request assumes a **Laravel** architecture (`php artisan`, Eloquent relationships, Form
Requests, Policies/Gates, Laravel Notifications, `composer dump-autoload`, `route:list`). This
project is **not Laravel** — confirmed directly: no `artisan` file anywhere, no `Illuminate\*`
namespace in `composer.json`, no Eloquent. It's a custom PHP MVC framework with its own Router,
`Model` base class, `Validator`, `QueueManager`, and `Controller` base class — the same
architecture every phase of the earlier Profile work (13 phases, see `PROFILE_CHANGELOG.md`)
was built against.

Everything below is built with the **intent** of the Laravel-flavored instructions (Service
layer, server-side authorization, async jobs, extensible RBAC) but using this project's actual
tools, not simulated Laravel syntax.

## Scale of the actual request

This is not "finish the Profile module." It's a request to build an entirely new Business
Context platform layer on top of the existing 13-phase Profile work: Business entity,
Locations, Services, Target Markets, AI Context, Brand Settings, an Integrations Center, Team
Management with RBAC, real API Keys, a centralized Audit Log, an Onboarding wizard, and an AI
Audit scoring system — each with DB + Model + Validation + Authorization + Service + API +
Frontend + Tests per the request's own completion bar. This is realistically weeks of real
engineering work, not a single session. This delivery covers the audit and the foundational
separation everything else depends on; later phases are proposed, not started.

---

## Phase 1 — Audit

### Already exists and works (from the prior 13-phase Profile effort + base project)
User Profile (identity/avatar/locale), Password Change, 2FA (TOTP+QR+Recovery Codes), Sessions
(API/Mobile via `RefreshToken` + browser sign-out-others), OAuth (Google/Facebook/Microsoft/
Apple Connect+Disconnect), Login History, Data Export (`ExportUserDataJob` on a real job queue),
Delete Account (password-gated), per-user Feature Flags (`FeatureFlagService`), one real
Notification wire-up (`notify_email` → Competitor Intelligence alerts), full i18n (ar/en/fr/de)
+ RTL, and an existing test suite (`tests/Unit`, `tests/Integration`, real Fixtures) — **not yet
extended to cover any of this new work**.

### Exists but incomplete
- **Business data**: scattered as individual columns on `websites` (`company_name`, `industry`,
  `target_language`, `target_country`, competitor URLs) — confirmed via `Website.php`'s own
  column-alias-detection comments that even *those* names don't match the live table
  (`company_name`→`brand_name`, `industry`→`industry_niche`, `target_country`→
  `target_countries`; the competitor-URL columns and `last_analysis_at` don't exist in the real
  table at all — only in the stale `schema.sql`). No separate Business entity exists.
- **API Keys**: only the single shared `users.api_token` (same one used for web cookie auth) —
  no create/list/revoke/scopes system.
- **Audit Log**: `login_history` and `activity_logs` exist but are separate, not unified.

### Doesn't exist at all
Business entity, Locations, Services, Target Markets, AI Business Context, Brand Settings,
Integrations Center (a unified view — individual integrations like Search Console exist but are
scattered across controllers like `ReputationController`), Team Management (no concept of
multiple users per business anywhere in the schema), RBAC beyond the existing single `role` enum
on `users`, Onboarding wizard with progress tracking, AI Audit scoring.

### Needs security review
The shared `api_token` mechanism (already documented as a structural limitation in
`PROFILE_CHANGELOG.md` Phase 7); no IDOR review exists yet for `business_id` because the concept
didn't exist until this delivery — addressed directly below.

---

## Phase 2 — Separating Business Profile from User Profile

## Design decision, stated directly

The existing `websites` table structure implies **one website = one business** (each website
row already carries its own company/industry/target data independently). There is no existing
signal that a user's multiple websites represent the same real-world business. The safe,
non-destructive design chosen: **one Business per Website (1:1)** for now, with the schema left
open to a future many-websites-per-business model without any destructive migration, once that
assumption can actually be verified (not guessed).

`businesses.owner_user_id` is a quick-reference pointer to the account that owns it — **not**
the full team membership list. Real multi-user-per-business (Team Management, spec Phase 10)
needs its own `business_members` table later; deliberately not conflated with this table.

## Files Added

- `database/migrations/2026_08_14_000049_create_businesses_table.sql` — additive only
  (`CREATE TABLE IF NOT EXISTS` + `ALTER TABLE ADD COLUMN`). New `businesses` table plus a
  nullable `websites.business_id` (FK `ON DELETE SET NULL`, not `CASCADE` — deleting a Business
  must never delete the underlying Website and its analytics history).
  `business_type` is stored as `VARCHAR`, not a DB `ENUM`, per the request's own instruction
  to prefer code-level validation for extensibility — new types need one line in
  `Business::allowedBusinessTypes()`, not a migration.
- `app/Models/Business.php` — new model, follows the same `$table`/`$fillable` convention as
  every other model in this project. Includes `isOwnedBy()` as the single, centralized
  ownership check every controller must use (there's no Policy/Gate system to build this into
  yet — this is the explicit, server-side stand-in for one, not a shortcut around it).
- `app/Controllers/BusinessController.php` — real CRUD (`show`/`store`/`update`), not a stub:
  full backend validation (required fields, ISO country/currency format checks, business_type
  whitelist, year-established sanity range), explicit ownership enforcement on every read/write,
  and IDOR-safe error handling (`update()` returns 404, not 403, for a business ID that exists
  but belongs to someone else — doesn't confirm or deny existence to an unauthorized caller).
- `scripts/backfill_businesses_from_websites.php` — one-time, idempotent CLI script (uses the
  existing `cron/bootstrap.php` CLI bootstrap, not a new one). **Deliberately built using the
  `Website` model, not raw SQL**, specifically because of the confirmed real-vs-schema.sql
  column drift described above — raw SQL referencing `company_name`/`industry`/etc. by those
  literal names would have silently read or written the wrong columns. Safe to re-run: skips
  any website that already has a `business_id`.

## Files Modified

- `app/Models/Website.php` — added `business_id` to `$fillable` (required for `save()` to
  persist it at all — same silent-drop issue class already fixed multiple times in the
  Profile work). Before the migration runs, this new field is automatically excluded from
  queries by the model's own existing column-alias system (it looks for a real column named
  `business_id`, doesn't find one yet, and self-heals into "unmappable" rather than erroring) —
  after the migration runs, it's picked up automatically. No manual coordination needed.
- `app/routes/api.php` — `GET/POST /api/business`, `PUT /api/business/{id}`, all
  `AuthMiddleware`-protected, matching the existing `/api/user/*` route style.

## Same Classmap Risk as Before — Now Applies to `Business` and `BusinessController`

Both are brand-new classes. Per the standing recommendation since Phase 7 of the Profile work:
verify `vendor/composer/autoload_classmap.php` on the real server before trusting this runs at
all (`grep -c "'Business'" vendor/composer/autoload_classmap.php` — expect `0` until
`composer dump-autoload -o` is run there, same as every other new class delivered so far).

## Tests Passed

None — no PHP runtime available in this environment, consistent with every phase of the prior
Profile work. This one additionally cannot be verified without running the backfill script
against a real database with real `websites` rows.

---

---

## Phase 3 — Business Locations (2026-08-14, same day continuation)

## Files Added

- `database/migrations/2026_08_14_000050_create_business_locations_table.sql` — additive only.
  `business_locations` table, `ON DELETE CASCADE` from `businesses` (deliberately different from
  the `businesses`↔`websites` link, which uses `SET NULL` — a location's only meaning is as part
  of its business; there's no scenario where an orphaned location makes sense, unlike a website
  which has independent analytics history worth preserving).
- `app/Models/BusinessLocation.php` — standard model, plus `getOpeningHours()` for consistent
  JSON-column decoding (same pattern as `Business::getSupportedLanguages()`).
- `app/Services/BusinessLocationService.php` — the actual business rule (**exactly one primary
  location per business at all times**) lives here, not duplicated across the Controller's
  create/update/delete paths. Handles three real edge cases explicitly:
  1. Setting a new location as primary automatically un-sets whichever one was primary before
     (wrapped in a DB transaction using the project's own `beginTransaction()`/`commit()`/
     `rollback()` — not raw `START TRANSACTION` SQL strings, since the `Database` class tracks
     an internal `$inTransaction` flag its own connection-retry logic depends on).
  2. A business's *first* location becomes primary automatically even if the caller didn't say
     so — otherwise a business could silently end up with zero primary locations.
  3. Deleting the current primary location promotes the next-oldest remaining location to
     primary automatically, for the same reason.
- `app/Controllers/BusinessLocationController.php` — full CRUD, with a **two-level ownership
  check** on every location-specific action (`location → its business → owner_user_id`), not
  just a location-ID lookup — the same IDOR-safety standard as `BusinessController`.

## Files Modified

- `app/routes/api.php` — nested routes (`GET`/`POST /api/business/{businessId}/locations`,
  `PUT`/`DELETE /api/business/locations/{id}`). Confirmed no ambiguity with the existing
  `PUT /api/business/{id}` route by reading the Router's pattern compiler directly — all route
  patterns are anchored (`^...$`) and match by exact path-segment shape, so
  `/api/business/{id}` (3 segments) and `/api/business/locations/{id}` (4 segments) can never
  collide. Also confirmed multi-parameter nested routes are an established pattern elsewhere in
  this project before adding one of my own.

## Tests Passed

None run (no PHP runtime, consistent with every phase). The primary-location transaction logic
in particular needs a real concurrency-aware test before trusting it under load — reasoned about
correctness by reading the code, not by executing it.

---

---

## Phase 4 — Business Services (2026-08-14, same day continuation)

## Files Added

- `database/migrations/2026_08_14_000051_create_business_services_table.sql` — additive only.
  `category` stored as `VARCHAR`, same extensibility reasoning as `business_type` (Phase 2) —
  the request explicitly said "don't hard-code these" for services specifically. Slug uniqueness
  is scoped **per business**, not global (`UNIQUE (business_id, slug)`) — two unrelated
  businesses can both have a `nile-cruises` service with no conflict.
- `app/Models/BusinessService.php` — standard model + `suggestedCategories()` (a starting list
  for the UI to offer, not a hard constraint — the column itself accepts anything) +
  JSON-array helpers for `target_markets`/`target_languages`, consistent with the pattern from
  `Business`/`BusinessLocation`.
- `app/Services/BusinessServiceManager.php` — slug generation/uniqueness logic, kept out of the
  Controller. Handles the actual edge case correctly: when updating a service's name, it
  regenerates the slug automatically *unless* the caller explicitly sent a `slug` field —
  otherwise renaming "Nile Cruises" to "Nile River Cruises" later would silently break any
  external link/bookmark pointing at the old slug with no way to opt out.
- `app/Controllers/BusinessServiceController.php` — full CRUD, same two-level ownership check
  pattern (`service → business → owner_user_id`) as `BusinessLocationController`. Even an
  explicitly client-provided slug is still re-validated for uniqueness server-side, not trusted
  as-is.

## Files Modified

- `app/routes/api.php` — nested routes under `/api/business/{businessId}/services` and
  `/api/business/services/{id}`, same shape/collision-safety reasoning as Phase 3's Locations
  routes.

## Tests Passed

None run (no PHP runtime, consistent with every phase).

---

---

## Phase 5 — Target Markets (2026-08-14, same day continuation)

## Design Decision

Unlike Locations/Services (repeatable entities), Target Markets is business-wide configuration
— one coherent answer to "who does this business target," not a list of separate records. Built
as a **1:1 table** (`UNIQUE (business_id)` enforced at the DB level), with a `show`/`upsert`
controller instead of full CRUD — there's no meaningful "create another one" or "delete this
one" for a single settings record.

This table is explicitly the **single source of truth** the request asked for — Phase 6 (AI
Business Context) will read from it directly rather than storing its own duplicate copy of
target countries/languages, per the request's own explicit instruction against that.

## Files Added

- `database/migrations/2026_08_14_000052_create_business_target_markets_table.sql` — additive
  only. `customer_type` (`b2b`/`b2c`/`both`) stored as `VARCHAR` despite being a genuinely small,
  stable set of values — kept consistent with every other classification column in this delivery
  rather than making an exception, per the same extensibility reasoning applied elsewhere.
- `app/Models/BusinessTargetMarket.php` — standard model + JSON-array getters for all four list
  fields (countries/cities/languages/segments), same pattern as every other model in this
  delivery.
- `app/Controllers/BusinessTargetMarketController.php` — `show()` (returns `null` cleanly if
  the business hasn't set this up yet — not an error) and `upsert()`. Validates:
  `customer_type` against the allowed list, every array field is genuinely an array (not
  trusting the frontend to have sent the right shape), and every `target_countries` entry
  against ISO 3166-1 format via regex — deliberately **not** via a shared country-list class,
  since (as documented since Phase 1 of the original Profile work) new Helper/Config classes
  carry the same classmap-autoload risk; a self-contained format check avoids that entirely here.

## Files Modified

- `app/routes/api.php` — `GET`/`PUT /api/business/{businessId}/markets`.

## Tests Passed

None run (no PHP runtime, consistent with every phase).

---

---

## Phase 6 — AI Business Context (2026-08-14, same day continuation)

## Why this phase matters most (per the request's own words)

This is the centerpiece: a single aggregation point every AI-driven feature in Tourfecto should
call instead of each independently querying (or worse, separately storing a copy of) business
identity, locations, services, target markets, and brand-voice data.

## Files Added

- `database/migrations/2026_08_14_000053_create_business_ai_context_table.sql` — additive only,
  1:1 with `businesses` (same pattern as Target Markets). `target_audience` here is a free-text
  field for AI prompting — it complements, not duplicates, the structured
  `business_target_markets` table from Phase 5; the two serve different purposes (prose context
  vs. filterable/structured data) and both being present isn't redundancy.
- `app/Models/BusinessAiContext.php` — standard model, JSON-array getters for every list field
  (USPs, forbidden claims, keywords, goals, competitors).
- **`app/Services/BusinessContextService.php`** — the actual deliverable this whole phase is
  about:
  - `getContext(int $businessId)` aggregates `Business` + all `BusinessLocation` rows (with the
    primary one singled out) + active `BusinessService` rows + `BusinessTargetMarket` +
    `BusinessAiContext` into one structured array, **cached** (via the project's existing
    `Cache` class — file/Redis/Memcached depending on config, didn't need to build a new caching
    layer) with a 1-hour TTL, directly per the request's own Phase 27 performance instruction
    ("don't let Business Context run many queries on every AI request").
  - `invalidate(int $businessId)` — and critically, **every controller that writes to any of the
    underlying tables now calls this after a successful save**: `BusinessController::update()`,
    all three `BusinessLocationController` write paths (create/update/delete), all three
    `BusinessServiceController` write paths, `BusinessTargetMarketController::upsert()`, and
    `BusinessAiContextController::upsert()` itself. A caching layer with invalidation that only
    partially invalidates is worse than no cache — it serves silently stale data after some edits
    and not others. Went back through every prior phase's controller specifically to close this,
    rather than declaring Phase 6 done with only the new endpoint wired up.
  - `toPromptContext(int $businessId)`: renders the aggregated context as a clean, ready-to-use
    text block for direct use in an LLM prompt — skips any field that's still empty rather than
    rendering placeholder text like "N/A", so a business that hasn't filled in target audience
    yet doesn't get a fake-looking prompt sent to whatever AI model consumes it.
- `app/Controllers/BusinessAiContextController.php` — `show()` (raw AI-context record),
  `full()` (the complete aggregated `BusinessContextService::getContext()` output — this is what
  other AI modules should eventually call internally, not re-fetch independently), and
  `upsert()` with real validation (array-shape checks on every list field, and specifically for
  `competitors` — validates each entry is an object with at least a `name`, not a bare string,
  since the schema stores `{name, url}` pairs).

## Files Modified (cache-invalidation wiring, not new features)

- `app/Controllers/BusinessController.php`, `BusinessLocationController.php`,
  `BusinessServiceController.php`, `BusinessTargetMarketController.php` — added
  `BusinessContextService::invalidate()` calls after every successful write. Two of these
  (`BusinessLocationController::destroy()`, `BusinessServiceController::destroy()`) needed the
  business ID captured **before** calling delete, since this project's `Model::delete()` clears
  the instance's attributes on success — capturing it after would have silently invalidated
  nothing.
- `app/routes/api.php` — `GET /api/business/{businessId}/ai-context`,
  `GET /api/business/{businessId}/ai-context/full`, `PUT /api/business/{businessId}/ai-context`.

## Tests Passed

None run (no PHP runtime, consistent with every phase). The cache invalidation coverage above
was verified by re-reading every write path in every prior-phase controller, not by executing
anything — worth a real test specifically confirming a location/service edit actually busts the
cached context before this is trusted in production.

---

---

## Phase 7 — Brand Settings (2026-08-14, same day continuation)

## Design decision — avoided duplicating Phase 2/6 data

The request's own field list for Brand Settings includes "logo" and "tone of voice" — both
**already exist**: `businesses.logo_url` (Phase 2) and `business_ai_context.brand_voice` /
`preferred_tone` (Phase 6). Did not create parallel columns for either — this is precisely the
anti-pattern the original request explicitly warned against ("don't let every module store its
own copy of company info"). The new `business_brand_settings` table holds only genuinely new
fields: favicon, brand colors, font preference, writing style, and preferred/prohibited
terminology.

The requested "Professional / Friendly / Luxury / Adventure / Family / Corporate, customizable"
preset list is now formally defined as `BusinessAiContext::allowedBrandVoicePresets()` —
extending the *existing* `brand_voice` column's validation (Phase 6 accepted any string up to 50
characters; now it's checked against this real list, with `'custom'` meaning the actual
description lives in the new `writing_style` field). This was a real, if small, gap in Phase 6 —
fixed here rather than left as "someone else's problem."

## Files Added

- `database/migrations/2026_08_14_000054_create_business_brand_settings_table.sql` — additive
  only, 1:1 with `businesses`.
- `app/Models/BusinessBrandSettings.php` — standard model + JSON helpers, including
  `getBrandColors()` which deliberately returns only whatever keys were actually set (no fake
  default black/white fallback color — that's a frontend rendering decision, not the backend's
  to invent).
- `app/Controllers/BusinessBrandSettingsController.php` — `show()`/`upsert()`. Real validation:
  `brand_colors` must be an object with only `primary`/`secondary`/`accent` keys, and every
  value must be a genuine `#RRGGBB` hex color (checked via regex) — not any arbitrary string that
  could break CSS if used directly in the frontend.

## Files Modified

- `app/Models/BusinessAiContext.php` — added `allowedBrandVoicePresets()`.
- `app/Controllers/BusinessAiContextController.php` — `brand_voice` is now validated against
  that list instead of accepted as any string.
- **`app/Services/BusinessContextService.php`** — brand settings are now part of the aggregated
  context (`buildContext()`) *and* the generated prompt text (`toPromptContext()` now includes
  writing style and prohibited terminology, with a clear "IMPORTANT - Never use these terms"
  line). Wired in immediately rather than left as a documented "future" gap — the same standard
  applied to every other data source in Phase 6.
- `app/routes/api.php` — `GET`/`PUT /api/business/{businessId}/brand`.

## Tests Passed

None run (no PHP runtime, consistent with every phase).

---

## Not done yet (remaining spec — proposed as future phases, matching the original priority order)

- **Phase 8–9**: Integrations Center (unifying scattered existing OAuth flows into one view) +
  Google integrations security review
- **Phase 12**: Real API Keys system (replacing the single shared `api_token`)
- **Phase 13–14**: Security Center enhancements + centralized Audit Log
- **Phase 15–16**: Expand `ExportUserDataJob` to include business data; enhance Delete Account
  to also revoke OAuth/API keys and handle business ownership transfer
- **Phase 17**: Onboarding wizard UI with progress tracking (the readiness score in the
  competitive phase is the data layer this UI will render)
- **Phase 20–27**: DB quality pass, validation hardening, API design review, Frontend UX,
  Notifications expansion, Tests, Security Audit, Performance — these apply across *everything*
  built in both this delivery and the prior Profile work, not just new code
- **Phase 28–30**: Migration verification, route/config cache check (no direct equivalent in
  this non-Laravel project — translates to verifying the classmap and manually checking
  `app/routes/*.php` for duplicate route registrations), final report

Each phase should be delivered and reviewed separately, the same way the 13-phase Profile
delivery was — this is not something to build all at once blind.

---

## Competitive phase (2026-08-15) — "AI Audit scoring + dashboard + correctness fix"

Differential analysis against Semrush / Yext / Birdeye / SOCi is documented in
`BUSINESS_COMPETITIVE_ANALYSIS.md`. This phase closes the three most exploitable gaps:

### New: BusinessReadinessService (AI Audit scoring)
- `app/Services/BusinessReadinessService.php` — weighted 0-100 readiness score across 7
  categories (identity, contact, locations, services, target markets, AI context, brand), with
  grade A-F, per-category breakdown, and prioritized next-step recommendations.
- Two-layer design: `scoreFromContext(array $context)` is pure logic (offline-testable) and
  reads only the `BusinessContextService::getContext()` output — the same Single Source of
  Truth, no extra queries; `score(int $businessId)` is the thin DB/cache wrapper.
- Weights: identity 20, AI context 20, contact 15, locations 15, services 10, target markets
  10, brand 10 (sums to 100). Documented as opinionated defaults, override-able later.
- Recommendations are sorted high-priority first (heavy-weight categories first).

### New: business overview dashboard endpoint
- `app/Controllers/BusinessController.php::overview()` + `GET /api/business/overview` (auth,
  IDOR-safe via the same `owner_user_id` filter as `show()`).
- One response = full context + readiness score + quick stats (location/service/market counts,
  `has_ai_context`, `has_brand_settings`) + top 5 next steps. Wires the dashboard without the
  frontend assembling six endpoints.

### Fix: atomic delete of a primary location
- `app/Services/BusinessLocationService::delete()` now runs delete + replacement-primary
  promotion inside one transaction (rollback on any failure), so the single-primary invariant
  can never be left half-applied.

### Files
- `app/Services/BusinessReadinessService.php` — **new**.
- `app/Controllers/BusinessController.php` — added `overview()`.
- `app/routes/api.php` — `GET /api/business/overview`.
- `app/Services/BusinessLocationService.php` — atomicity fix.
- `public_html/index.php` — registered `BusinessReadinessService`.
- `tests/Unit/Business/BusinessReadinessServiceTest.php` — **new** offline unit test (7 cases).

### Tests Passed
- `php tests/Unit/Business/BusinessReadinessServiceTest.php` — 7/7 passed (offline, pure
  logic; no DB required). DB-backed flows still require the server runtime, consistent with
  every prior phase.

---

## Phase 10-11 — Team Management + RBAC (2026-08-15)

Full multi-user access + role-based access control, delivered separately from the competitive
phase. Detailed changelog: `BUSINESS_TEAM_RBAC_CHANGELOG.md`.

- **`business_members`** table (additive migration 000055) — `admin`/`member`/`viewer` rows;
  owner stays derived from `businesses.owner_user_id` (single source of truth).
- **`BusinessAccessService`** — the one RBAC gate every business controller now calls
  (`roleOf`/`canView`/`canEdit`/`canManageTeam`/`canAdministerTeam`/`resolveUserBusiness`);
  pure `roleAllows()`/`roleRank()` are offline-testable.
- **`BusinessTeamService`** — invite (instant for registered users, token invite otherwise),
  accept, remove, change role, list. Fine rules centralized: `admin` cannot touch another
  `admin`; assigning `admin` requires the owner; owner can't be re-added as a member.
- **`BusinessTeamController`** + 5 routes (list/invite/accept/remove/changeRole).
- **Refactor**: all six business controllers' ownership checks replaced with the access
  service; write actions return 403 for `viewer`; `show()`/`overview()` resolve owner-or-member.
- **Tests**: `BusinessAccessServiceTest` 7/7 passed offline; readiness test re-run 7/7;
  route dispatch verified, no conflicts; `php -l` clean.
