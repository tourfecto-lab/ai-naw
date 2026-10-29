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
| 1 | AI Executive Foundation | **COMPLETED** (verified by the owner on tourfectoai.pro, 2026-10-29) |
| 2 | AI Cost & Quality Intelligence | **Implemented** — see below (awaiting owner check of the cost section) |
| 3 | Global Tourism Business Intelligence | **Implemented** — see below (awaiting owner check of the Companies tab) |
| 4 | Autonomous Growth & Sales | **Implemented (partial scope, see below)** — awaiting owner setup and check |
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
- **Verified by the owner (admin account):**
  - `/admin/ai-ceo` loads with real data: every tile has a value and no source is missing.
  - The chat answered "إيه اللي حصل النهارده؟" from the numbers.
- **Hourly cron:** installed in aaPanel as `tourfecto-ai-ceo-cycle` (user www, minute 40). First run: `7 open decisions, 0 auto-approved`, with no unavailable sections.
- **Fix after review (PR #28):** the page root is now `dir=ltr`. English lines rendered reversed inside the RTL admin shell.
- **Real figures at the time** (not invented):
  - MRR $0 (it was $573.17 thirty days earlier) and 0 paying customers (2 before), so 30-day churn shows 100%.
  - 1 customer at risk, with $374.17 MRR (`past_due`).
  - 2 active trials, both ending within 24 hours.
  - Platform health FAIL: 8 failing checks and 4 warnings.
  - 86 errors in 24 hours.
  - 637 outreach companies, 115 of them waiting for approval.

**Owner actions after Phase 1**
1. Open `/admin/ai-ceo`. Check the overview tiles, then ask the six questions in the chat. Any tile showing "—" means that source failed to load; report which one.
2. Optional: add the hourly cron in aaPanel:
   `cd /www/wwwroot/tourfectoai.pro && PHP=$(command -v php || ls -d /www/server/php/*/bin/php | sort -V | tail -1) && $PHP cron/ai_ceo_cycle.php`

## Phase 2 — AI Cost & Quality Intelligence

**Reused (not rebuilt)**
- **Routing:**
  - `AIOrchestrator` provides the task → provider chain, the circuit breaker and the fallback when a provider fails.
  - `AiServiceRouting` and `/admin/ai-routing` assign the model per task.
- **Limits and cost:**
  - `AIGovernor` enforces the per-company limits (set in `/admin/brain`).
  - `api_usage_logs` holds every call, with `feature` = task, `model`, cost and duration.
  - `AIUsageStatsService` serves the existing cost page.

**New**
- `app/Services/AiCeo/AiCostIntelligence.php`:
  - Spend today, usual day (7-day average), month, and a month-end projection at the current pace.
  - Cost per task, per model (cost per request, average tokens, average latency) and per account.
  - **Anomalies:** a task or account spending more than 3× its usual day, and at least $0.50.
  - **Routing review:** the same task served by two models where one costs more than 3× per request (5+ requests each). This only produces a recommendation and never changes routing.
  - **Budgets** in `system_settings`:
    - `ai_platform_budget_usd_month`: an alert threshold, unset by default.
    - **A separate AI CEO budget:** `aiceo_ai_budget_usd_day` = $1 and `aiceo_ai_budget_usd_month` = $20 by default. AI CEO spend counts only `feature LIKE 'ai_ceo%'`.
  - **Measured savings:** AI CEO answers given from the numbers or the cache, multiplied by the real average cost of a paid AI CEO call. It shows "—" until there is at least one paid call. Nothing is projected.
- **Chat:**
  - It checks the AI CEO budget before calling AI. When the budget is reached it says so, with no call.
  - The same question on the same snapshot within 30 minutes is answered from memory (source `cache`) with no call.
  - Answers that failed are not saved, so they are never served from the cache.
- **Decisions:**
  - `ai_budget_projection`: projected spend ≥ 90% of the budget. Risk low.
  - `ai_usage_anomaly`: risk low.
  - `ai_routing_review`: risk **medium**, because changing a model affects customer quality, so it needs approval and a quality test first.
  - `aiceo_budget_reached`.
- **API:**
  - `GET /api/admin/ai-ceo/costs`
  - `POST /api/admin/ai-ceo/budgets` (owner only; audited as `budgets_changed`)
- **Page:** a new "AI Cost Optimization" section in Mission Control (`#costs`). It has tiles, unusual usage, the routing review, cost tables by task, model and account, and the budget form.
- **Tests:**
  - `tests/Unit/AiCostIntelligenceTest.php`
  - `tests/Integration/AiCostIntelligenceIntegrationTest.php`: the AI CEO budget is separate from customers and stops at the limit; invalid budgets are rejected.
  - New cases in `PlatformDecisionsTest` and `AiCeoChatTest` for the budget guard and the cache.

**Known limits (stated on the page)**
- Failed provider calls are not written to `api_usage_logs`, so failure rates are not measured here. Provider outages show in platform health and in the orchestrator's circuit breaker.
- There are no automatic per-task quality scores yet. Quality is protected by keeping model changes behind approval and a sample test. A per-task quality test harness would need paid calls, so it is deferred and needs the owner's approval to spend.
- Batch processing and "do not re-analyse unchanged data" already exist inside individual services (for example the SEO cycles and the market TTLs). No new generic layer was added, because it would duplicate them.

**Owner action pending:** set a monthly platform AI budget in AI Costs.

## Phase 3 — Global Tourism Business Intelligence

**Reused (not rebuilt):** `platform_leads` and `PlatformOutreachService`. They already provide:
- import from Apify, URL, JSON or CSV;
- dedup on import by email and domain, and filling only missing fields on re-import;
- skipping existing customers;
- site analysis (`OutreachAudit` → `data_json.audit`, `needs_json`, `track`, `score` = lead score);
- the message history (`platform_lead_messages`);
- unsubscribe links;
- `signed_up` matching.

**New (additive)**
- Migration `2026_10_29_000007_tourism_business_intelligence.sql` adds:
  - `tbi_reports`: analysis history.
  - `tbi_consent_events`: opt_in, objection, unsubscribe, do_not_contact and erasure, per channel.
  - `tbi_suppression`: SHA-256 hashes of erased email, domain and phone.
  - `tbi_merges`: reversible merges.
- `app/Services/AiCeo/TourismBusinessDb.php`:
  - search, filters and segments, and stats;
  - a full profile: socials, likely services, opportunities, latest analysis, saved reports, contact history, consent log, merges, and whether the company became a customer (read-only match on users and websites);
  - `captureReports()` copies each new analysis into history, so the previous one is never overwritten;
  - `recordConsent()`: any objection or do-not-contact sets `status = 'unsubscribed'`, which the existing sender already stops on;
  - `backfillUnsubscribes()`;
  - `erase()` wipes contact data, notes and raw data, and keeps only hashes;
  - `isSuppressed()`;
  - `duplicates()` matches on the same domain, the same phone, or the same name in the same city;
  - `merge()` and `undoMerge()`.
- `PlatformOutreachService::importRows` skips suppressed companies. This is a guarded two-line hook, and nothing else in that service changed.
- API (admin): `GET /api/admin/ai-ceo/companies`, `/companies/{id}`, `/companies-duplicates`. POST `/companies/{id}/consent` is for any admin, because it is protective. POST `/companies/{id}/erase` and `/companies-merge` (including undo) are owner only. Everything is audited.
- Cron: `cron/ai_ceo_cycle.php` also runs `captureReports()` and `backfillUnsubscribes()` every hour.
- Mission Control has a new **Tourism Companies** tab: stats, filters, results, a profile panel with consent recording and erase, and duplicates with merge.
- Tests: `tests/Integration/TourismBusinessDbIntegrationTest.php`. It covers search, the report history, objection stopping contact, erasure blocking re-import, merge and undo, and no writes to users.

**Compliance notes**
- Published contact details are **not** treated as marketing consent. Explicit consent is recorded only as an `opt_in` event.
- Use only the Apify accounts the owner is licensed to use. Multiple accounts must not be used to bypass provider limits. The platform's Apify token is configured in platform outreach.
- The lawful basis per market (US CAN-SPAM, EU GDPR/ePrivacy, Gulf) is enforced in Phase 4 before any campaign.

## Phase 4 — Autonomous Growth & Sales

**Reused:** `PlatformOutreachService`. It already provides:
- need-based email sequences per company;
- admin approval before the first message;
- a daily limit;
- unsubscribe links and the company address in the footer;
- open, click, report-view and WhatsApp-click tracking;
- per-track and per-country stats;
- `signed_up` matching.

The owner's CRM is reused through `CrmLeadService`.

**New**
- `app/Services/AiCeo/SalesCompliance.php` is a pure, conservative lawful-basis gate. It is not legal advice.
  - **Regions:** us, ca, eu (EU/EEA/UK/CH), gcc, other.
  - **Always required:** an email address, no objection, unsubscribe or erasure on record, and a real postal address in outreach settings. The default "Tourfecto · Cairo, Egypt" is not accepted.
  - **US:** CAN-SPAM opt-out, plus the owner's confirmation for the region.
  - **CA:** needs a recorded opt-in.
  - **EU, GCC and other:** the owner confirms the basis for the region, and only generic company addresses (info@, booking@ …) are used. Personal addresses need an opt-in.
  - **DE and AT:** always need an opt-in.
  - A recorded email opt-in passes any region. WhatsApp marketing only ever goes out with an opt-in on WhatsApp.
  - **ICP fit (0–100):** a target market adds 40, a tour or travel category 20, the analysis score scaled to 30, and a website 10.
- `PlatformOutreachService::approve()` now passes every approval through the gate, including the existing `/admin/growth` buttons. It fails closed: if the check errors, nothing is approved.
- `app/Services/AiCeo/SalesEngine.php`:
  - settings: basis per region, target markets, and monthly spend for CAC;
  - the approval queue with the gate verdict and ICP fit;
  - `approveCompliant`;
  - `reevaluateApproved`: approvals made before the gate that are not compliant go back to the queue. It runs in the hourly cron;
  - `activeNonCompliant` and `pauseSequences`, owner only;
  - the pipeline (discovered → contactable → approved → contacted → engaged → interested → signed up → paying) by region and by source, with conversion rates and CAC (only when the owner enters spend);
  - `handoffToCrm` moves replied or interested companies into the platform owner's CRM, once per company (`tbi_crm_handoffs`). It creates a contact and a deal with a follow-up the next day;
  - daily and weekly sales reports (weekly on Mondays), written to memory by the cron.
- Migration `2026_10_29_000008_ai_ceo_sales.sql` adds `tbi_crm_handoffs`.
- **Decisions:**
  - `sales_postal_address`
  - `sales_basis_setup`
  - `sales_compliant_ready` (medium)
  - `sales_noncompliant_sequences` (medium)
  - `sales_crm_handoff`
- **API:** `GET /api/admin/ai-ceo/sales` and `POST /api/admin/ai-ceo/sales/{settings|approve|reevaluate|pause|crm}`. `reevaluate` is protective, so any admin can run it; the rest are owner only. Everything is audited.
- **Mission Control:** a new **Leads & Sales** tab.
- **Tests:**
  - `tests/Unit/SalesComplianceTest.php`
  - `tests/Integration/SalesEngineIntegrationTest.php`: approval from the outreach service goes through the gate; earlier non-compliant approvals return to the queue; the pipeline; the CRM handoff happens once; reports are written once.

**Important behaviour change for the owner**
Until the postal address is set and at least one region's basis is confirmed, **no new first messages are approved**. Sequences already running continue until the owner reviews them in "Running sequences the gate now rejects".

**Not built in this phase (needs owner decisions, inputs or integrations)**
- The AI sales assistant answering inbound replies and handling objections. It needs an inbound mailbox integration (IMAP or forwarding) and the owner's approval of the tone and limits.
- A/B tests of templates. They need changes in the sender and a minimum sample per variant.
- A referral program. It needs a product, pricing and billing decision.
- Partnership discovery.

**Next: Phase 5** — Tourfecto Brand Growth Engine.
