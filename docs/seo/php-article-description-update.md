# Publish the reviewed PHP article description

Base c7fcdbe, branch seo/content-publication-polish.

The live article's saved SEO description differed from the public meta description. EdgeRules built the PHP article description from the beginning of the body, including Markdown. The compiler now uses the reviewed description from the published quality record belonging to the same website owner and exact task source. Empty or missing review records retain the existing legacy excerpt fallback. No article content, publication URL, title or existing schema is removed.

The publication checklist also compares the public description with the reviewed description. A raw body excerpt can no longer pass that quality check when a reviewed value exists. The check is named in Arabic and English in the interface.

Install on PLATFORM hosting: back up and upload app and public_html from this archive preserving paths, then restart persistent workers/reload OPcache and refresh the SEO Expert page. No SQL, paid generation, article recreation or manual change to the saved draft is required. The connector fetches the corrected description in its normal rules response.

The archive includes the prior inline-link renderer and verification files. The platform's freshly downloaded customer-specific PHP connector was independently confirmed to contain that renderer. If the customer site still displays literal Markdown links after replacing its file, reload its PHP service and verify that the file being loaded is the one specified by its existing auto_prepend_file path. Do not change open_basedir or replace .user.ini. A new description payload alone does not prove that the customer PHP process is running the updated renderer.

Verification: 37/39 groups pass. Existing baseline billing failures remain plans_studio 40/47 and plan_gate 19/24. Content/publication contracts: 44 assertions; real database/edge compiler: 13 assertions, including the reviewed description in the PHP payload. Inline-link checks: 14, generated connector checks: 40. All prior required phase, research, connector, plans, xpay, plan_gate and fx_bank suites were run. Full output included.

Deployment status must be verified separately on the customer's existing article: HTTP 200, reviewed meta description, actual clickable inline URLs, complete visible text, matching Article/FAQ schemas and sitemap entry. Public availability and sitemap discovery do not prove Google indexing or a ranking improvement.

Rollback: restore the backed-up platform files together and restart workers/OPcache. No database rollback, article deletion or customer hosting configuration change is needed.
