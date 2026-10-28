# Tourfecto — Business Control Center — Integrations, API Keys, Audit Log, Export/Delete, Onboarding (Phases 8-9, 12, 13-14, 15-16, 17)

**Date:** 2026-08-15
**Scope:** Remaining Business Control Center feature phases delivered on top of the merged
competitive-analysis phase and the open Team RBAC phase: Integrations Center, Business-scoped
API keys, a dedicated business audit log, export + account-deletion safety, and an onboarding
progress tracker.

---

## Design decisions, stated directly

### Integrations Center is derived state, not a new table
`BusinessIntegrationsService` builds the catalog from code and resolves each integration's
live status from existing storage (`platform_connections`, `BotSetting`, linked websites) —
no `business_integrations` table. The feature is a *view* over real connections, so it can
never drift out of sync with what is actually connected. Websites are matched via
`business_id`, falling back to `user_id` for legacy sites that were never linked to a business.

### Business API keys are separate from user keys
Keys use a `tf_bk_` prefix (`tf_pk_` / `tf_live_` are user-scoped) and are stored as a
`password_hash` + the prefix for lookup — the raw secret is returned exactly once at creation.
Scopes are `read` / `write`; `scopeAllows()` is pure PHP so it is testable offline. A business
may hold at most 10 active keys; revoking is permanent.

### Business audit log complements (does not duplicate) the user audit log
`business_audit_logs` records business-scoped events (create/update/delete of locations,
services, markets, AI context, brand settings, plus team member events) with an actor id and
`result='success'|'failed'`. Events are recorded fire-and-forget so a logging failure can never
block the underlying operation. Read access is owner/admin only — the log exposes internal
activity, so viewers do not get it (unlike integrations/onboarding, which are viewable by
viewer and above).

### Delete-account safety: ownership transfers before cascade
`BusinessAccountClosureService::prepareForAccountDeletion()` runs before the user's account is
removed. For every owned business it picks the highest-ranked **active** member
(admin > member > viewer) as successor and transfers `owner_user_id`; if no active member
exists the business is left to be cascaded away. `pickSuccessorFromMembers()` is pure logic and
unit-tested offline. `ExportUserDataJob::collectBusinessData()` gathers each owned-or-active
business with its locations/services/markets/AI context/brand/members — API key *metadata only*
(no raw secrets, no hashes) — into the existing export payload.

### Onboarding progress is derived from the context service
`BusinessOnboardingService` defines 8 steps and computes progress from the live
`BusinessContextService` context — no separate state table to go stale. The `team` step is
considered complete by default because the owner is themselves a team; each other step is
derived from real data (identity fields, contact info, locations, services, target markets,
AI context, brand settings).

### IDOR posture stays consistent
Non-accessible business => 404; accessible-but-viewer hitting an owner/admin endpoint
(api-keys, audit-log) => 403; viewer reading integrations/onboarding => 200.

---

## Files Added

- `database/migrations/2026_08_15_000056_create_business_api_keys_table.sql` — additive,
  `tf_bk_` key hash + prefix lookup column, `scope`/`status` VARCHAR validated in code, FK to
  `businesses` `ON DELETE CASCADE`, unique `uq_business_keyhash`.
- `database/migrations/2026_08_15_000057_create_business_audit_logs_table.sql` — additive,
  event/entity/actor FKs (`CASCADE`), `metadata` JSON column, `result` VARCHAR, indexes on
  `(business_id, created_at)`.
- `app/Models/BusinessApiKey.php` — generation (`tf_bk_` + random suffix), hashing, `verify()`,
  `scopeAllows()` (pure), `allowedScopes()`.
- `app/Models/BusinessAuditLog.php` — `record()` (fire-and-forget) and `listFor()` with
  filters + pagination (cap 100 rows per call).
- `app/Services/BusinessIntegrationsService.php` — integration catalog + `mergeStatuses()`
  (pure OR-combining across sites) + per-business status resolution.
- `app/Services/BusinessApiKeyService.php` — list/create/revoke rules (10-key cap) + audit
  events.
- `app/Services/BusinessAuditService.php` — unified event constants + `actionLabels()`
  (pure, offline-testable) for human-readable labels.
- `app/Services/BusinessOnboardingService.php` — 8-step definition + `progressFromContext()`
  + `isStepCompleted()` (pure).
- `app/Services/BusinessAccountClosureService.php` — `pickSuccessorFromMembers()` (pure) +
  `prepareForAccountDeletion()` (DB-backed ownership transfer).
- `app/Controllers/BusinessApiKeyController.php` — `index` / `store` / `revoke`.
- `app/Controllers/BusinessAuditLogController.php` — `index`.
- `app/Controllers/BusinessIntegrationsController.php` — `index`.
- `app/Controllers/BusinessOnboardingController.php` — `status`.
- `tests/Unit/Business/BusinessCenterPhase8912Test.php` — 9 offline tests covering
  `scopeAllows`, `actionLabels`, onboarding progression (empty / partial / complete / step
  checks / next-step ordering), successor picking, and `mergeStatuses` OR logic. Uses a stub
  `Model` class so `BusinessApiKey` can be loaded with no DB.

## Files Modified

- `app/routes/api.php` — 6 new routes:
  `GET /api/business/{businessId}/integrations`,
  `GET /api/business/{businessId}/api-keys`,
  `POST /api/business/{businessId}/api-keys`,
  `POST /api/business/{businessId}/api-keys/{id}/revoke`,
  `GET /api/business/{businessId}/audit-log`,
  `GET /api/business/{businessId}/onboarding`.
- `app/Controllers/BusinessController.php` — `business_created` / `business_updated` audit
  events on create/update.
- `app/Controllers/BusinessLocationController.php`, `BusinessServiceController.php`,
  `BusinessTargetMarketController.php`, `BusinessAiContextController.php`,
  `BusinessBrandSettingsController.php` — audit events (`*_created` / `*_updated` / `*_deleted`)
  on every write action.
- `app/Controllers/BusinessTeamController.php` — `member_invited` / `member_joined` /
  `member_removed` / `member_role_changed` audit events.
- `app/Controllers/UserController.php` — `deleteAccount()` now calls
  `BusinessAccountClosureService::prepareForAccountDeletion()` before removal.
- `app/Jobs/ExportUserDataJob.php` — `collectBusinessData()` includes businesses the user owns
  or actively belongs to, with locations/services/markets/AI context/brand/members and API key
  metadata.
- `public_html/index.php` — registered `BusinessApiKey`, `BusinessAuditLog`,
  `BusinessIntegrationsService`, `BusinessApiKeyService`, `BusinessAuditService`,
  `BusinessOnboardingService`, `BusinessAccountClosureService` + the four new controllers in the
  manual autoload list.

## Tests Passed

- `php tests/Unit/Business/BusinessCenterPhase8912Test.php` — 9/9 passed (offline, pure logic).
- `php tests/Unit/Business/BusinessAccessServiceTest.php` — 7/7 passed (regression check).
- `php tests/Unit/Business/BusinessReadinessServiceTest.php` — 7/7 passed (regression check).
- Route dispatch verified: 440 registrations total, zero method+path duplicates; all 30
  business routes unique.
- `php -l` clean on every new/modified file.

DB-backed flows (API key creation against the table, audit writes, ownership transfer,
integration status resolution against real connections) require the server runtime, consistent
with every prior phase.

## Honest limitations / next steps

- API keys are not yet usable from an auth middleware — only issued/stored/revoked. Wiring a
  `BusinessApiKey` auth path for partner integrations is a natural follow-up phase.
- Audit log reads are owner/admin only by design; a future phase could offer viewer-safe
  summaries if product needs them.
- `collectBusinessData()` does N+1 user lookups while enumerating members — acceptable for the
  rare export job; a JOIN is a later optimization.
- Remaining optional phase groups: 20-30 (DB quality, validation, frontend, tests, security,
  performance, final verification).
