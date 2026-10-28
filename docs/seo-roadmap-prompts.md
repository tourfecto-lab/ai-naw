# Tourfecto SEO / AEO / GEO — أوامر التنفيذ لـ ChatGPT (المرحلة 2 وما بعدها)

## طريقة الاستخدام (اقرأ الأول)

1. استخدم **ChatGPT Codex** (أو ChatGPT اللي مربوط بـ GitHub) واربطه بالمستودع `tourfecto-lab/ai-tour`.
2. ابدأ دايمًا بـ **الأمر 0 (السياق)** في كل محادثة جديدة، وبعده أمر المرحلة.
3. نفّذ مرحلة واحدة بس في المرة. متبدأش اللي بعدها غير لما:
   - الاختبارات كلها تعدّي (ChatGPT هيكتبلك النتيجة).
   - يعمل commit و push على فرع جديد.
4. لو ChatGPT قالك محتاج مفتاح أو اشتراك (زي DataForSEO أو OpenAI)، **إنت اللي تحطه في لوحة الأدمن**. متبعتش المفاتيح في الشات.
5. بعد كل مرحلة اطلب منه: "اعمل ملف zip بالملفات المتغيرة بنفس طريقة النشر" وارفعه على السيرفر زي العادة.

---

## الأمر 0 — السياق (الصقه أول حاجة في كل محادثة)

```
You are a senior PHP engineer working on Tourfecto (repo tourfecto-lab/ai-tour), an AI SaaS for tour and travel companies. Read this context carefully before touching code.

BASE BRANCH (CRITICAL): the latest code is on branch `claude/stoic-heisenberg-66srnk`, NOT `main` (main is an old snapshot). Always `git fetch` and create your phase branch FROM `claude/stoic-heisenberg-66srnk`. Before you finish, merge the latest `claude/stoic-heisenberg-66srnk` into your branch and resolve conflicts without deleting existing features. Push real code commits (including your tests under tests/Automation/) to the phase branch — do not push a branch that only contains a ZIP. The deployment ZIP must be built from `git diff --name-only --diff-filter=AM 0127545 HEAD` (excluding tests/) so it contains every changed file since the original deployment.

STACK & CONVENTIONS
- PHP 8 custom MVC, no framework. Live classes are listed in app/Config/project_classmap.php — every NEW class must be added there or it will not load in production.
- Routes: app/routes/web.php and app/routes/api.php. Middleware is an array like ['AuthMiddleware','AdminMiddleware'] or 'PlanGateMiddleware:ai_tool'.
- Admin API permissions: app/Services/Admin/AdminAccess.php (map new /api/admin/* paths to a permission).
- Cron: add jobs to cron/schedule.php (one central scheduler runs every minute). Each cron script requires cron/bootstrap.php.
- Settings & secrets: SystemSettingsService (app/Services/System/SystemSettingsService.php). Register new keys in its $registry with is_secret=true for API keys (they are encrypted; never return secrets to the UI).
- Billing/quotas: PlanUsage (app/Services/Subscription/PlanUsage.php) features + PlanGateMiddleware. Any paid external API call must be metered (plan quota first, then wallet) — never give unlimited paid calls.
- MySQL. Never run CREATE TABLE / ALTER inside a transaction (implicit commit). Add a migration SQL file under database/migrations/ AND a safe ensureSchema() that checks information_schema first.
- Customer-facing UI text is Egyptian Arabic (with English fallback where the page supports it). Code comments may be Arabic or English, match the surrounding file.
- Deploy is by ZIP upload (no SSH, no composer on server). Do not add composer dependencies; use curl.
- Tests: plain PHP scripts in tests/Automation/*.php using a tiny check($cond,$msg) helper and printing "N/M passed" (see tests/Automation/seo_phase1_test.php and tests/Automation/xpay_test.php). External APIs MUST be injectable (constructor transport callable) so tests run offline with fakes. DB tests use DB_NAME=tourfecto_test DB_USER=tf DB_PASS=tfpass DB_HOST=127.0.0.1 ENCRYPTION_KEY=<32 bytes base64>.

NON-NEGOTIABLE PRODUCT RULES
- Never invent data: no fake search volumes, rankings, prices, reviews, citations or statistics. If a data source is not configured, show "not measured" and create a clear task — do not let the LLM guess numbers.
- Every automatic change to a customer site must be: approvable, applied through the existing connector (EdgeRules), verified on the live public page (PageContext::verifyValue), and reversible.
- Security: SSRF-safe fetching (SafeHttp), escape all output, validate every value before it reaches a customer site, never send secrets to the browser, never log API keys.
- Keep costs controlled: per-plan quotas, caching, batch limits, and an admin on/off switch for every paid provider.

SEO MODULE MAP (what already exists)
- SEO Expert cycle: app/Services/SeoExpert/SeoExpertService.php (scan -> techChecks step -> metrics -> opportunities -> LLM plan -> tasks -> approve -> execute apply/verify). Tasks table seo_expert_tasks. Plan quality filter SeoPlanQualityGate. Opportunities: SeoOpportunityService.
- Phase 1 (DONE): SeoSchemaBuilder (TouristTrip/Breadcrumb from real page data), PageSpeedService (real Lighthouse + CrUX), GoogleSearchConsoleAPI::inspectUrl/listSitemaps, related-trip internal links + image alt via connector body helpers (EdgeRules::bodyHelpers/wpBodyHooks), builder-site schema/sitemap/robots (WebsiteBuilderController), SeoTrafficAlertService (daily GSC drop alert), content engine fixes (real published URL for IndexNow, WP slug, weekly A/B alternation).
- Connector: app/Services/AutoFix/EdgeRules.php (sanitize(), rulesFor(), WordPress snippet + PHP connector). PageContext.php parses public pages.
- Rank tracking: app/Services/Seo/RankTrackingService.php + DataForSeoRankingSource.php (needs serp_* settings, global location only, 20 keywords max per call). Competitor keyword tracking: app/Services/CompetitorIntelligence/KeywordRankingService.php + cron/ci_keyword_rankings.php (broken: table competitor_keywords is never populated, and it sends 100 keywords while the source rejects >20).
- Keyword research: KeywordResearchService (generic HTTP source, untested) / AIController::discoverKeywords (AI guesses, no volume) / AIController::syncSearchConsoleKeywords (stores impressions as search_volume — wrong).
- Impact measurement: SeoImpactService (GSC/GA4 before-after, manual AI citation import).

WORKING METHOD
1. First read the files named in the phase prompt and summarize in 10 lines what you found (and any risk) BEFORE coding.
2. Implement in small, reviewable commits on a new branch named seo/phase-N.
3. Write/extend offline tests for every new service; run php -l on every changed PHP file; run tests/Automation/seo_phase1_test.php and php tests/Automation/php_connector_test.php to prove nothing regressed.
4. Finish with: list of changed files, test output, what the admin must configure, and what is NOT done/verified (be honest).
Reply "ready" when you have read this.
```

---

## الأمر 1 — المرحلة 2: أرقام بحث حقيقية + تتبّع الترتيب (DataForSEO)

```
PHASE 2 — Real keyword demand and rankings (DataForSEO). Branch: seo/phase-2.

Goal: replace AI-guessed keywords with real search data so plans target keywords people actually search, per market (tourism customers sell to many countries/languages).

Read first: app/Services/Seo/RankTrackingService.php, app/Services/Seo/DataForSeoRankingSource.php, app/Services/Seo/KeywordResearchService.php (+ its sources), app/Controllers/AIController.php (discoverKeywords, syncSearchConsoleKeywords, analyzeKeywords), app/Services/CompetitorIntelligence/KeywordRankingService.php, cron/ci_keyword_rankings.php, cron/seo_rank_tracking.php, app/Services/SeoExpert/SeoOpportunityService.php, SystemSettingsService registry (serp_*).

Build:
1. DataForSeoClient (one class, injectable transport, Basic auth from serp_login/serp_password, per-call cost logging in a table dataforseo_calls with endpoint, cost, website_id, created_at). Admin can set a monthly budget cap in USD; when reached, calls stop and the admin gets a notification.
2. Markets per website: table seo_markets (website_id, location_code, language_code, label e.g. "UK – English", "Germany – German", is_primary). Admin/customer UI to add up to N markets by plan (starter 1, professional 3, enterprise 6). Default market from the site language.
3. Keyword research with REAL volume/difficulty/CPC/intent: DataForSEO Labs (keyword_ideas or keywords_for_site + bulk_keyword_difficulty) per market. Store in seo_keyword_research (website_id, market_id, keyword, volume, difficulty, cpc, intent, source='dataforseo', fetched_at). Cache 30 days. Fix syncSearchConsoleKeywords so impressions are stored as impressions, not search_volume.
4. Rank tracking per website per market: tracked keywords belong to a market; RankTrackingService uses that market's location/language. Batch in chunks of <=20 (the source limit). Normalize host matching (www vs bare, trailing slash, http/https) so positions are not lost. Keep history (date, position, url).
5. Competitor keywords: populate competitor_keywords using DataForSEO Labs ranked_keywords for each tracked competitor domain (top N by volume, per primary market). Fix cron/ci_keyword_rankings.php to batch <=20.
6. Keyword gap: keywords where competitors rank top 10 and the customer is absent or >20, with volume — feed into SeoOpportunityService and the SEO Expert prompt as VERIFIED research (mark source and date). The plan prompt must say volumes come from DataForSEO.
7. Quotas: add PlanUsage feature 'seo_data' (keyword lookups + rank checks). Starter small, professional medium, enterprise large; overage from wallet with a price in pay_per_use_pricing. Admin switch to disable.
8. UI (Arabic): in the keywords page show volume, difficulty, intent, current rank per market, 30-day trend; a "Keyword gap vs competitors" table; empty state explains "اربط DataForSEO من الأدمن" when not configured — never show fake numbers.

Acceptance:
- Offline tests with fake DataForSEO responses: research parsing, market-specific rank check, batching of 45 keywords into 3 calls, www/bare host matching, budget cap stops calls, quota deduction (plan then wallet then blocked), gap calculation.
- With serp settings empty, nothing calls the API and the UI says "not measured".
- Report the expected monthly DataForSEO cost for 1 customer with 50 keywords × 2 markets checked daily, and suggest the plan prices/quota accordingly.
```

---

## الأمر 2 — المرحلة 3: الظهور في ChatGPT وGemini وPerplexity (GEO)

```
PHASE 3 — AI visibility (GEO) measurement and improvement. Branch: seo/phase-3.

Goal: measure whether AI assistants mention/cite the customer when travelers ask buying questions, then improve it with concrete tasks.

Read first: app/Services/SeoExpert/SeoExpertService.php (metrics() returns ai_visibility = not_measured), SeoImpactService (importAi), FixValueBuilder (llms.txt), EdgeRules (llms_txt), existing AI provider clients (search for AiText, AIOrchestrator, GeminiClient, any OpenAI client).

Build:
1. Prompt set per website and market: 10–30 realistic traveler questions generated from confirmed business facts + real keywords from Phase 2 (e.g. "best Luxor day tour from Hurghada", "Nile cruise Aswan to Luxor price"). Customer can edit/approve the list. No brand name in the questions (we measure organic mentions).
2. Providers (admin enables each, keys in SystemSettingsService, secrets encrypted):
   - OpenAI Responses API with web search tool.
   - Google Gemini with Google Search grounding.
   - Perplexity Sonar API.
   Each provider class has an injectable transport and returns: answer text, cited URLs, model, cost.
3. Weekly job (cron) runs each approved question once per provider per market; stores seo_ai_answers (website_id, market_id, provider, question, mentioned_brand bool, brand_position (order of first mention), cited_domain bool, cited_urls json, competitors_mentioned json, answer_excerpt, cost, checked_at). Brand/competitor detection by name + domain, case/diacritics-insensitive, Arabic and Latin names.
4. Metrics: share of voice per provider (mentions / questions), citation rate, top competitors that appear instead, trend over 8 weeks. Replace ai_visibility 'not_measured' in SEO Expert metrics with these real numbers and pass them to the plan prompt.
5. Improvement tasks generated from evidence: pages AI cites for competitors (fetch them, compare with the customer page: missing facts, prices, FAQs, schema, reviews), missing third-party presence (TripAdvisor, GetYourGuide, Viator, Google Business reviews) when competitors are cited from those sources, entity consistency (same name/phone/address across site and profiles; Organization sameAs with real profile URLs entered by the customer only).
6. Improve llms.txt: include real trip pages with one-line summaries and prices exactly as published, contact, languages; plus /llms-full.txt optional. Never claim ranking benefits.
7. Quotas: PlanUsage feature 'ai_visibility' (number of question×provider checks per month by plan), wallet overage, admin kill switch, monthly budget cap.
8. UI (Arabic): "ظهورك في الذكاء الصناعي" page: score cards per provider, questions table with ✓/✗ and who was mentioned instead, cited sources, trend chart, and the generated tasks.

Acceptance:
- Offline tests with fake provider answers: mention detection (Arabic + English, partial names), citation detection by domain, share-of-voice math, budget cap, quota, no brand leakage into questions.
- When no provider is configured, the page shows "not measured" with setup steps; nothing is estimated.
- Report cost per customer per month for 20 questions × 3 providers weekly.
```

---

## الأمر 3 — المرحلة 4: تحديث المحتوى + المقالات الكاملة على ووردبريس

```
PHASE 4 — Content quality loop. Branch: seo/phase-4.

Read first: app/Services/Seo/SeoContentService.php, app/Services/AI/ArticleGenerator.php (FAQPage generation), app/Jobs/PublishScheduledArticleJob.php, app/Services/Publishing/WordPressPublisher.php, app/Services/AutoFix/WordPressBundle/tourfecto-page-editor.php and tourfecto-native-seo.php.

Build:
1. Content briefs from real data before writing: target keyword + volume (Phase 2), the top 5 ranking pages' headings and word counts (fetch with SafeHttp), People Also Ask questions (DataForSEO SERP), and the customer's confirmed facts. The article generator must follow the brief; no invented prices/claims.
2. Publish SEO fields to WordPress: add a signed REST endpoint in the Tourfecto WordPress plugin (bundle) that sets Yoast/RankMath/native SEO title, meta description and focus keyword for a post the platform just created (reuse TourfectoNativeSeo adapters). Save ArticleGenerator FAQs and inject FAQPage + Article JSON-LD for that post via the connector page_rules (EdgeRules jsonld) — not inside post content.
3. Topic clusters: group keywords into clusters (pillar trip/destination page + supporting articles) and add internal links article -> pillar and pillar -> articles through the connector related_links mechanism.
4. Content decay: weekly job compares GSC clicks/position per page (last 28 days vs previous 28 days). Pages losing >25% clicks or >3 positions become "refresh" tasks with a concrete brief (new questions, outdated year/prices to confirm, competitors that overtook).
5. Fix SEO Expert content drafts so they are themed: for WordPress publish as real posts; the PHP connector /guides pages must use the site's header/footer when possible and must be added to the site sitemap.
Acceptance: offline tests for brief building, WP SEO-field endpoint signature validation, FAQ schema injection, decay detection math. Document plugin update steps for customers.
```

---

## الأمر 4 — المرحلة 5: السيو المحلي + الباك لينكس + التقارير

```
PHASE 5 — Local SEO, links and reporting. Branch: seo/phase-5.

Read first: app/Services/Reputation/GoogleBusinessAPI.php, GbpLocalSeoAuditService, cron/gbp_enqueue_background_sync.php (exists but NOT scheduled), BacklinkMonitorService, CompetitorBacklinkDiscoverySource, SeoScheduledReportService, SeoImpactService, SeoTrafficAlertService.

Build:
1. Schedule GBP background sync in cron/schedule.php and verify it is idempotent.
2. Local rank grid: DataForSEO Google Maps SERP for 2–3 keywords on a 5×5 grid around the business location (plan-limited), heatmap UI, monthly history.
3. NAP/citation consistency: compare name/phone/address/website across Google Business, the website (Organization schema/footer) and customer-entered directory profiles; tasks for mismatches.
4. Backlink monitor fix: a link is "live" only if the page contains an <a> to the customer's domain; record rel (nofollow/sponsored/ugc) and anchor text; alert when a link disappears.
5. Real link data (optional provider, admin switch): DataForSEO Backlinks API for referring domains of customer vs competitors -> link gap list (domains linking to 2+ competitors but not the customer) feeding the outreach module. No fake prospects.
6. Monthly report email (Arabic, PDF-friendly HTML): GSC clicks/impressions/top queries, rankings per market, AI visibility share, Core Web Vitals, tasks done and their measured impact (SeoImpactService auto-created monitors for every applied change), next month plan.
7. Auto impact monitors: every applied/verified SEO Expert task automatically creates a SeoImpactService monitor (7/14/28 days) — no manual step.
Acceptance: offline tests for grid generation, NAP comparison, backlink live detection, link gap, report data assembly, auto monitor creation.
```

---

## الأمر 5 — المرحلة 6: لغات متعددة + الفهرسة على دومين العميل

```
PHASE 6 — International & indexing polish. Branch: seo/phase-6.

Build:
1. Builder sites multilingual: per-language versions (/en, /de, /ar ...) generated from confirmed content with human review, hreflang alternates + x-default, per-language sitemap entries.
2. Connector hreflang for WordPress sites using WPML/Polylang when detected (read their language map; never guess translations).
3. IndexNow key file served on the CUSTOMER domain through the connector (WordPress route and PHP connector), validated before submitting URLs; submit only verified-live URLs.
4. Optional Search Console full scope (webmasters) as a separate "allow sitemap submit" reconnect flow; when granted, submit the sitemap automatically and list errors.
Acceptance: offline tests for hreflang map generation, IndexNow key route, sitemap submission with fake GSC.
```

---

## بعد كل مرحلة اسأل ChatGPT الأسئلة دي

```
1) Show me the full test output and php -l results.
2) List every new admin setting / API key I must configure and the exact menu path.
3) What is the monthly cost per customer for the new paid APIs, and which plan quota did you set?
4) What did you NOT verify against the real external API?
5) Build the deployment ZIP of files changed vs main (exclude tests/) and list them.
```
