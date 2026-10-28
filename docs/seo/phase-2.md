# Phase 2: real keyword demand and market-specific rankings

The keyword page at `/ai/keywords` now uses measured DataForSEO research. Markets isolate location, language, keyword metrics and rank history. Search Console impressions and its aggregate average position are separate fields; neither is monthly demand or a market-specific SERP measurement. AI discovery and guessed difficulty writes have been removed from these keyword endpoints.

DataForSEO is disabled unless `serp_enabled=1` and both encrypted credentials are present. Missing metrics remain null and the Arabic page says `اربط DataForSEO من الأدمن — not measured`. No application Composer dependencies were added.

## Deployment and administrator configuration

1. Back up the deployment database and run `database/migrations/2026_10_17_000001_seo_dataforseo_markets.sql` outside a transaction. It is repeatable. The runtime `SeoDataSchema::ensureSchema()` fallback checks `information_schema`, serializes fallback installation and refuses DDL inside a transaction.
2. Upload the changed application files **and** `app/Config/project_classmap.php` in the deployment ZIP. New classes are registered there; no server-side Composer command is required.
3. As Super Admin, open **لوحة الأدمن → الإعدادات → إعدادات النظام والربط (API) → DataForSEO — بحث الكلمات والترتيب** (`/admin/settings`) and configure `serp_login`, `serp_password`, a positive `serp_monthly_budget_usd`, and `serp_enabled=1`. Credentials remain encrypted and are never returned to the page. `serp_device` remains supported. Global `serp_location_code`/`serp_language_code` are legacy settings; website markets now determine targeting.
4. Allow HTTPS to `api.dataforseo.com`. The Labs/Google and Google organic SERP endpoints must be available on the provider account. Validate current provider tariffs before enabling paid execution.
5. Customers manage markets on `/ai/keywords`; authorized administrators manage them at `/admin/seo-markets`. Both admin API routes require `settings.manage`. Enter a valid DataForSEO location code, language and label. The first market defaults from the website's audience language: English→US, Arabic→Egypt, German→Germany, French→France, Spanish→Spain, Italian→Italy. These are editable defaults, not inferred customer demand; unsupported languages require an explicit first market.
6. Existing central scheduler entries `seo_ranks` and `ci_rankings` are reused. Do not add duplicate crontab entries. Keep `serp_enabled=0` to disable all paid SEO provider operations.

Tier defaults are 1/3/6 markets and 300/4,000/20,000 `seo_data` units per subscription quota month for starter/professional/enterprise. Existing explicit `features_json` allowances take precedence. Negative SEO allowances do not become unlimited paid calls. Plan events are consumed first; exhausted allowances use the existing atomic wallet service; insufficient funds roll back the entire request's partial deductions before any provider call. The migration inserts an active `seo_data` wallet price of **$0.05/unit** without changing an existing administrator price. It does not change subscription selling prices.

One rank check consumes one unit. Research reserves up to 100 lookup units per bounded ideas/site request, then one per returned difficulty lookup. Exact tracked-keyword overview reserves one per input keyword, plus difficulty lookups. Competitor discovery reserves its configured result limit (default 50, maximum 100) per competitor. These are bounded lookup allowances, not HTTP request counts; cache hits cost zero units. Provider submission failures/timeouts retain the usage charge because a response failure cannot prove the provider performed no paid work.

Budget accounting is global to the configured provider account, uses UTC calendar months, and records the endpoint, actual reported USD cost, website, timestamp and status. Concurrent calls reserve headroom before transport. Reservations are conservative **$0.10 per Live SERP task** and **$1 per bounded Labs request**, not advertised provider tariffs. A request may stop before the cap if its reservation would exceed the remaining headroom. Actual costs replace reservations when known; unknown outcomes retain them for administrator reconciliation. An in-app administrator notification is deduplicated by month, including when settlement reaches the cap. Validate that tariff changes remain below these reservation bounds before enabling the integration; they are not a provider-side spend guarantee.

## Research and ranking behavior

- Ideas/site research and exact keyword overview use Labs; bulk difficulty is joined by keyword. Volume, CPC and intent are parsed from actual response fields. A missing field stays unmeasured, including missing intent; a measured zero stays zero.
- Research cache keys include input, endpoint, site and market, expire after 30 days, and serialize identical refreshes. Display/planning joins exclude stale research and legacy guessed numbers.
- Tracked keywords are unique within a website and market. Legacy unassigned keywords move to the first market with their unscoped current rank cleared; their old history remains unassigned rather than gaining false geographic provenance. Following a downgrade, excess markets are retained but excluded from active selection/tracking until the allowance permits them.
- Daily ranks retain date, market, position and URL; the page exposes the 30-day series and position change. `null` after a successful complete SERP means absent from the first 100 results. Missing/failed responses do not create an absence measurement. Already measured keywords are not charged again within 24 hours. A website lock prevents overlapping rank runs.
- Source calls are chunked into 20/20/5 for 45 keywords. **The Live endpoint accepts one task per HTTP request**: those three source batches still make 45 paid HTTP requests. This implementation does not promise three HTTP calls or implement asynchronous Standard SERP submission/polling. A scheduler run processes at most 500 due keywords per active market; leftovers remain due.
- Competitor discovery fills `competitor_keywords` from Labs ranked keywords, ordered by real search volume, for up to five active competitors in the primary market and refreshes after 30 days. The competitor ranking runner also chunks source calls at 20.
- A VERIFIED gap requires a recent DataForSEO competitor position 1–10, measured demand and a recent customer position >20 or measured absence in the same market. Untracked keywords are unknown, not automatically absent. The user-triggered gap refresh follows and measures up to 50 candidate keywords before classifying them; it does not trigger unrelated tracked keywords. The SEO Expert receives source, market and fetch date and explicitly says volumes come from DataForSEO.

## Monthly provider cost and plan quota

Public pricing checked on 2026-10-17 (Africa/Cairo):

- [Google Organic SERP](https://dataforseo.com/pricing/google-serp/google-organic-serp-api): Live is **$0.002 per 10 results**. Our depth=100 requests cost up to **$0.020 per keyword/market check**. Fewer returned result pages may be refunded. Location, language and device do not add a surcharge.
- [Google Labs](https://dataforseo.com/pricing/dataforseo-labs/dataforseo-google-api): the implemented research/overview/difficulty/ranked-keywords endpoints are **$0.012/task + $0.00012/item**, with clickstream disabled.
- Cost-multiplying Google search operators are rejected before rank API submission; these checks accept plain keywords. Budget reservations are not actual provider prices.

For **50 keywords × 2 markets × 30 days**, ranks cost up to **3,000 × $0.020 = $60/month** ($62 over 31 days). Two site research refreshes, one per market, each returning 100 items plus 100 difficulty items, add **4 × ($0.012 + 100 × $0.00012) = $0.096** per 30-day cache cycle. Baseline: approximately **$60.10/customer/30 days**, excluding competitors, extra tracked keywords and manual requests.

Competitor cost is additional and is enabled for active, unpaused competitors by the existing daily `ci_rankings` scheduler:

| Example workload | 30-day provider cost, at full depth |
| --- | ---: |
| Customer 50 keywords × 2 markets, daily | $60.00 |
| Site research + difficulty, 100 keywords × 2 markets, once | $0.096 |
| One competitor's 50 keywords, daily ranks | $30.00 |
| One competitor's 50 Labs ranked keywords, once | $0.018 |
| Five competitors, including daily ranks and monthly discovery | $150.09 |
| Customer baseline plus five competitors | **$210.186 ≈ $210.19** |

A new tracked keyword adds up to **$0.60 per market per 30 days**. Gap refresh can follow up to 50 new primary-market keywords; if they remain followed all month, that can add up to **$30/month** and 1,500 quota units. Existing tracked words are not duplicated. Actual bills depend on returned pages/items, cache hits, active competitor count, remaining allowance/wallet funds and the global budget. No account-specific invoices, discounts or real charged tasks were verified.

These are the code defaults actually set:

| Plan | Markets | `seo_data` units per quota month | Suggested selling price (not applied) |
| --- | ---: | ---: | ---: |
| Starter | 1 | 300 | $29 |
| Professional | 3 | 4,000 | $199 |
| Enterprise | 6 | 20,000 | $799 |

One rank check consumes one unit. Site/ideas research reserves 100 units plus one for each returned difficulty item. Competitor research reserves 50 units by default. The customer baseline above uses about **3,400 units**; five daily competitors add **7,500 rank units + 250 research units**, totaling **11,150 units** without new gap words. **Professional does not cover that five-competitor scenario**: after its 4,000 included units, 7,150 additional units would cost **$357.50 from the wallet** at the default $0.05/unit, or execution blocks if funds are unavailable. Enterprise covers that example. Existing explicit `features_json` quotas/pricing overrides remain authoritative. Subscription selling prices were not modified.

Wallet price menu: **لوحة الأدمن → الباقات والأسعار → تسعير "ادفع حسب الاستخدام"** (`/admin/plans`), row **قياس كلمة SEO** (`seo_data`). Market management is linked from the DataForSEO settings section to `/admin/seo-markets`.

## Validation

Tested on PHP 8.4.24 and local MariaDB 11.8.6. No real DataForSEO calls were made.

```bash
cd /workspace/ai-tour
export PATH=/workspace/.tools/bin:$PATH
php tests/Automation/seo_phase2_test.php
# 29/29 passed — response parsing, market targeting, batching, hosts, no credentials, budget and gaps

# Only an EMPTY disposable tourfecto_test, or this test's marked fixture database:
TF_SEO_ALLOW_FIXTURES=1 TF_SEO_UI_ARTIFACT=/tmp/seo-phase2-ui.html \
  php tests/Automation/seo_phase2_database_test.php
# 46/46 passed — actual Database/PlanUsage/WalletService, both schema install paths,
# repeatability, caches, tenant/market isolation, notifications, history, rollback and SEO Expert provenance

# Optional, using an already installed Playwright/Chromium; no application dependency added:
PLAYWRIGHT_MODULE_PATH=/opt/codex/runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright \
  node tests/Automation/seo_phase2_browser_test.cjs
# 12/12 passed — actual generated page JS and Panel helpers, mocked API responses,
# Arabic empty state, market selection/create, zero values and safe keyword rendering
```

The database test requires the user's local test conventions (`DB_HOST=127.0.0.1`, `DB_NAME=tourfecto_test`, `DB_USER=tf`, `DB_PASS=tfpass`) and generates an ephemeral encryption key in memory. It refuses an existing unmarked database and resets only its disposable fixture on reruns. Never run it on staging/production. The fixtures isolate audience configuration and logging side effects; provider transports are fake, while SQL, models, quota consumption and wallet charging are real.

All 27 changed/new PHP files passed `php -l`. The requested `tests/Automation/seo_phase1_test.php` and `tests/Automation/php_connector_test.php` do not exist in this checkout; invoking each reported “Could not open input file”. They are **unrun**, not passed. Existing connector code was not changed.

Not verified: real credentials/provider entitlement, current authenticated response compatibility and actual charged costs, authenticated full-application navigation, production MySQL deployment, production cron execution, or end-to-end SEO plan execution. Browser checks exercise the generated feature page against mocked responses, not the full authenticated deployment. No environment publication or production deployment was performed.

## Cloud development configuration

Saved a reusable `install_script` for the rootless PHP/MariaDB tools and `start_skill` for activation, retained-database startup and readiness/test commands. The installation script was executed successfully; the local database was restarted and answered `SELECT 1`. The custom network draft adds `api.dataforseo.com`, `docs.dataforseo.com` and `dataforseo.com`, preserving existing package-manager presets. Saving the draft does not apply those domains or publish the environment. Review and save the environment settings, then publish if you want to activate this configuration. A new-task restoration has not been verified.

## Changed files

- `app/Config/project_classmap.php`
- `app/Controllers/AIController.php`
- `app/Controllers/AdminController.php`
- `app/Controllers/SeoDataController.php`
- `app/Controllers/SeoInsightsController.php`
- `app/Models/TrackedKeyword.php`
- `app/Services/Admin/AdminAccess.php`
- `app/Services/CompetitorIntelligence/KeywordRankingService.php`
- `app/Services/Seo/DataForSeoClient.php`
- `app/Services/Seo/DataForSeoRankingSource.php`
- `app/Services/Seo/DataForSeoResearchService.php`
- `app/Services/Seo/KeywordResearchService.php`
- `app/Services/Seo/RankTrackingService.php`
- `app/Services/Seo/SeoDataLedger.php`
- `app/Services/Seo/SeoDataSchema.php`
- `app/Services/Seo/SeoKeywordGapService.php`
- `app/Services/Seo/SeoMarketService.php`
- `app/Services/SeoExpert/SeoExpertService.php`
- `app/Services/SeoExpert/SeoOpportunityService.php`
- `app/Services/Subscription/BillingPlanCatalog.php`
- `app/Services/Subscription/PlanUsage.php`
- `app/Services/System/SystemSettingsService.php`
- `app/routes/api.php`
- `app/routes/web.php`
- `cron/ci_keyword_rankings.php`
- `database/migrations/2026_10_17_000001_seo_dataforseo_markets.sql`
- `docs/seo/phase-2.md`
- `tests/Automation/seo_phase2_browser_test.cjs`
- `tests/Automation/seo_phase2_database_test.php`
- `tests/Automation/seo_phase2_test.php`
