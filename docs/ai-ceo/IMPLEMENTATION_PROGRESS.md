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
| 5 | Tourfecto Brand Growth Engine | **Implemented (partial scope, see below)** — awaiting owner check of the Brand Growth tab |
| 6 | Customer Success & Revenue Intelligence | **Implemented (partial scope, see below)** — awaiting owner check of the Customer Success tab |
| 7 | AI Platform Operations & Development Advisor | **Implemented (partial scope, see below)** — awaiting owner check of the Platform & Dev Advisor tab |
| 8 | Financial & Strategic Intelligence | **Implemented (partial scope, see below)** — awaiting owner costs entry and check of the Finance & Strategy tab |
| 9 | Executive Intelligence & Continuous Learning | **Implemented (partial scope, see below)** — awaiting owner check of the Business Health & Reports tab |
| 10 | Final Integration & Controlled Activation | **Implemented** — acceptance tests pass; activation is staged and controlled by the owner (see below) |

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

## Phase 5 — Tourfecto Brand Growth Engine

**Reused:**
- `visitor_logs` (from `VisitorTrackingMiddleware`) for traffic and sign-up sources;
- `MarketingContent::features()`: the public feature pages are the only list of services the AI may mention;
- the platform's own websites, reviews, connections and social posts (for Tourfecto's presence on its own platform);
- `AIOrchestrator` with the AI CEO budget guard from Phase 2.

**New**
- `app/Services/AiCeo/BrandSiteAudit.php`: a free SEO/AEO check of the Tourfecto website itself (`brand_site_url` setting, otherwise `APP_URL`).
  - It reads robots.txt and the sitemap (one level of sitemap index) and checks up to 40 pages on the same host. Redirects are followed only on the same host.
  - **Per page:** HTTP status, speed, title, meta description, H1, canonical, noindex, JSON-LD, Open Graph, word count and internal links.
  - **Site level:** sitemap, robots → sitemap, `/llms.txt`, and duplicate titles.
  - **Score (0–100):** each critical issue costs 25, each warning 8 and each info 2, averaged per page; site issues are subtracted in full.
  - Every run is saved in `aiceo_brand_audits` (migration `2026_10_29_000009_ai_ceo_brand.sql`) so improvement is measured against the previous score.
  - It runs weekly from `cron/ai_ceo_cycle.php` and on demand ("Run audit now", any admin, twice per hour).
- `app/Services/AiCeo/BrandGrowth.php`:
  - `acquisition(30)`: visitors by landing page, referrer and country, from public pages only. A customer sign-up is credited to the first page and referrer of the visitor who signed up. Sign-ups with no tracked visit are counted separately.
  - `presence()`: the Tourfecto website inside an admin account (connections, Google reviews, social posts).
  - `monthlyPlan()`: an AI plan per market (8 posts, 2 articles, 1 video, plus Google/Meta ad **drafts**). It is owner only and runs on the AI CEO budget (task `ai_ceo_brand_plan`). It uses only listed features plus the sign-up data. Promises such as "guaranteed", "#1", "first page" and "نضمن" are flagged for removal. The plan is saved in memory (kind `plan`). Nothing is published, and no ad money is spent.
- **Snapshot section `brand` and decisions** (all low risk):
  - `brand_audit_first`
  - `brand_site_critical`
  - `brand_no_conversions` (50 or more visitors and 0 sign-ups in 30 days)
  - `brand_site_not_in_platform`
- **API:**
  - `GET /api/admin/ai-ceo/brand`
  - `POST /api/admin/ai-ceo/brand/audit`
  - `POST /api/admin/ai-ceo/brand/plan` (owner only)
  - Everything is audited.
- **Mission Control:** a new **Brand Growth** tab.
- **Tests:**
  - `tests/Unit/BrandGrowthTest.php`: page analysis, score, path/host, promise warnings.
  - `tests/Integration/BrandGrowthIntegrationTest.php`: sign-up attribution and admin-page exclusion; audit on a fake site (other hosts skipped, improvement measured); decisions; the plan with a fake AI and rejection of non-JSON output and bad months.

**Not built in this phase (needs owner decisions, accounts or integrations)**
- Publishing the plan automatically to Tourfecto's own social accounts. It needs the Tourfecto pages connected in the owner account; until then the plan is a draft for the owner.
- Running paid ads. Paid ads stay off; only drafts are written.
- Search Console and GA4 data for tourfecto.pro (queries, impressions). This needs the Google verification to finish and a connection for the owner's own property.
- Measuring AI answer engines (ChatGPT, Perplexity, Gemini) mentioning Tourfecto. The platform's AI visibility service could do it, but it costs API credits and needs the owner's approval of a budget.

## Phase 6 — Customer Success & Revenue Intelligence

**Reused:** `SaasMetricsService` (MRR, ARR, ARPU, churn, LTV, trial conversion), `CustomerHealthService` (0–100 health score per customer: activity 30, connections 20, usage 20, payment 20, setup 10, with reasons and 30-day profit), `SalesEngine` (CAC), `contact_submissions` (support messages), `payment_transactions`, `subscriptions`, `api_usage_logs`.

**New:** `app/Services/AiCeo/CustomerSuccess.php`
- **Journey (last 90 days of sign-ups):** signed up → added a website → connected a service → used a service → trial → paying, with the biggest drop.
- **Onboarding assistant:** customers stuck 3+ days get their next step and a suggested Arabic/English message. Nothing is sent; the owner reviews and sends.
- **Payments:** attempts by status, failure rate, attempts stuck over 1 hour, and past-due customers.
- **Renewals:** renewals in 30 days and the ones set to cancel at period end.
- **Cancellations:** reasons and themes over 180 days, by month.
- **Support:** messages, unanswered, from paying customers, and topic themes (login, payment, WhatsApp, Facebook/Instagram, Google, SEO, booking, bug, pricing). Topics with 3+ messages become a development decision. Support cost is computed only when the owner enters minutes per message and cost per hour.
- **Upsell and cross-sell:** healthy heavy users (top quartile of AI actions) and paying customers with no connected service.
- **Services:** usage, customers, paying adoption and AI cost per service (AI task), excluding the AI CEO's own tasks.
- **Plans and pricing:** margin per plan after AI cost. Suggestions for low margin (under 50%), unused plans, and plans below half the market median (owner-entered). **Suggestions only; nothing is changed.**
- **Forecast:** MRR at 3/6/12 months in three cases (conservative: churn ×1.5 and new ×0.5; expected; optimistic: churn ×0.5 and new ×1.5), with the assumptions and the confidence (low under 10 paying customers).
- **Snapshot section `success` and decisions** (all low risk):
  - `cs_onboarding_stuck`
  - `cs_payment_problems`
  - `cs_renewals_cancelling`
  - `cs_support_unanswered`
  - `cs_recurring_complaints`
  - `cs_upsell_ready`
- **API:**
  - `GET /api/admin/ai-ceo/success`
  - `POST /api/admin/ai-ceo/success/settings` (owner only, audited)
- **Mission Control:** a new **Customer Success** tab.
- **Tests:**
  - `tests/Unit/CustomerSuccessTest.php`
  - `tests/Integration/CustomerSuccessIntegrationTest.php`: every section loads on the real schema; stuck customer, failed payments, cancelling renewal, unanswered and recurring support topics; settings validation; decisions.

**Not built in this phase**
- Sending onboarding messages automatically. It needs the owner's approval of the wording and channel, and WhatsApp needs opt-in.
- Satisfaction surveys (NPS/CSAT). There is no survey data yet; it needs a decision on when and how to ask.
- Market-based price changes. Prices are never changed automatically; market data is the owner-entered median until competitor pricing is collected (Phase 9).
- A support ticket system. Support today is the contact form only.

## Phase 7 — AI Platform Operations & Development Advisor

**Reused:**
- `PlatformHealthService`: cron, integrations, mail, queue, payments, server and database schema checks;
- `ErrorLogService`: grouped errors;
- `api_usage_logs`: provider calls, status codes and timings;
- `login_history`;
- `tools/backup.php` and `tools/restore_backup.php`: encrypted backups.

**New:** `app/Services/AiCeo/OpsAdvisor.php`, plus migration `2026_10_29_000010_ai_ceo_ops.sql` (`aiceo_ops_checks`, `aiceo_incidents`, `aiceo_dev_backlog`).
- **Page checks (synthetic):** every cron cycle, and on demand, the platform's own pages are opened without login.
  - It covers 14 public pages, including `/api/health`, and 11 protected pages.
  - Public pages must return 200. Protected pages must redirect or answer 401/403.
  - A 5xx or no answer counts as down. A protected page answering 200 is flagged as a security warning. Anything over 3 seconds is slow.
  - No accounts and no customer data are used. The address is fixed to the platform domain (settings reject other hosts). Results are kept for 30 days.
- **Incidents:**
  - Opened automatically from failed pages, protected pages without login, platform-health failures, errors repeated 20+ times a day, providers with a 20%+ error rate, and stale backups.
  - Updated while the problem lasts and closed automatically when it is gone.
  - Each reopening increments `occurrence`. Recurring failures are those that happen 3+ times in 30 days.
  - The owner can record a root cause.
- **Development backlog:** every item has the ten required fields: service, problem, evidence, severity, improvement, priority (0–50 from the Phase 1 formula), estimated cost (S/M/L), benefit, test method, and whether it needs approval.
  - Items are generated from recurring incidents, recurring support topics (Phase 6) and critical findings of the Tourfecto site audit (Phase 5), or added by the owner. A key prevents duplicates.
  - Statuses: open → approved → in progress → done / rejected.
  - The metric before is recorded at creation. When an item is marked done, the metric after is recorded (automatically for incident items).
- **GitHub issues** are created only if the owner turns it on **and** `GITHUB_ISSUES_TOKEN` and `GITHUB_ISSUES_REPO` exist in `.env`. Otherwise the issue text is shown for copying.
- **Providers:** calls, errors, error rate and average time per provider over 24 hours, with a suggested alternative for each (e.g. route Gemini tasks to OpenAI/Claude in AI service routing).
- **Abuse:** customers above max(200, 10× median) AI actions in 24 hours, and IPs with 20+ failed logins in an hour. These are shown for review only; nothing is blocked automatically.
- **Backups:** the age of the newest `*.tfb` in the folder the owner sets. Over 26 hours is a warning, over 48 hours a failure. Restore tests are documented: only on a separate server or database, never production.
- **Snapshot section `ops` and decisions:**
  - `ops_pages_down`
  - `ops_incidents_open`
  - `ops_provider_failing`
  - `ops_backups`
  - `ops_abuse_review` (medium, needs approval)
  - `ops_backlog_review`
- **API:**
  - `GET /api/admin/ai-ceo/ops`
  - `POST /api/admin/ai-ceo/ops/run` (any admin, 6 per hour)
  - `POST /api/admin/ai-ceo/ops/{backlog-add|backlog-status|issue|root-cause|settings}` (owner only, except root cause)
  - Everything is audited.
- **Mission Control:** a new **Platform & Dev Advisor** tab.
- **Tests:**
  - `tests/Unit/OpsAdvisorTest.php`
  - `tests/Integration/OpsAdvisorIntegrationTest.php`: a page goes down and comes back three times (open, no duplicate, auto-resolve, occurrence count, recurring); the backlog is generated once per key; done records the after metric; validation; GitHub is off by default; settings reject other hosts and relative paths.

**Not built in this phase (needs owner decisions or access)**
- Logged-in end-to-end tests of sign-up, subscription, connections, SEO, social, WhatsApp, studio, CRM and bookings. They need dedicated test accounts on a staging copy, not on the shared production database. The Playwright checks used during development can be turned into a CI job once a staging database exists.
- Server CPU, memory and database size beyond what `PlatformHealthService` already reads. aaPanel monitoring covers this today.
- Watching Google/Meta/WhatsApp platform changes. There is no reliable free feed; this is manual.
- Restoring backups automatically. Restores must be done by hand on an isolated server.
- Preparing fixes and pull requests automatically. This stays with a developer (or Claude Code) after the owner approves a backlog item.

## Phase 8 — Financial & Strategic Intelligence

**Reused:**
- `SaasMetricsService`: MRR series, paying customers, churn.
- `wallet_transactions`: subscription charges, deposits, refunds.
- `api_usage_logs`.
- `dataforseo_calls` and `SeoDataCostReport`.
- `AiCostIntelligence` (Phase 2): routing review, spikes, budget guard.
- The Phase 1 priority formula, and support settings from Phase 6.

**New:** `app/Services/AiCeo/FinanceStrategy.php`, plus migration `2026_10_29_000011_ai_ceo_finance.sql` (`aiceo_cost_items`, `aiceo_goals`, `aiceo_goal_tasks`).

- **Profit and loss for 6 months:**
  - **Revenue:** completed subscription charges from wallets, minus refunds. Cash in and MRR are shown next to it.
  - **Costs:** AI, DataForSEO, and the fixed monthly costs the owner enters (infrastructure, tools, people, marketing, other). The page states that profit is overstated until fixed costs are entered.
- **Markets:** for each country, paying customers, MRR, ARPU, sign-ups over 90 days, conversion, AI cost over 30 days, and MRR minus AI cost.
- **Cost-saving opportunities:** from the AI routing review, AI cost spikes, the top AI costs, the DataForSEO projection, and expensive tool subscriptions.
- **Scenarios for any decision:**
  - The owner enters one-time cost, monthly cost, new customers per month, ARPU, churn and months.
  - Three scenarios are compared:
    - conservative: adoption ×0.5, cost ×1.2, churn ×1.5
    - balanced
    - aggressive: adoption ×2, cost ×1.6, churn ×1.2
  - Each shows customers, MRR, revenue, cost, net, ROI, payback month, assumptions, risks and resources.
- **Digital Twin:** an estimate model built from the last 30 days (active and paying customers, ARPU, AI and data cost per customer, fixed costs, sign-ups, conversion, support time, and the server capacity the owner enters).
  - **Levers:** customers ×, API cost change, lost customers %, sign-ups ×, and a new country's cost and customers.
  - **The directive's five questions** are ready-made: customers double, API cost +50%, lose 10%, new country, sign-ups ×5.
  - **Output:** MRR, costs, profit and the change from today, capacity used, and resources needed.
  - Every result is labelled an estimate and lists its assumptions.
- **Quarterly goals:**
  - Each goal is tied to a real metric: MRR, paying customers, sign-ups, churn, trial conversion, the Tourfecto SEO score, open incidents, or unanswered support.
  - The baseline is captured when the goal is created, and progress is measured automatically. For churn, incidents and support, lower is better.
  - Weekly tasks per goal, with this week's done count.
- **AI Boardroom (owner only, AI CEO budget, task `ai_ceo_boardroom`, 10 per hour):**
  - The owner asks a strategic question in Arabic or English.
  - The answer includes a summary, the CFO/CMO/CTO/COO perspectives, three scenarios (cost, expected return, risks, assumptions, resources), a recommendation, next steps, and data gaps instead of invented numbers.
  - Answers are saved in memory.
- **Snapshot section `finance` and decisions:**
  - `fin_fixed_costs`
  - `fin_losing_money`
  - `fin_quarter_goals`
- **API:**
  - `GET /api/admin/ai-ceo/finance`
  - `POST /api/admin/ai-ceo/finance/{scenario|simulate}`: any admin, calculations only.
  - `POST /api/admin/ai-ceo/finance/{cost|cost-delete|goal|goal-status|task|task-status|capacity|boardroom}`: owner only, audited.
- **Mission Control:** a new **Finance & Strategy** tab.
- **Tests:**
  - `tests/Unit/FinanceStrategyTest.php`
  - `tests/Integration/FinanceStrategyIntegrationTest.php`: P&L counts only completed charges and active fixed costs; markets; baseline and capacity; goals with tasks and progress; the boardroom with a fake AI and rejection of non-JSON answers.

**Not built in this phase**
- Partnership discovery as its own feature. Ask the Boardroom for now; real discovery needs a data source.
- Competitor pricing analysis. That is Phase 9.
- An AI-written quarterly plan. Use the Boardroom ("draft next quarter's plan") and turn its steps into goals and tasks.

## Phase 9 — Executive Intelligence & Continuous Learning

**Reused:**
- the snapshot sections from Phases 1–8;
- `aiceo_activity` (decision history) and `aiceo_memory` (reports, lessons, knowledge);
- `market_signals` (Strategic Brain, free sources);
- `contact_submissions`, `reviews`, `api_usage_logs`;
- `SafeHttp` for competitor pages.

**New:** `app/Services/AiCeo/ExecutiveIntel.php`, plus migration `2026_10_29_000012_ai_ceo_executive.sql` (`aiceo_health_scores`, `aiceo_competitors`).

**TOURFECTO BUSINESS HEALTH SCORE (0–100): method and weights**

| Component | Weight | Calculation (real data only) |
|---|---|---|
| Growth | 15 | MRR change vs 30 days ago (60 at 0%, +2 per 1%), averaged with sign-ups this week vs last week (50 when equal) |
| Revenue & profitability | 15 | 50 + last full month margin % after AI, SEO data and fixed costs. **Needs fixed costs entered** |
| Customer satisfaction | 10 | Average Google rating of the Tourfecto profile: 1★ = 0, 5★ = 100. **Needs reviews** |
| Retention | 15 | 100 − 5 × monthly customer churn %. **Needs paying customers 30 days ago** |
| Service quality | 10 | 100 − 20 per page down − 5 per slow page − 2 per unanswered support message (max 10). **Needs a page check** |
| Platform stability | 15 | 100 − 15 per failing health check − 3 per warning − 10 per open high/critical incident |
| Security | 10 | 100 − 30 per protected page open without login − 10 per IP attacking logins − 5 per customer with abnormal usage |
| Operating efficiency | 10 | AI cost ÷ revenue over 30 days: ≤10% = 100, ≥100% = 0 (no revenue but AI cost = 0; neither = no data) |

- If any component has no data, **the score is shown as incomplete**. The page then shows a partial score over the components that have data, the coverage %, and the list of missing components. No score is invented.
- One row is kept per day, so the page can show a 30-day trend and what improved or declined against the previous day.

**Also new**
- **Daily and weekly executive report.** It covers what happened, what improved, what declined, the main problem, the priorities, what needs approval, the real results (resolved decisions, finished backlog items with before/after) and the next step. The hourly cron saves it in memory once a day, and a weekly report on Mondays. Today's report is always visible on the tab.
- **AI CEO self-review (30 days).** It reports decisions proposed, approved, auto-approved, resolved, reopened and dismissed, and the resolution rate. It lists decisions that worked, those that keep coming back (reopened 2+ times) and those often dismissed. These become **lessons** saved once in memory. The owner can add lessons too.
- **Data quality before decisions.** It checks snapshot age, sections that failed to load, and how fresh the visitor log, AI log, page checks, market sources and the companies import are. It gives a score, and a decision when it is below 70%.
- **AI output quality.** It shows AI call errors and latency per feature over 7 days, review-reply statuses over 30 days, and promises removed from brand plans.
- **Competitors.** The owner adds name, website, starting price, features, strengths and weaknesses.
  - Their public page is checked once a day (cron at 06 UTC) or on demand with `SafeHttp` (SSRF-safe). A text fingerprint that ignores scripts and dates flags real changes.
  - Price comparison against Tourfecto's plans (median, below/around/above market). One click sets the median as the market price used in the Phase 6 pricing suggestions.
- **Customer requests and suggestions** found in contact messages (English and Arabic phrases), the latest market signals, and a tourism **knowledge base** (owner notes in memory).
- **Snapshot section `executive` and decisions:**
  - `exec_health_incomplete`
  - `exec_health_low`
  - `exec_data_quality`
- **API:**
  - `GET /api/admin/ai-ceo/executive`
  - `POST /api/admin/ai-ceo/executive/{competitor|competitor-delete|competitors-check|market-median|lesson|knowledge}` (owner only, audited)
- **Mission Control:** a new **Business Health & Reports** tab, placed after Approvals.
- **Tests:**
  - `tests/Unit/ExecutiveIntelTest.php`: weights, every component, incomplete instead of a score, changes, fingerprint, price comparison, report text.
  - `tests/Integration/ExecutiveIntelIntegrationTest.php`: one score row per day; reports written once; Monday weekly; review and lessons once; data quality; competitors and page-change detection.

**Not built in this phase**
- Automatic monitoring of global competitors and AI news. There is no reliable free feed, and scraping third-party sites beyond their public page is avoided. The owner adds competitors, and their pages are checked.
- Why customers prefer competitors. This needs exit surveys or interviews; today only cancellation reasons exist (Phase 6).
- Social media and web mentions of Tourfecto (reputation beyond Google reviews). This needs a listening tool or API.

## Phase 10 — Final Integration & Controlled Activation

**New**
- `app/Services/AiCeo/AiCeoActivation.php` provides three things:
  - a 12-item readiness checklist in stages: cron, emergency stop, page checks, fixed costs, platform AI budget, backups, quarter goals, Tourfecto site in the platform, site audit, competitors, postal address, lawful basis;
  - the current **activation stage**, computed (0 observe → 1 data complete → 2 limited auto → 3 growth), with advice and the next missing items;
  - integration status from the existing integrations registry, plus what the AI CEO needs (GitHub issues token, backup key, DataForSEO, Apify) and the pending Meta/Google reviews. It shows only whether each one is configured; no secret is displayed.
- `GET /api/admin/ai-ceo/activation` and a new **Setup & Integrations** tab. It also states exactly what runs automatically and what never does, and shows AI CEO spend against its limits.
- Mission Control tabs reordered to follow the directive's dashboard order.
- `docs/ai-ceo/OWNER_GUIDE_AR.md`: a simple owner guide in Egyptian Arabic.
- `docs/ai-ceo/ACTIVATION_PLAN_AR.md`: the staged activation plan, the keys and approvals needed, and the shared-database warning.

**Acceptance tests**
- `tests/Unit/AiCeoAcceptanceTest.php` (static checks):
  - every AI CEO route (30) has Auth and Admin middleware, and its handler exists;
  - every GET checks permission, and every POST goes through `guard` (CSRF + rate limit);
  - owner-only actions use `guard(true)`, and AI actions pass the AI CEO budget guard;
  - AI CEO services never send email or WhatsApp. Network calls go only to the platform domain, GitHub, or `SafeHttp`;
  - activation stages.
- `tests/Integration/AiCeoEndToEndIntegrationTest.php` runs the full cycle on the real schema:
  - a failing provider makes only its section null while the rest loads, and data quality flags it;
  - in approval mode nothing executes; the emergency stop blocks auto mode; auto mode approves only low-risk decisions;
  - a page going down opens an incident and the `ops_pages_down` decision;
  - daily and weekly reports are written and the health score is saved;
  - the readiness checklist and stage work;
  - every step is audited;
  - the snapshot stays under 20 seconds.
- **Existing phase tests cover:** approval/auto/stop (`AiCeoStoreIntegrationTest`), budgets (`AiCostIntelligence*`), the companies database, consent, erasure and merge (`TourismBusinessDb*`), the outreach gate (no unauthorised sending, `SalesEngine*`, `SalesComplianceTest`), brand/content (`BrandGrowth*`), customer success, ops, finance, the digital twin, the health score and executive reports.

**Directive dashboard → Mission Control tabs**

| Directive section | Where |
|---|---|
| Executive Overview, AI Mission Control, Automation Settings | Overview (+ mode / emergency stop on every tab), Setup & Integrations |
| AI CEO Chat | AI CEO Chat |
| Strategic Radar, Approvals | Approvals & Tasks |
| Business Health Score, Reports & Insights, Competitive Intelligence | Business Health & Reports |
| Tourism Business Database | Tourism Companies |
| Leads & Sales, Email & WhatsApp | Leads & Sales (gate, approvals, sequences; sending stays in the existing outreach service) |
| Marketing Campaigns, Content & Social Media, SEO / AEO / GEO | Brand Growth |
| Customer Success, Subscriptions & Revenue | Customer Success |
| Financial Intelligence, AI Boardroom, Digital Twin | Finance & Strategy |
| Development Advisor, Platform Health | Platform & Dev Advisor |
| AI Cost Optimization | AI Costs |
| Integrations | Setup & Integrations |
| Audit Logs | Audit Log |

**Conflicts with the other Claude sessions:** none. They work on the website builder, site-admin and Creative Studio. The shared files (`app/routes/api.php`, `app/Config/project_classmap.php`) received additive lines only, and every rebase was clean. `app/Jobs/GenerateVideoJob.php` was not touched; its lint warning still fails the CI lint job.

**Operating cost**
- The hourly cron uses no AI.
- AI is used only on the owner's buttons (chat outside the known intents, monthly content plan, boardroom), capped by the AI CEO budget (default $1/day, $20/month).
- The page checks make about 25 HTTP requests per hour to the platform itself.
- The site audit makes up to about 45 requests a week.
- The competitor checks make one request per competitor per day.
- Actual spend appears in AI Costs and Setup & Integrations.

**Final status**
All 10 phases are implemented, tested in CI and merged. **The system is not "fully complete"** until the owner finishes these items:
1. Verify the new tabs on the admin account (the customer account was verified to receive 403).
2. Enter the owner inputs in the activation checklist.
3. Decide the lawful basis per region with a lawyer before any outreach.
4. Add the GitHub/backup keys if those features are wanted.
5. Raise the DataForSEO cap.
6. Separate the dev database from production.
7. Have the other session fix the `GenerateVideoJob.php` lint warning.

Logged-in end-to-end tests of customer flows need a staging database and test accounts. Paid ads, automatic sending and automatic publishing remain off by design.
