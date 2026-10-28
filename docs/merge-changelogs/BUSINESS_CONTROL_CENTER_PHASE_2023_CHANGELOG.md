# Business Control Center — Phases 20–30 Changelog

**Branch:** `feat/business-control-center`
**Date:** 2026-08-17
**Scope:** DB quality pass, validation hardening, API design review, Frontend UX, Notifications expansion, Tests, Security audit fixes, Performance audit fixes, Migration verification, Route & config verification, Final report

---

## Phase 20 — DB quality pass

`database/migrations/2026_08_15_000058_business_db_quality_fks.sql` — closes 4 FK/index gaps found in the Phase 8–17 tables. **Additive only** (`ADD INDEX` / `ADD CONSTRAINT`, no `DROP`):

| Table / Column | Action | Rationale |
|---|---|---|
| `business_members.user_id` | `ADD INDEX` + FK → `users.id` **ON DELETE CASCADE** | When a joined user's account is deleted, their membership row must go too (no orphan rows / blocked deletes). |
| `business_members.invited_by_user_id` | `ADD INDEX` + FK → `users.id` **ON DELETE SET NULL** | The inviter may delete their account while invitations stay pending — keep the invite, drop the pointer. |
| `business_members.invited_email` | `ADD INDEX` | The accept-invite lookup filters by `invited_email`; without an index that scan grows linearly. |
| `business_api_keys.created_by_user_id` | `ADD INDEX` + FK → `users.id` **ON DELETE SET NULL** | API keys belong to the **Business**, not the creator. If ownership was transferred before the creator deletes their account, the keys must keep working — hence SET NULL, not CASCADE. |

`2026_08_15_000056_create_business_api_keys_table.sql` updated so `created_by_user_id` is `DEFAULT NULL`, consistent with SET NULL.

## Phase 21 — Validation hardening

Backend stays the single source of truth for input validation across six controllers:

- **BusinessController** — `website_url` / `logo_url` validated via `filter_var` with a temporary `https://` prefix when the scheme is missing; `primary_language` / `supported_languages` constrained to ISO 639 (2–3 alpha); timezone validated against `timezone_identifiers_list()`.
- **BusinessLocationController** — `lat` clamped to `[-90, 90]`, `lng` to `[-180, 180]`; new `validateLocationInput(bool $isUpdate)` keeps `name` required on create but optional on update.
- **BusinessTargetMarketController** — `target_languages` values must be ISO 639 (2–3 alpha), not free text.
- **BusinessServiceController** — new `validateTargetArrays()` merged into `store`/`update`; `applyOptionalFields()` filters target arrays to strings only.
- **BusinessAiContextController** — `competitors[]`: every entry must have a non-empty `name` and a valid URL.
- **BusinessBrandSettingsController** — `terminology` accepts a string or `{term, ...}` object; `favicon_url` must be a valid URL.

## Phase 22 — API design review

Consistency pass over the new endpoints (uniform `success`/`error` envelopes, 401/403/404/422/500 statuses) surfaced one design flaw: **API-key and audit-log access were coupled to team-management permission**.

- `BusinessAccessService` gains `CAP_MANAGE_KEYS` and `CAP_READ_AUDIT` capabilities + `canManageKeys()` / `canReadAudit()` helpers (owner + admin).
- `BusinessApiKeyController` now gates on `canManageKeys()` (was `canManageTeam()`) — and its `index` was **also** hardened: viewing the key list is owner/admin only, not every member.
- `BusinessAuditLogController` gates on `canReadAudit()`.
- Capability model is now semantically distinct (`manage_team` vs `manage_keys` vs `read_audit`), future-proof even though owner/admin hold all three today.

## Phase 23 — Frontend UX

New **Business Control Center** panel page at `/business-center` (`BusinessCenterController`) — a single pane that assembles the business module instead of the dashboard stitching six endpoints:

- **Overview tab**: readiness ring + grade, per-category progress bars, 4 quick-stat tiles (locations / services / markets / AI+Brand), top-5 recommended next steps from the AI audit.
- **Profile tab**: create/update the business profile (business-type dropdown populated from `Business::allowedBusinessTypes()` server-side).
- **Team tab**: member list with role/status, invite-by-email (member/viewer/admin), resend pending invite.
- **API Keys tab**: key list (prefix masked), create key with scope + one-time raw key display, revoke.
- **Audit tab**: searchable audit log with pagination and success/fail pills.

Wiring & consistency:
- Web route `GET /business-center` behind `AuthMiddleware`.
- Sidebar entry "Business Center" in the Business Intelligence group (`app/Core/Controller.php` `renderPanelSidebar`).
- All `business_center.*` i18n keys in `en/ar/fr/de`.
- New `bc-*` styles appended to `public_html/assets/css/panel.css` (readiness ring, category bars, alert box).
- Controller registered in `$optionalNewClassFiles` (public_html/index.php).

## Phase 24 — Notifications expansion

New `app/Services/BusinessNotificationService.php` — in-app notifications for business events, split into pure builders (testable offline) + thin `push()` wrapper that calls `Notification::notify` when available and fails silently otherwise:

- **Team events** wired into `BusinessTeamService`: member added (notify the added member), invite sent (notify owner), invite accepted (notify owner), member removed (notify the removed member), role changed (notify the member). `acceptInvite` also refactored to fetch the business once instead of twice.
- **API key events** wired into `BusinessApiKeyService`: create + revoke notify the business owner with the key name.
- All events link back to `/business-center` and use distinct types (`business_team_*`, `business_api_key_*`).
- Registered in `$optionalNewClassFiles`.

New `tests/Unit/Business/BusinessNotificationServiceTest.php` — **8/8** covering every builder payload + `push()` no-op safety.

## Phase 25 — Tests

New `tests/Unit/Business/BusinessServiceManagerTest.php` — **9/9** covering `slugify` (English/Arabic/special chars/empty fallback) and `generateUniqueSlug` (no conflict, numeric suffix, self-exclusion on update, per-business scoping, multi-conflict increment) via a test subclass that stubs `slugExists` with in-memory data.

Two real defects surfaced and fixed in `BusinessServiceManager`:
- `slugify` produced `cairo---giza-2026` from `"Cairo - Giza"` — now collapses consecutive hyphens.
- `slugExists` was `private`, making the slug logic untestable by extension — changed to `protected`.

`BusinessAccessServiceTest` gained `testFullCapabilityMatrix()` asserting the complete truth table (6 capabilities × 4 roles = 24 pairs); now **9/9**.

Total business test suite: **48 assertions across 6 test files, all green** — Wiring 6, Phase8912 9, Access 9, Readiness 7, Notification 8, ServiceManager 9.

## Phase 26 — Security audit fixes

Full read-only review of every business file + the shared framework pieces surfaced seven findings. Four are **in-scope and fixed**, three are **app-wide architectural decisions documented for the platform owners** (not module bugs):

**Fixed in this module:**

- **F2 (HIGH) — privilege escalation via invites**: an admin could invite another admin (and, before the RBAC refactor, worse). Now `invite()` accepts the actor's role and rejects `role=admin` unless the actor is the **owner** — enforced in **two layers**: the controller gates on the actor's role (403) *and* `BusinessTeamService::invite()` re-checks it (defense in depth), mirroring the existing `changeRole()` rule. `validate(['email' => 'required|email|max_length:255'])` re-added in the controller.
- **F3 (MED) — invite token exposure**: `BusinessMember` now lists `invite_token` / `invite_expires_at` in `$hidden`, so any accidental `toArray()` cannot leak the accept token. The explicit `memberToArray()` path in `BusinessTeamService` already filtered them — this is the second layer.
- **F5 (MED) — unbounded pending invites**: new `MAX_PENDING_INVITES = 25` cap per business in `BusinessTeamService::invite()`, preventing junk flooding of `business_members` and the owner's notification feed.
- **F6 (LOW) — XSS edge in shared `esc()`**: the shared client-side `esc()` in `panel.js` now also encodes `'` (`&#39;`), closing a single-quote-context injection gap used across the panel.

**Documented, not module-fixed (framework-wide decisions, out of scope):**

- **F1 (MED) — no CSRF tokens on `/api/business/*`**: this is the app-wide, deliberate design — all `/api/*` paths are exempted in `AuthController::csrfGuard`, and protection relies on `SameSite=Lax/Strict` cookies + CORS origin allow-list. Enforcing CSRF here alone would diverge from every other module; recommended as a platform-wide follow-up.
- **F7 (LOW) — auth token accepted via GET query param**: lives in the shared `AuthMiddleware`, used by all modules; tightening it to POST/Authorization header only is a platform change.
- **F4 (LOW) — pending invites are never emailed to the invitee**: `BusinessNotificationService` notifies the *owner*; there is no mailer channel for unregistered invitees in this app (API-first flow returns `invite_link` to the caller). Product decision, not a security defect.

New `tests/Unit/Business/BusinessSecurityTest.php` — **5/5** covering the admin-invite rule (F2, both admin and member actor roles), the pending-cap constant (F5), and token hiding (F3).

## Phase 27 — Performance audit fixes

Read-only review of every business service/controller/model + the base `Model`/`Database`/`Cache` classes surfaced the hot spots. Fixed the high-leverage ones:

- **H1 — `roleOf()` duplicated per request**: `BusinessAccessService` now memoizes the `(businessId, userId) → role` answer per instance (`$roleCache`) — the role cannot change mid-request. Every `canX()` check reuses it instead of re-running 2 queries.
- **H2 — `getAccessibleBusiness()` fetched the same Business row twice**: the Business loaded inside `roleOf()` is now kept in `$businessCache` and returned directly — one `SELECT` eliminated from every authorized request across all 9+ controllers.
- **H1-enabler — controllers now reuse one `BusinessAccessService` instance**: all 8 Business controllers gained a singleton `access()` helper (was `new BusinessAccessService()` per call, which defeated the cache).
- **M3 — dropped the redundant `currentUser()` DB query**: 7 controllers re-fetched the `users` row via `$_SESSION['user_id']` + `User::find()` even though `$this->user` (from `Controller::loadAuthenticatedUser()`) already carries `id`/`email` with **zero** queries. All now read `$this->user['id']` directly.
- **H3 — N+1 in the team list**: `BusinessTeamService::list()` now batch-loads all member users in one `SELECT ... WHERE id IN (...)` (`usersById()` helper) instead of one `User::find()` per member. A 25-member team went from 7+N queries to ~4+N.
- **M2 — API key list sorted in PHP**: `BusinessApiKeyService::list()` pushes `ORDER BY created_at DESC` into SQL and drops the `usort`.

**Verified efficient already (no action):** `BusinessAuditLog::listFor()` (real SQL `COUNT(*)` + `LIMIT/OFFSET` pagination), `BusinessContextService` (1-hour TTL cache, invalidated on every write path), `BusinessReadinessService`/`BusinessOnboardingService` (pure over cached context).

**Documented, low priority (not module-fixed):** L1 (Business row fetched twice only on cold-cache overview — the 1h cache absorbs it), L2 (slug collision retry query — 1 query typical), L3 (`BusinessIntegrationsService::getBusinessStatus()` loops 3 queries per website — fine for today's 1:1 model).

New `tests/Unit/Business/BusinessPerformanceTest.php` — **3/3** using counting stubs to prove `roleOf()` runs 2 queries once (repeat call = 0), and `getAccessibleBusiness()` issues exactly 1+1 queries while returning the correct Business.

## Phase 28 — Migration verification

Verified all **10** business migrations are **additive-only** — `CREATE TABLE IF NOT EXISTS` / `ADD INDEX` / `ADD CONSTRAINT` only; zero `DROP`/`TRUNCATE`/`ALTER COLUMN` across the set:

- `000049`–`000057` (businesses → audit_logs): `CREATE TABLE IF NOT EXISTS` with `FOREIGN KEY`s matching the app's `users.id INT(11)` / parent PK types exactly (verified column-type compatibility for every FK).
- `000058` (DB quality pass): 4 additive `ALTER TABLE` statements — the `fk_member_user` constraint correctly reuses the existing `idx_member_user` index from `000055` (no duplicate index created), and the two `ON DELETE SET NULL` FKs target already-`DEFAULT NULL` columns.

## Phase 29 — Route & config verification

- **Routes**: `app/routes/api.php` registers all 26 business endpoints behind `AuthMiddleware` (70–109), `app/routes/web.php:52` registers `GET /business-center`. Zero true duplicates — checked exact `method+path` pairs per file and cross-file (`api.php` vs `api_ADDITIONS.php`). The earlier "duplicates" were path-prefix substring overlaps (`/api/business` matching `/api/business/overview`), not real registrations.
- **Ordering**: the router matches in registration order (Router.php:194); `GET /api/business/overview` (api.php:71) is registered before the `{businessId}` param routes, so it resolves correctly.
- **Classmap**: all 21 business classes registered in `$optionalNewClassFiles` (public_html/index.php:280–320); every new controller/service/model present, no missing file (the loader guards with `file_exists`).

## Tests

- `tests/Unit/Business/BusinessCenterWiringTest.php` — **new**, 6/6: route registered, sidebar entry, classmap registration, controller exports `index()`, all five API endpoints wired in JS, translation keys complete across 4 languages.
- `tests/Unit/Business/BusinessAccessServiceTest.php` — extended with a `testSensitiveCapabilities()` case for `manage_keys` / `read_audit`; now **8/8**.
- Regressions: `BusinessCenterPhase8912Test.php` 9/9, `BusinessReadinessServiceTest.php` 7/7.
- `tests/Unit/Business/BusinessSecurityTest.php` — **new**, 5/5 (F2/F3/F5).
- `tests/Unit/Business/BusinessPerformanceTest.php` — **new**, 3/3 (H1/H2 via counting stubs).
- `php -l` clean on every touched file; route-duplication check clean (the `/sitemap.xml` double registration at HomeController:14 / AssetController:246 is pre-existing and unrelated).
- Total business suite: **56 assertions across 8 test files, all green**.

## Phase 30 — Final report

The Business Control Center module is feature-complete across all 30 phases. Summary of the final state:

- **10 migrations** (additive-only, FKs type-matched to `users.id`), **21 business classes** in the classmap, **26 API routes** + `GET /business-center` panel route, all behind `AuthMiddleware` with centralized RBAC.
- **RBAC**: 4 roles × 6 capabilities truth table lives in `BusinessAccessService::roleAllows()`; `getAccessibleBusiness()` = 404 for unauthorized, violated capability = 403.
- **Security**: Phase 26 closed privilege escalation (owner-only admin grants, two layers), token exposure, unbounded invites, and the `esc()` XSS edge; the three remaining findings (CSRF on `/api/*`, GET-param auth token, invitee emailing) are platform-wide decisions documented for follow-up.
- **Performance**: per-request role/business memoization (H1/H2), batch user loading in the team list (H3), SQL-side ordering (M2), and removal of the redundant `currentUser()` query (M3) — cutting 2–5 queries from every business endpoint.
- **Tests**: **56 assertions across 8 test files, all green** (Wiring 6, Phase8912 9, Access 9, Readiness 7, Notification 8, ServiceManager 9, Security 5, Performance 3), runnable offline in CLI PHP 8.2 with the `Model` stub pattern.
- **Frontend**: `/business-center` panel (overview/profile/team/API keys/audit tabs) with i18n in en/ar/fr/de and `bc-*` styles; in-app notifications for team + API-key events.
- Branch `feat/business-control-center` is up to date with the merged upstream `main` and pushed to remote (`7dc3099`).
