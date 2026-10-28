# Phase 3 — measured AI visibility

Branch: `seo/phase-3`. Required base: `origin/claude/stoic-heisenberg-66srnk`, including `8d46940` and `c4a791a`. `main` is an older snapshot. The previous local attempt was saved in a backup branch/stash before recreating this branch from the required base; existing Phase 1, billing, connector and planning changes were retained.

## What the customer gets

Open **الظهور في البحث → ظهورك في الذكاء الصناعي**, `/ai-visibility`. Choose a website and an existing Phase 2 market. Confirm current company facts in Business Hub. Generate or enter 10–30 traveler buying questions, edit them, and explicitly approve the list. Generation is local, costs no model tokens, and uses confirmed services plus recent (30-day) DataForSEO keywords. English, Arabic, German and French templates are supported; other languages need customer-authored questions. No fabricated keywords or volumes are used. Customer and tracked competitor names/domains are excluded from questions.

The page shows provider/model, actual completed sample counts, mention share, citation rate, competitors appearing instead, question answers and cited sources, an eight-week chart, and evidence-backed improvement tasks. Missing data is **مش مقاس (not measured)**, including unresolved citation attribution. Dates and partial coverage remain visible. Past readings are marked stale. Changed question cohorts are not controlled experiments.

These are web-grounded API samples, not measurements of consumer ChatGPT/Gemini/Perplexity app rankings. The market supplies language and traveler context; it does not guarantee a physical IP location or identical consumer personalization. Common country codes have neutral country names; other DataForSEO location codes remain explicit numeric context. Customer-editable market labels and company identity are never sent as additional provider context.

Mention position means order of first mention among the configured company and competitors, not an overall search position. Matching ignores case and Arabic/Latin diacritics, normalizes domains, supports distinctive partial company names and explicitly entered bilingual aliases, and avoids substring matches inside other words. Brand aliases shorter than three normalized characters and generic travel words are excluded to reduce false positives; ambiguous names still require sample review.

## Admin setup — exact paths and all new settings

Sign in as **Super Admin → الإعدادات → إعدادات النظام والربط (API) → قياس ظهور AI**, `/admin/settings`.

| Setting | Configure |
| --- | --- |
| `geo_enabled` | Master paid-measurement switch, `1` on / `0` off. Defaults off. |
| `geo_monthly_budget_usd` | Positive platform-wide monthly USD ceiling. Missing/zero stops calls. |
| `geo_openai_enabled` | Enable OpenAI independently. |
| `geo_openai_api_key` | OpenAI project API key with Responses/web-search access and billing. |
| `geo_gemini_enabled` | Enable Google independently. |
| `geo_gemini_api_key` | Gemini API key with Google Search grounding access. |
| `geo_perplexity_enabled` | Enable Perplexity independently. |
| `geo_perplexity_api_key` | Perplexity API key with synchronous Sonar compatibility access and billing. |

All three new API-key settings are encrypted using the existing deployment `ENCRYPTION_KEY`; admin readback never reveals them. Inputs clear after save. OpenAI/Gemini can reuse the existing shared `integration_openai_api_key` / `integration_gemini_api_key` or corresponding existing shared key/constant when their dedicated GEO key is empty. Independent GEO switches and budget still apply. Prefer dedicated project keys. No other new key or paid subscription is required by this phase. Existing DataForSEO settings from Phase 2 supply research used for drafting questions.

Wallet price: **الباقات والأسعار → تسعير "ادفع حسب الاستخدام"**, `/admin/plans`, row **قياس ظهور AI: سؤال ومزوّد**, feature `ai_visibility`. Initial price **$0.15 per question × provider × market attempt**. Existing admin prices survive migration. Wallet overage requires an active, positive USD price. The customer sees the current price before requesting measurements.

| Plan | Default `ai_visibility` allowance per month, shared across account websites |
| --- | ---: |
| Starter | 60 |
| Professional | 300 |
| Enterprise | 2,000 |

Explicit `subscription_plans.features_json.ai_visibility` overrides the default; `-1` never grants unlimited paid measurements. Allowances use the existing subscription monthly quota period, including annual subscriptions. Plan usage is consumed first, then wallet, then the request is blocked. Existing subscription prices remain controlled by the billing catalog. For GEO alone, an incremental allowance priced around $5 / $15 / $60 per month respectively is a reasonable starting allocation to evaluate with actual invoices; this does not price the platform's other paid features or guarantee margins.

## Deployment and scheduling

1. Back up hosting files and database using the normal deployment process. Upload the cumulative ZIP preserving paths from the project root. Its manifest is built from `git diff --name-only --diff-filter=AM 0127545 HEAD`, excluding `tests/`, as required by the current roadmap context. A separate Phase 3-only ZIP is also provided for a hosting installation already at `8d46940`.
2. Apply `database/migrations/2026_10_17_000002_ai_visibility.sql` outside any transaction. The additive runtime fallback checks `information_schema`, uses a schema lock, and refuses DDL in a transaction. Existing Phase 2 tables, Business Hub, billing, notifications and the `jobs` queue are prerequisites. The six GEO tables store versioned questions, confirmed aliases/profiles, durable call claims/costs, measured answers, evidence tasks and budget alerts.
3. PHP needs the application's existing extensions plus **DOM/XML** for public-page evidence. No Composer package is added.
4. Configure the eight settings above. Start conservatively: a $30 monthly platform budget covers the $0.10/check reservation envelope for one customer doing 20 questions × 3 providers weekly in an average month. Increase the platform budget deliberately for more customers/markets. This is a reservation envelope, not a forecast invoice.
5. Keep the existing central `cron/scheduler.php` running each minute. New schedule `seo_ai_visibility` runs **Monday 06:00 in `CRON_TIMEZONE` (default Africa/Cairo)** and enqueues approved markets. Existing `queue` runs each minute and executes `AiVisibilityCheckJob`. Each job attempts one check, then schedules continuation. `cron/seo_ai_visibility.php` can be run manually from CLI for diagnosis; it requires the standard cron bootstrap.

Claims are unique by website, market, provider, normalized question and UTC Monday week. Requests already attempted that week are not retried, including uncertain timeouts: this avoids duplicate paid calls. New/edited approved questions can create new weekly checks; unchanged questions are deduplicated. A changed confirmed business profile requires a fresh list approval. Changing aliases/profile URLs revokes all list approvals. Jobs from an earlier week or old list version exit.

The global budget lock serializes reservations. Each outgoing request reserves $0.10 before consuming quota/wallet and committing its durable claim. Known cost replaces that reservation; missing usage/cost retains it. Budget-blocked calls consume nothing. Attempted provider failures consume a check because the remote billing outcome may be uncertain; no automatic refund or retry is inferred. Admins receive an in-app budget notification once per month when another reservation cannot fit. The cap is a control on recorded costs/reservations; provider pricing changes or unexpected invoices are reconciled against vendor billing.

## Evidence, site changes and llms.txt

Competitor citations are safely fetched with TLS, per-hop SSRF/DNS-pinning protection, byte/time/count bounds and no provider credentials. The service compares the cited page with a relevant linked customer trip page where it can find one, otherwise the homepage sample. Tasks show source URL/date, excerpts, published price/FAQ/schema/review markers, pickup/duration/inclusions/cancellation observations and confirmed facts not observed as exact text. These are review leads, not proof that content is absent from an entire site or that competitors' claims are true. Inaccessible pages produce no invented comparison.

Third-party citations alongside competitor mentions create review tasks for TripAdvisor/GetYourGuide/Viator/Google presence; they do not assert that a customer profile is missing. Entity comparison uses only profile URLs explicitly entered and confirmed by the customer. NAP comparisons are bounded public-text checks; login walls, dynamic content and alternate formatting can require manual review.

Organization `sameAs` uses only those confirmed customer-entered URLs. GEO task approval records review and does not modify a customer site. `llms.txt` drafts use fetched published trip links, summaries, literal price text, public contact links and published language declarations. No AI description fallback is used. Existing AutoFix approval → EdgeRules connector → public `PageContext::verifyValue` → rollback remains the publishing path. The existing WordPress/PHP `/llms.txt` behavior remains in place. Optional `/llms-full.txt` was not added. No ranking or citation benefit is claimed.

Scheduled measurements are passed, with dated source evidence/tasks, into SEO Expert metrics and its plan prompt. Existing manual SeoImpact AI imports stay separate and are not mixed into these denominators.

## Cost per customer — 20 questions × 3 providers weekly

Prices checked against public official documentation on **17 October 2026**:

- [OpenAI GPT-4.1 mini](https://developers.openai.com/api/docs/models/gpt-4.1-mini): $0.40/M input and $1.60/M output tokens. [Web search](https://developers.openai.com/api/docs/pricing): $10/1,000 calls plus a fixed 8,000 search-content input-token block for this model with the non-preview tool.
- [Gemini 2.5 Flash](https://ai.google.dev/gemini-api/docs/pricing#gemini-2.5-flash): $0.30/M text input, $2.50/M output including thinking. Google Search grounding has 1,500 free requests/day shared with Flash-Lite, then $35/1,000 grounded prompts. Free quota is project-shared; the ledger deliberately uses list price without assuming free allowances or cache discounts.
- [Perplexity migration notice](https://docs.perplexity.ai/docs/agent-api/migrate-from-sonar/overview): support for Sonar Chat Completions ended **27 September 2026**. Synchronous requests continue through gradual Agent API reformulation. The documented compatibility endpoint is `/v1/sonar`. [Current pricing](https://docs.perplexity.ai/docs/getting-started/pricing) no longer supplies the old Sonar rate card. The implementation therefore uses `usage.cost.total_cost` when reported and otherwise records unknown cost; it does not apply the historical Sonar token/request rates to the migrated model.

For **1,000 reported input + 1,000 output tokens per answer**, one OpenAI search call, and one Gemini grounded answer (no thinking tokens):

| Provider | Per check | 4 weekly runs (80 checks/provider) |
| --- | ---: | ---: |
| OpenAI | $0.0152 | $1.216 |
| Gemini, without grounding free allowance | $0.0378 | $3.024 |
| Perplexity Sonar compatibility | Actual reported cost `S` | `80 × S` |
| Total | | **$4.24 + 80 × S** |

An average calendar month has about 4.35 weekly runs: **$4.61 + 86.96 × S**. If Perplexity averages an illustrative **$0.006/check**, total is about **$4.72 for 4 runs, $5.13 in an average month, or $5.90 for 5 runs**. That Perplexity figure is a planning assumption, not a verified current Sonar tariff or an invoice. With Gemini's shared free grounding allowance still available, subtract $2.80 / 4-run month from that scenario. Actual input/search/output tokens, returned model, account discounts, taxes, failed/uncertain requests and vendor policy affect the bill. Budget reservations cover uncertainty.

This workload uses **240 checks for 4 runs, about 261 in an average month, or 300 for 5 runs, per market**. Professional's 300 covers one such market; two markets require additional quota or wallet. Enterprise's 2,000 covers six markets even in a five-run month (1,800 checks). Starter fits a smaller schedule, e.g. 10 questions × 1 provider × 5 runs = 50. $0.15 overage gives room for vendor cost variation; review real per-check bills before changing it.

## Verification and limits

Commands and complete final output: `docs/seo/phase-3-test-output.txt`.

- Plain PHP offline provider/detection/evidence tests: **53/53**.
- MySQL/MariaDB integration with real plan/wallet/encryption/queue/admin/customer APIs and fake provider transports: **56/56**. The fixture script refuses an unmarked populated database and requires explicit `TF_GEO_ALLOW_FIXTURES=1`; it resets only the disposable `tourfecto_test` fixtures.
- Playwright actual customer JavaScript and admin settings markup/save functions, mocked network: **18/18**.
- Existing Phase 1 regression: **30/30**.
- Existing generated PHP connector integration: **20/20**.
- Every Phase 3 changed/new PHP file passes `php -l`; JavaScript passes `node --check`.

**Not verified live:** authenticated paid calls to OpenAI, Gemini or Perplexity; production keys/access/model availability, grounding/citation redirect behavior under actual vendor responses, actual invoices/discounts and migrated Sonar billing; production scheduler throughput; customer-site/WordPress deployment, approval/public verification/rollback on a real host; real TripAdvisor/Google login/dynamic profile coverage; observed visibility improvement over eight weeks. Public official documentation GETs were verified. Offline tests cover fake payload contracts and local persistence, not the external production services. Optional llms-full is omitted; no fabricated measurement fills these gaps.
