# TOURFECTO AI CEO — Implementation progress

Read this first when resuming. The directive is 10 controlled phases: one phase at a time, and the next phase starts only after the owner writes **"كمل المرحلة التالية"**.

## Environments and rules (short)
- Dev: `tourfectoai.pro`, auto-deployed from `main`. Production: `tourfecto.pro`, manual. **Nothing goes to production without approval.**
- Dev and production currently share the database `aa` (confirmed by the owner). Schema changes are **additive only**.
- Other Claude sessions work on Creative Studio (`app/Jobs/GenerateVideoJob.php`, `app/Services/CreativeStudio/*`) and chat. Do not touch those files.
- CI note: since `810e11a`, the *Lint + static analysis* job fails on `app/Jobs/GenerateVideoJob.php:48` ("variable might not be defined"). That comes from the Creative Studio session, not from AI CEO work. PHPUnit and PHPStan wide pass.

## Phase status
| # | Phase | Status |
|---|---|---|
| 1 | AI Executive Foundation | **Done** — see below |
| 2 | AI Cost & Quality Intelligence | Not started |
| 3 | Global Tourism Business Intelligence | Not started |
| 4 | Autonomous Growth & Sales | Not started |
| 5 | Tourfecto Brand Growth Engine | Not started |
| 6 | Customer Success & Revenue Intelligence | Not started |
| 7 | AI Platform Operations & Development Advisor | Not started |
| 8 | Financial & Strategic Intelligence | Not started |
| 9 | Executive Intelligence & Continuous Learning | Not started |
| 10 | Final Integration & Controlled Activation | Not started |

## Phase 1 — AI Executive Foundation

**Design**
- The AI CEO extends the customer Strategic Brain pattern (snapshot → decisions with evidence → approve/measure). It reuses `BrainSchema` (runtime table creation), `BrainConfig` (settings in `system_settings`), `AIOrchestrator` (chat model routing and fallback) and the existing admin services.
- It is **separate from the customer brain**: its own tables `aiceo_*` and no customer `user_id`. It never writes to `brain_decisions` or any customer table.
- Access:
  - Viewing, chat, notes and the emergency stop: admins with `system.manage`.
  - Approvals, changing the mode and resuming after a stop: the **platform owner** only (`super_admin`, or `AdminAccess::isPrimaryOwner`).

**Files**
- `database/migrations/2026_10_29_000006_ai_ceo_foundation.sql` creates three tables:
  - `aiceo_decisions`: decisions, tasks and approvals.
  - `aiceo_memory`: institutional memory (decision, plan, result, note, chat).
  - `aiceo_activity`: the audit log.
- `app/Services/Brain/BrainSchema.php` ensures the three tables at runtime.
- `app/Services/Brain/BrainConfig.php` adds two keys:
  - `aiceo_auto`: 0 = APPROVAL mode (default), 1 = AUTO mode.
  - `aiceo_paused`: the emergency stop.
- `app/Services/AiCeo/PlatformSnapshot.php` builds the company picture from existing services:
  - SaasMetrics, TrialFollowUp, CustomerHealth, PlatformHealth, ErrorLog, AIUsageStats and PlatformOutreach, plus sign-ups.
  - A section that cannot be read is `null`; it is never guessed.
- `app/Services/AiCeo/PlatformDecisions.php` is the pure decision engine (Strategic Radar).
  - **Priority** = 10 × (0.30 impact + 0.20 roi + 0.25 urgency + 0.10 confidence + 0.05 (6−risk) + 0.05 (6−cost) + 0.05 (6−effort)), with every factor scored 1–5. The result ranges from 10 to 50.
  - **Risk levels:**
    - `low`: internal work. Only these can be auto-approved.
    - `medium`: contacting customers or companies. Always needs approval.
    - `high`: money, pricing, permissions or production. Always needs approval.
- `app/Services/AiCeo/AiCeoStore.php` handles:
  - sync, resolve and reopen. A decision marked done gets 24 hours before it can reopen.
  - owner status changes.
  - `autoExecute` (AUTO mode, low risk only, at most 5 per cycle, blocked by the emergency stop).
  - memory and the audit log.
- `app/Services/AiCeo/AiCeoChat.php` is the chat in Arabic (Egyptian) or English.
  - The six owner questions are answered **from the numbers with no AI call**:
    - today
    - top problem
    - why subscriptions dropped
    - what needs development
    - best country
    - what is needed from me
  - Other questions go to `AIOrchestrator` (task `ai_ceo_chat`, 700 tokens) with a "data only, never invent" prompt.
  - No AI call is made during the emergency stop.
- `app/Controllers/AiCeoController.php` serves the `/admin/ai-ceo` page and these APIs:
  - `GET /api/admin/ai-ceo` (the snapshot is cached for 10 minutes in `system_settings.aiceo_snapshot`; `?refresh=1` recomputes it)
  - `POST /api/admin/ai-ceo/{decisions/{id},mode,pause,chat,memory}`
  - All POSTs check CSRF and a rate limit.
- `public_html/assets/js/admin-ai-ceo.js` is the Mission Control page (English UI). It has the controls (mode and emergency stop), Executive Overview, AI CEO Chat, the Strategic Radar with tasks and approvals, Institutional Memory and the Audit Log. Its styles are added to `public_html/assets/css/brain.css`.
- `cron/ai_ceo_cycle.php` is an optional hourly job. It makes no AI calls, does nothing while stopped, and in AUTO mode approves low-risk decisions only.
- Navigation: "AI Mission Control" in the admin sidebar, General group.
- Tests:
  - `tests/Unit/PlatformDecisionsTest.php`
  - `tests/Unit/AiCeoChatTest.php`
  - `tests/Integration/AiCeoStoreIntegrationTest.php` (modes, emergency stop, resolve, memory and audit log, and no writes to `brain_decisions`)

**What "execute" means in Phase 1**
Approving a decision turns it into an in-progress task with a link to the existing admin page that carries it out, and records it in memory and the audit log. External actions (emails, WhatsApp, ads, publishing) are **not** executed by the AI CEO yet; they come in later phases, behind approval.

**Test results (2026-10-29)**
- CI (PR #26):
  - PHPUnit on MariaDB 10.11: **pass**. This includes the 3 new test files: decisions, chat, and store with isolation.
  - PHPStan wide: **pass**.
  - Lint job: fails only on the Creative Studio file noted above.
- Live on tourfectoai.pro with a customer account:
  - `/admin/ai-ceo`, `GET /api/admin/ai-ceo` and `POST /api/admin/ai-ceo/chat` → **403**.
  - The customer brain `/api/brain` → 200 with 9 decisions, **unaffected**.
- **Not verified yet:** the Mission Control page with real platform data. It needs the owner's admin account, so the owner must open `/admin/ai-ceo` and report. The hourly cron is not installed yet.

**Owner actions after Phase 1**
1. Open `/admin/ai-ceo`. Check the overview tiles, then ask the six questions in the chat. Any tile showing "—" means that source failed to load; report which one.
2. Optional: add the hourly cron in aaPanel:
   `cd /www/wwwroot/tourfectoai.pro && PHP=$(command -v php || ls -d /www/server/php/*/bin/php | sort -V | tail -1) && $PHP cron/ai_ceo_cycle.php`

**Next: Phase 2** — AI Cost & Quality Intelligence. Reuse `AIGovernor` (limits), `AiServiceRouting` and `/admin/ai-routing` (models), `AIUsageStatsService` (cost) and `SemanticCache`. Add per-task quality checks, a separate AI CEO budget, anomaly alerts and the savings dashboard.
