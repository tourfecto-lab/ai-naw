# Tourfecto — Competitor Intelligence Module

> Version 1.6.0 — a self-contained module for discovering, tracking, and
> benchmarking competitors: monitoring, change detection, alerts, AI
> insights, reports, and settings. Arabic-first codebase (comments, UI
> strings, Lang files). v1.5.1 added the competitive-differentiator pass:
> structured price tracking, careers/hiring signals, and CSV export.
> **v1.6.0** is a UI-only professionalization pass: the embedded panel was
> rebuilt on the unified "Compass" design system (`panel.css`), all emoji
> were replaced by a single SVG icon sprite shared by PHP and JS, stat
> tiles + skeleton/empty states + a custom confirm/prompt modal were added,
> full ARIA/focus/keyboard support (`tab`/`dialog` roles, Escape close,
> focus restore, `prefers-reduced-motion`), and 33 `ci.*` i18n keys were
> added to `en.php`/`ar.php`. No API/route/migration changed.

## Quick navigation

- [Architecture](#architecture)
- [Directory layout](#directory-layout)
- [API endpoints](#api-endpoints)
- [Security model](#security-model)
- [Rate limiting](#rate-limiting)
- [Database](#database)
- [Tests](#tests)
- [Development rules honored](#development-rules-honored)

---

## Architecture

One unified module behind one page (`/competitor-intelligence`) and ~37
JSON endpoints under `/api/competitor-intelligence/*`. The flow:

```
user adds/imports a competitor
        -> competitors row + ci_watchlist entry (user's default prefs)
        -> cron/monitor_competitors.php schedules MonitoringEngine
        -> MonitoringEngine fetches 7 page types + sitemap.xml
        -> WebsiteSnapshotFetcher (SSRF-protected) stores ci_snapshots
        -> ChangeDetectionService diffs last two snapshots -> ci_changes
           (pricing/offer/product changes also get structured
            price_before/price_after/currency via PriceExtractor)
        -> AlertService (respects watchlist min severity / keyword rules
           / pause state) -> ci_alerts -> email / webhook / slack
        -> ThreatOpportunityService -> ci_insights (evidence-linked)
        -> BenchmarkingService -> ci_scorecards
        -> ReportService -> ci_reports (view / export PDF & CSV)
```

Everything is **tenant-isolated**: every query is scoped by `user_id`, and
ownership is asserted in the controller (`assertCompetitorOwnership`,
`assertDiscoveryOwnership`) before any read or write.

## Directory layout

| Path | Responsibility |
|---|---|
| `app/Controllers/CompetitorIntelligenceController.php` | Page shell + all API endpoints + embedded JS panel UI (tabs, Chart.js) |
| `app/Services/CompetitorIntelligence/` | Business logic (see table below) |
| `app/Jobs/` | Queue jobs: `MonitorCompetitorJob`, `SendCompetitorAlertEmailJob`, `SendCompetitorAlertWebhookJob` |
| `app/Models/Competitor.php` + `Ci*` models | Thin models over `competitors` + `ci_*` tables |
| `cron/monitor_competitors.php` | Due-competitor scheduler (max 200/run) + periodic scorecards (max 100/run) |
| `cron/ci_weekly_digest.php` | Monday-only weekly digest email (never sends an empty digest) |
| `database/migrations/` | Additive SQL migrations, run in filename order |
| `app/Lang/ar.php` / `en.php` | All UI strings under `ci.*` / `ci.js.*` keys |
| `tests/Unit/CompetitorIntelligence/` | Offline, dependency-free unit tests (see [Tests](#tests)) |

### Services

| Service | Role |
|---|---|
| `CompetitorDomain` | **Single normalization point** for user-entered domains/URLs: trim, auto `https://`, lowercase host extraction, SSRF safety. Use this everywhere you touch a competitor domain. |
| `SsrfGuard` | SSRF gate. Resolves **all** A + AAAA records and rejects the domain if any single record is private/reserved/loopback — including IPv4-mapped IPv6 (`::ffff:127.0.0.1`) and IPv6-only private ranges. Blocks non-http(s) schemes, non-standard ports, known metadata hostnames. Re-validates every redirect hop. |
| `WebsiteSnapshotFetcher` | Safe GET of one public page (1.5MB cap, 12s timeout, manual redirect handling, pins IPv4 so the connection matches the validated addresses). Extracts title/meta/normalized text/hash + technology signals. |
| `SitemapMonitor` | New/Removed Pages detection via `sitemap.xml` diffing (index recursion capped at 3 sub-sitemaps). No arbitrary crawling. **v1.5.1:** detects careers/jobs/join/hiring/vacancies URLs and flags them as a strategic hiring signal (page_type `careers`, severity `high`). |
| `ChangeDetectionService` | Hash diff between last two snapshots of a page -> rule-based change_type/severity/confidence. Fetch failure is "monitoring failed", never "nothing changed". |
| `MonitoringEngine` | One full cycle for one competitor: fetch -> snapshot -> detect -> alert, rate-limited between requests. |
| `CompetitorDiscoveryService` + sources | Pluggable discovery (`CompetitorDiscoverySourceInterface`): `WebsiteOnboardingDiscoverySource` (user's own onboarding URLs, free), `GooglePlacesDiscoverySource`, `NullDiscoverySource` (honest "not configured"). |
| `AlertService` | Change -> `ci_alerts`, respecting watchlist min severity, keyword filters (bypass severity), pause state, channels. Every alert has a real `change_id`. |
| `ThreatOpportunityService` | Rules-engine producing evidence-linked `ci_insights` (threat/opportunity/insight/recommendation). |
| `AICompetitiveAnalyst` | Gemini Q&A, weekly summary, profile positioning — grounded strictly in captured page content, never general knowledge. |
| `BenchmarkingService` | Comparison + periodic scorecards labeled `data_backed` / `estimated`. |
| `ReportService` | Weekly/Monthly/Profile/Threat/Opportunity/Change reports stored as JSON, viewable + exportable. |
| `CiRateLimiter` | Per-user fixed-window rate limiter on expensive endpoints (DB-backed via `ci_rate_limits`). |
| `PriceExtractor` | **v1.5.1.** Pure rule-based extraction of structured price + currency from changed page text (symbols, ISO codes, Arabic words, Arabic-Indic digits). Drives the price-history feature. |
| `CiConstants` | Centralized allowed-value lists (categories, frequencies, severities, channels, insight statuses, page types) + severity rank. |
| `CiPermissions` | Maps `users.role` onto Admin/Manager/Analyst/Viewer scoped to this module. Fails closed: missing/unknown role -> viewer. |

## API endpoints

All under `/api/competitor-intelligence/`. List endpoints paginate
(`page`, `per_page`, max 100) and sort via a whitelisted
`sortClause()` — no dynamic SQL.

| Method & path | Notes |
|---|---|
| `GET /dashboard` | Cached per-user (`ci_dashboard:{userId}`, 60s) |
| `GET /competitors` · `POST /competitors` · `PUT /competitors/{id}` · `DELETE /competitors/{id}` | Full CRUD, ownership-asserted |
| `POST /competitors/bulk-import` | Max 200 rows/request, per-row results |
| `POST /competitors/{id}/check-now` | Manual cycle, rate-limited to 1/5min per competitor |
| `GET /competitors/{id}` | Profile + insights |
| `GET /competitors/{id}/timeline` | Month-grouped history |
| `GET /competitors/{id}/price-history` | **v1.5.1.** Structured price-change log (before/after/currency) for pricing, offers & product changes — rendered as a history card in the profile timeline |
| `POST /competitors/{id}/scan-insights` | Run rules engine (rate-limited) |
| `POST /competitors/{id}/analyze-profile` | AI positioning (rate-limited) |
| `POST /competitors/{id}/compute-scorecard` · `GET /competitors/{id}/scorecard-trend` | Benchmarking |
| `POST /discovery/suggest` · `POST /discovery/run` (rate-limited) · `GET /discovery` · `POST /discovery/{id}/approve|dismiss` | Discovery |
| `GET /watchlist` · `POST /watchlist` · `DELETE /watchlist` | Watchlist upsert/remove |
| `GET /activity` · `POST /comparison` · `POST /comparison/export` | Feed + compare + **v1.5.1** CSV export (same data, downloadable) |
| `GET /alerts` · `POST /alerts/{id}/read` · `POST /alerts/read-all` · `GET /alerts/unread-count` | Alerts incl. v1.5.0 bulk-read + badge count |
| `GET /insights` · `POST /insights/{id}/status` | Insights incl. v1.5.0 review/dismiss |
| `POST /ai/ask` (rate-limited) · `GET /ai/weekly-summary` (rate-limited) | AI |
| `GET /reports` · `POST /reports` (rate-limited) · `GET /reports/{id}` · `GET /reports/{id}/export` | Reports + PDF/CSV export |
| `GET /settings` · `PUT /settings` · `POST /settings/pause-all` | Preferences + bulk pause/resume |

## Security model

1. **Tenant isolation (primary).** Every query filters by `user_id`; ownership
   is asserted in the controller before any action. Independent of role.
2. **SSRF.** All outbound URLs (competitor domains, sub-pages, webhooks,
   discovery candidates) pass `SsrfGuard`. All A + AAAA records must be
   public; IPv4 is pinned at the curl layer; redirects re-validated per hop.
3. **Rate limiting.** Expensive endpoints (`ai_ask`, `ai_profile`,
   `ai_insights`, `ai_weekly_summary`, `discovery_run`, `report_generate`)
   are limited per user via `CiRateLimiter`. Adjust limits in one place:
   `CiRateLimiter::LIMITS`.
4. **Input validation.** Whitelisted enums via `CiConstants::within()`,
   length caps on AI question (2000) and competitor name (255),
   pagination capped at 100.
5. **XSS.** All dynamic strings rendered in the JS panel pass through
   `esc()` (HTML-escape); CSV export uses `fputcsv`; JSON report view is
   escaped before display.
6. **Least privilege.** `CiPermissions` fails closed — missing/unknown
   `users.role` maps to `viewer`, not admin.
7. **No fake data.** Nothing is invented — no discovery provider without a
   real key, no speculative alerts, no AI claims beyond captured content.

## Rate limiting

Per-user fixed-window counters in `ci_rate_limits` (`scope_key` +
`window_start`, `INSERT ... ON DUPLICATE KEY UPDATE`). Works across
multiple app processes (not in-memory). `CiRateLimiter::windowStart()` is
pure and unit-tested. The DB is never blocked on — if the table is missing,
the limiter fails open and logs (the migration is additive).

## Database

All tables are additive `ci_*` tables plus **optional** new columns on
`competitors`. Migrations are plain SQL, run in filename order:
`2026_08_08_000042` … `2026_08_15_000049` (v1.5.1 adds `price_before` /
`price_after` / `currency` on `ci_changes` and a `careers` `page_type`
value to the `ci_snapshots` / `ci_changes` enums). No existing
production tables are altered in a breaking way.

## Tests

Offline, zero-dependency unit tests (no database, no framework) — run from
the repo root:

```bash
php tests/Unit/CompetitorIntelligence/SsrfGuardTest.php
php tests/Unit/CompetitorIntelligence/CompetitorDomainTest.php
php tests/Unit/CompetitorIntelligence/CiRateLimiterTest.php
php tests/Unit/CompetitorIntelligence/CiConstantsTest.php
php tests/Unit/CompetitorIntelligence/CiPermissionsTest.php
php tests/Unit/CompetitorIntelligence/PriceExtractorTest.php
php tests/Unit/CompetitorIntelligence/SitemapMonitorTest.php
```

Current suite: **126 assertions, 0 failures.** The integration test
(`tests/Integration/CompetitorIntelligenceTest.php`) requires the full
production app (real database + migrations) and cannot run offline — run it
in the production environment after applying the migrations.

Sanity check before shipping:

```bash
find app cron tests -name "*.php" -print0 | xargs -0 -I{} php -l {}
```

## Competitive analysis (v1.5.1)

Benchmark against the global leaders offering the same service class
(research 2026-08-15):

| Platform | Focus | Flagship capabilities |
|---|---|---|
| **Klue** | Sales-enablement CI | Battlecards, "Ask Klue" (Slack/Salesforce answers), deal tips, email digests, win/loss suite, G2-review insights |
| **Crayon** | Market intel | Aggregates websites + news + social + ads + jobs + reviews; Crayon AI; battlecards; win/loss |
| **Kompyte** (Semrush) | Automation CI | Tracks websites, reviews, content, social, ads, **job postings**; AI daily summaries; battlecards; Slack/Teams/CRM; win/loss |
| **Prisync** | E-commerce pricing | **Structured price tracking**, price history, price-position comparison, stock availability, Excel reports, daily/instant email alerts |
| **SEMrush / Similarweb** | Digital marketing | Traffic / SEO visibility / market-share benchmarking |

### Our position and what closes the gaps

We already cover the website-tracking core (snapshots, sitemap change
detection, keyword watchlists, severity thresholds, dashboard/email/in_app/
webhook/slack alerts, AI insights + weekly digest, reports, roles,
rate limiting, SSRF hardening). v1.5.1 closes three concrete gaps that the
research shows matter most for the SMB segment:

1. **Structured pricing intelligence** (Prisync differentiator) —
   `PriceExtractor` turns raw text diffs into `price_before` / `price_after`
   / `currency`, surfaced through `GET /competitors/{id}/price-history` and
   a price-history card in the profile timeline.
2. **Careers / hiring signal** (Crayon & Kompyte both track job postings) —
   `SitemapMonitor::isCareerUrl()` flags new/removed careers pages as
   `page_type=careers`, severity `high`.
3. **CSV export** (Prisync Excel reports) — `POST /comparison/export`
   returns the same comparison matrix as a downloadable CSV (client-side
   Blob download, nothing stored on the server).

### Deliberately deferred (roadmap — need external data / paid APIs / CRM)

- Traffic/SEO visibility data (SEMrush/Similarweb APIs), social & review
  monitoring (G2/Trustpilot APIs), win/loss analysis (needs CRM + sales
  pipeline), and screenshot/visual diff (needs a headless browser). These
  are noted here so future work targets the right platform; the module
  already has the data model and endpoints to bolt each one on additively.

## Development rules honored

- **Rule #41** — no refactor of the existing project: changes are additive;
  shared framework classes (`Model`, `Database`, `Cache`, `GeminiClient`,
  `QueueManager`, `Validator`) live in the production app, not here.
- **Rule #30** — tenant isolation (`user_id`) on every query.
- **NO FAKE DATA** — all data derives from real user input, real fetched
  content, or real observed signals.
- **Arabic-first** — comments, commit messages, and UI strings are in
  Arabic; every new UI string has a matching `ci.*` key in `ar.php` and
  `en.php`.
