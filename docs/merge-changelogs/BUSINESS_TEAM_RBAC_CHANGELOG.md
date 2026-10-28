# Tourfecto — Business Control Center — Team Management + RBAC (Phases 10-11)

**Date:** 2026-08-15
**Scope:** Multi-user access to a Business (previously: 1 user = 1 Business, owner-only) plus a
central, extensible role-based access control layer enforced across every business endpoint.

This closes the single biggest architectural gap flagged in the original 30-phase request:
*"no concept of multiple users per business anywhere in the schema."*

---

## Design decisions, stated directly

### Owner is not a member row
`businesses.owner_user_id` remains the single source of truth for ownership. `business_members`
stores only non-owner members (`admin` / `member` / `viewer`). This avoids ever having two
conflicting records that both claim "owner" and keeps the existing tables untouched.

### Role model
| Role | View | Edit business data | Manage team (invite/remove/role) | Admin-level team ops |
|---|---|---|---|---|
| owner | yes | yes | yes | yes |
| admin | yes | yes | yes | no (owner only) |
| member | yes | yes | no | no |
| viewer | yes | no | no | no |

- `admin` cannot remove or re-role another `admin`; only the owner can.
- Assigning/revoking the `admin` role requires the owner (`administer_team`).
- You cannot add the owner as a member (duplicate authority).

### Centralized enforcement (RBAC in one place)
`BusinessAccessService` is now the only place that answers "what can this user do on this
business?" — the six earlier controllers each had their own `loadOwnedBusiness()` +
`isOwnedBy()` copy, which is exactly how authorization drift starts. All six were refactored to
call it. The capability map (`roleAllows`) is pure PHP (no DB) so it is unit-testable offline,
mirroring the `BusinessReadinessService` / `SsrfGuard` test pattern.

### Invitation flow
- Registered user's email → membership granted immediately (`status='active'`).
- Unregistered email → pending invite row (`status='invited'`, random 64-hex token, 7-day
  expiry). On accept, the logged-in user's email must match the invite email (prevents
  hijacking), then `user_id` is set and the row becomes `active`.
- `uq_business_user (business_id, user_id)` blocks duplicate membership; the service also
  blocks duplicate pending invites per email.

### IDOR posture
Same principle as before: non-accessible business => 404 (don't reveal existence). A `viewer`
who legitimately *can* see the business gets **403** on write attempts, not 404.

---

## Files Added

- `database/migrations/2026_08_15_000055_create_business_members_table.sql` — additive
  (`CREATE TABLE IF NOT EXISTS`), unique keys on `(business_id, user_id)` and `invite_token`,
  FK to `businesses` `ON DELETE CASCADE`. `role`/`status` are VARCHAR (validated in code), not
  ENUM — same extensibility rule as the rest of the module.
- `app/Models/BusinessMember.php` — `isActive()` / `isPending()` helpers.
- `app/Services/BusinessAccessService.php` — the RBAC core: `roleOf()`, `canView()`,
  `canEdit()`, `canManageTeam()`, `canAdministerTeam()`, `getAccessibleBusiness()`,
  `resolveUserBusiness()` (owner-first, then first active membership — lets a whole team open
  `/api/business` and `/api/business/overview`), plus pure `roleRank()` / `roleAllows()` /
  `allowedMemberRoles()`.
- `app/Services/BusinessTeamService.php` — invite / acceptInvite / remove / changeRole / list,
  with the fine-grained rules (admin-vs-admin, admin-role ownership) centralized here.
- `app/Controllers/BusinessTeamController.php` — `index` / `invite` / `acceptInvite` / `remove`
  / `changeRole`, all AuthMiddleware-protected with real validation and RBAC gates.
- `tests/Unit/Business/BusinessAccessServiceTest.php` — offline tests of the pure role logic.

## Files Modified

- `app/routes/api.php` — 5 new team routes:
  `GET /api/business/{businessId}/team`,
  `POST /api/business/{businessId}/team/invite`,
  `POST /api/business/{businessId}/team/invite/{token}/accept`,
  `DELETE /api/business/{businessId}/team/members/{memberId}`,
  `PUT /api/business/{businessId}/team/members/{memberId}/role`.
- `app/Controllers/BusinessController.php` — `show()`/`overview()` now resolve the user's
  business via `resolveUserBusiness()` (owner or active member); `update()` uses the access
  service and returns 403 for viewers.
- `app/Controllers/BusinessLocationController.php`, `BusinessServiceController.php`,
  `BusinessTargetMarketController.php`, `BusinessAiContextController.php`,
  `BusinessBrandSettingsController.php` — all `loadOwnedBusiness()`/`loadOwnedLocation()`/
  `loadOwnedService()` ownership checks replaced with `BusinessAccessService`; write actions
  add an explicit `canEdit()` gate (403 for viewers).
- `public_html/index.php` — registered `BusinessMember`, `BusinessAccessService`,
  `BusinessTeamService`, `BusinessTeamController` in the manual autoload list.

## Tests Passed

- `php tests/Unit/Business/BusinessAccessServiceTest.php` — 7/7 passed (offline, pure logic).
- `php tests/Unit/Business/BusinessReadinessServiceTest.php` — 7/7 passed (regression check).
- Route dispatch verified for all 5 team endpoints + existing business endpoints — no conflicts,
  no duplicate registrations.
- `php -l` clean on every new/modified file.

DB-backed flows (role resolution, invites against real tables) require the server runtime,
consistent with every prior phase.

## Honest limitations / next steps

- Invitations are returned as an API link, not emailed yet — notification wiring is a separate
  phase (Phase 19 Notifications expansion).
- Membership list resolution does N+1 user lookups — fine for small teams; a JOIN query is a
  later optimization if teams grow.
- Ownership transfer / Delete Account handling for businesses is still the deferred Phase 15-16.
