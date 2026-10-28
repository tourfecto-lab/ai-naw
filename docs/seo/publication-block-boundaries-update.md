# Verify publication text across HTML block boundaries

Base: 8a80d89, branch seo/content-publication-polish.

The customer article now exposes its reviewed description and real clickable links on desktop and mobile. The remaining publication verification reported approved_content=missing because DOM textContent concatenates adjacent HTML blocks. For example, <h2>Choose your route</h2><p>Read Luxor tours.</p> became "Choose your routeRead Luxor tours." The approved source correctly normalizes to separate words.

PageContext now provides a protected bodyText hook. Its default extraction is unchanged. SeoExpertPageContext adds separators around block elements and line breaks only during freshPublication reads, using a cloned DOM. Inline formatting remains intact: Con<strong>firm</strong> stays Confirm. Ordinary planning reads keep their prior extraction and limits. An actually omitted paragraph still fails verification.

Install on the PLATFORM hosting (tourfecto.pro): back up and upload the archive's app directory preserving both paths, then restart persistent PHP workers/reload platform OPcache. The two runtime files must be deployed together:

- app/Services/AutoFix/PageContext.php
- app/Services/SeoExpert/SeoExpertPageContext.php

This does not require changing the customer's connector or .user.ini, SQL, another article generation, or republishing. Tests and documentation do not need to be uploaded to public hosting. Existing scheduled verification can observe the corrected text. If the cycle has completed, its Recheck public pages action queues verification without publishing or starting another paid cycle. A monitoring cycle must finish before that action is available.

Verification: the offline guide suite passes 18 assertions, including compact headings/paragraphs/lists, inline word preservation, line breaks, unchanged planning reads and rejection of omitted content. On a private copy of the actual public article and its approved source, the prior reader reports approved_content=missing and the fixed reader reports passed; links, description and FAQ schema also pass. No customer data, connector token or authentication state is included in this archive or repository tests.

All 39 suite groups were run after this change: 37 pass. Existing baseline billing failures remain plans_studio (40/47) and plan_gate (19/24); they were not suppressed. Phase 1–6, research, connector, xpay and fx_bank checks pass. Complete output is in publication-block-boundaries-test-output.txt.

Public browser checks separately confirm HTTP 200, reviewed title/description/canonical, the site's header/footer, visible FAQ answers, one Article and one FAQPage with three questions, all intended clickable inline URLs, no raw Markdown links, no horizontal overflow and no JavaScript errors at widths 1440 and 390. The article is present in the valid connector sitemap. These observations do not establish Google indexing or ranking improvements. The production task remains pending platform deployment and subsequent worker verification.

Rollback: restore the two backed-up platform files together and restart PHP workers/OPcache.
