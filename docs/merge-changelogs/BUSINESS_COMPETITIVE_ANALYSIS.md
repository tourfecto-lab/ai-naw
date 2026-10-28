# Tourfecto — Business Control Center — Competitive Analysis & Positioning

**Date:** 2026-08-15
**Scope:** Benchmarks the Business Control Center module (Phases 1-7, merged as of 2026-08-14)
against the market leaders that define how a "single source of truth" for business data must
behave in the age of AI Search (ChatGPT, Perplexity, Google AI Overviews, Meta AI).

**Why this matters:** The module was built to be the **single source of truth** for any AI
feature (content generation, review replies, GBP posts, SEO recommendations). That is exactly
the positioning battle the four platforms below are fighting over — so the module must be
measured against them, not against generic CRUD examples.

---

## 1. The four competitive reference points

| Platform | Core thesis | What it is best known for |
|---|---|---|
| **Semrush** | Data-scale SEO/AEO: keyword & competitor intelligence feeding AI-optimized content | Keyword/competitor/market analysis, AI Brand Sentiment, Local SEO, rank tracking, content optimization |
| **Yext** | The **Knowledge Graph** — "one trusted set of verified facts for every location", the AI-ready source of truth | 200+ direct publisher distribution, real-time listing sync, reviews across 80+ platforms, AI search agent answers |
| **Birdeye** | Reputation-as-OS for multi-location brands, now wrapped in **BirdAI agents** | Reviews AI (200+ platforms), Listings AI, Social AI, Search AI, Insights AI; 3000+ integrations; industry/brand-tuned AI models |
| **SOCi** | "The CoPilot for Marketing" — **agentic** local marketing | Genius agents (Local Search, Reputation, Social, Reviews), Local Visibility Index (branded readiness score), brand/trained-on-your-workflows models, benchmark vs competitors |

## 2. What each does well (the bar to clear)

### Semrush
- **Competitive breadth at scale:** keyword volumes, competitor domains, market shares, share of
  voice — across web + local + ads + social. Its moat is *data volume*.
- **AI Brand Sentiment / AI visibility:** measures how brands appear across AI chat answers
  (ChatGPT/Perplexity) — the "AI-overview share" concept.
- **Content optimization score:** a quantified 0-100 score telling you how ready a piece of
  content is to rank — a *scoring feedback loop*, not just raw output.

### Yext
- **Knowledge Graph = the single source of truth.** Every business fact (name, hours, services,
  photos) lives once, is verified once, and is syndicated everywhere. This is *precisely* the
  role `BusinessContextService::getContext()` plays inside Tourfecto.
- **Real-time, transactional consistency** across 200+ publishers.
- **First-class API + developer ecosystem** — the facts are machine-readable and consumed by
  their own AI Search agents, not buried in PDF reports.

### Birdeye
- **AI agents per domain** (Reviews AI, Listings AI, Social AI, Search AI) all trained on the
  *same business brand data* — one business context, many agents.
- **Brand grader / "Scan your brand"** — an instant audit that scores your online presence and
  tells you exactly what to fix next (onboarding hook).
- **Broad integration surface** (3000+) — data lands wherever the customer already works.

### SOCi
- **Local Visibility Index (LVI):** a branded, weighted 0-100 readiness score across listing
  completeness, review volume/velocity, social engagement, and website signals — the closest
  analog to an "AI Audit score."
- **Agents educated on your brand, trained on your workflows** — governance + brand-voice
  enforcement inside every generation.
- **Execution-to-visibility measurement:** proves the link between "posted content" and
  "improved visibility," with industry benchmarks.

## 3. Where the Tourfecto module already competes

Strengths to keep and market explicitly:

1. **Single source of truth, enforced in architecture.** `BusinessContextService::getContext()`
   is the one entry point every AI module must use, with a 1-hour cache TTL and explicit
   `invalidate()` wiring after every mutation (documented in
   `BUSINESS_CONTROL_CENTER_CHANGELOG.md`). This is the *architectural* equivalent of the Yext
   Knowledge Graph and the shared context all Birdeye/SOCi agents read from.
2. **Server-side ownership enforcement everywhere (IDOR-safe).** Every controller resolves the
   business through `loadOwnedBusiness()` and returns 404 (not 403) for non-owned IDs — a
   discipline several of the reference platforms have to bolt on later.
3. **Business **prompt-ready** context.** `toPromptContext()` turns the context into a clean,
   hallucination-guarded prompt (including "IMPORTANT - Never claim" and
   "Never use these terms" lines) — brand-voice enforcement in the same spirit as SOCi's
   "trained on your brand."
4. **Validation is real, not decorative.** Brand colors must be genuine `#RRGGBB`, business
   type / brand voice validated against allow-lists, ISO country/currency codes enforced,
   JSON fields decoded via model getters instead of ad-hoc `json_decode`.
5. **A primary-location invariant enforced in the service layer** (`BusinessLocationService`),
   transaction-safe, with automatic primary promotion on delete — the kind of rule that keeps a
   Knowledge Graph *consistent* over time.

## 4. The gaps vs these platforms (and what this delivery addresses)

### G1 — No quantified readiness score (SOCi LVI / Birdeye Brand Grader analog)
The module can tell you *whether* a field exists, but not *how complete the business profile is*
or *what to do next in priority order*. Every reference platform uses a branded score to drive
onboarding, upsells, and retention.

> **Addressed in this delivery:** `BusinessReadinessService` — a weighted 0-100 "AI Audit"
> score across 7 categories (identity, contact, locations, services, target markets, AI
> context, brand), with grade A-F, per-category breakdown, and prioritized next-step
> recommendations. Pure-logic core (`scoreFromContext()`) is unit-testable offline, mirroring
> the repo's SsrfGuard test pattern.

### G2 — No aggregated dashboard view of the business (Yext "single pane", SOCi visibility dashboard)
Consumers must call 6 endpoints and assemble the picture themselves. There is no single
`overview` payload that answers "where is my business right now?" — needed to wire the
dashboard (original spec Phase 19).

> **Addressed in this delivery:** `GET /api/business/overview` — one authenticated call that
> returns the full context, the readiness score, entity counts, and the top 5 prioritized next
> steps, cached on the same 1h TTL as the context itself.

### G3 — Non-atomic delete of a primary location
`BusinessLocationService::delete()` deleted the location and *then* promoted a replacement
primary outside any transaction — a failure between the two leaves a business with no primary
location (breaks the invariant the service is supposed to guarantee).

> **Addressed in this delivery:** delete + primary re-promotion are now one transaction, so the
> single-primary invariant can never be left in a half-applied state.

### G4 — No competitor benchmarking surface (Semrush/SOCi)
SOCi shows you where you stand vs industry; Semrush monetizes competitor data. The module
stores competitors in AI Context but exposes no quantified comparison.

> **Proposed (future phase):** compute a per-category delta between the owned business's
> readiness and the aggregated readiness of its stored competitors, exposed as a
> `benchmark` section in the overview. Requires the competitors to also have Tourfecto business
> profiles (optional + manually-filled), so it is additive and safe to defer.

### G5 — Review/reputation signals not yet part of the business context
Birdeye's whole value is reviews; the module's context currently ignores `reviews` (which the
platform already tracks for GBP/TripAdvisor).

> **Proposed (future phase):** fold average rating + review counts + sentiment for the owned
> business into `getContext()` (it already exists in the wider platform via `Review`), and
> weight them into the readiness score once the data contract is confirmed.

### G6 — No distribution/syndication story yet
Yext's moat is 200+ publisher syndication. This is out of scope for a data-layer module and
correctly belongs in the platform's existing integration layer — but the readiness score should
explicitly flag "no connected listing channels" as a recommended next step (done in this
delivery via the contact/verification category).

## 5. Positioning statement (how the module should be sold)

> **Tourfecto Business Control Center is the Yext-style single source of truth for a travel
> business, with a SOCi-style readiness score and Birdeye-style brand-voice guardrails — built
> directly into the same platform that already runs its SEO/AI, reputation, and WhatsApp
> assistant.**

It does not try to beat Semrush on data volume or Yext on publisher count. It competes on
**ownership of the authoritative business context that every Tourfecto AI feature reads from**,
plus the **feedback loop** (score + next steps) that turns that context into a growing asset.

## 6. What this delivery changes (files)

| File | Change |
|---|---|
| `app/Services/BusinessReadinessService.php` | **New** — AI Audit readiness score (pure core + DB wrapper). |
| `app/Controllers/BusinessController.php` | **New `overview()` action** — aggregated dashboard payload. |
| `app/routes/api.php` | **New route** `GET /api/business/overview`. |
| `app/Services/BusinessLocationService.php` | **Fix** — atomic delete with primary re-promotion. |
| `public_html/index.php` | **Register** `BusinessReadinessService` in the manual autoload list. |
| `tests/Unit/Business/BusinessReadinessServiceTest.php` | **New** — offline unit tests for the scoring core. |
| `docs/merge-changelogs/BUSINESS_CONTROL_CENTER_CHANGELOG.md` | Updated scope + new "Competitive phase" section. |

## 7. Honest limitations

- Scoring weights are opinionated defaults (documented in the service); a/B-testable and
  override-able per plan later.
- No live competitor benchmark yet (G4) and no review signals yet (G5) — explicitly deferred,
  not half-built.
- Tests in this delivery run offline against the pure scoring core only; DB-backed flows still
  require the server runtime, consistent with every prior phase.
