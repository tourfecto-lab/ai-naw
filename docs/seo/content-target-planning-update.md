# Content target selection and planning persistence update

Base: bb1521d on seo/content-publication-polish.

Install on the PLATFORM hosting, preserving the paths in this archive. Back up the two runtime files first. Replace app/Services/SeoExpert/SeoExpertService.php and upload app/Services/SeoExpert/SeoContentTargetSelector.php. Restart persistent PHP queue workers and clear/reload PHP OPcache through the hosting control panel. No SQL migration, customer connector update, or new credentials are needed. The helper is explicitly required; regenerating the class map is unnecessary.

Selection now requires fresh real DataForSEO records in the primary configured market, confirmed business terms and an eligible same-site pillar. Audience countries and campaign goals do not authorize services. Relevance precedes measured difficulty and volume. Unknown difficulty stays unknown; measured zero volume remains valid. Unsupported, stale, navigational and unrelated high-volume terms cannot trigger article writing. If no supported target exists, metadata planning continues without an article draft. Selected evidence and the no-target/brief state are saved in the run snapshot. This conservative lexical check can decline valid synonyms; confirm those service terms in the business profile rather than inventing relevance.

The task persistence transaction now captures the site context used by schema, technical and related-link tasks. A valid-plan database regression fails without this fix and succeeds with it. Cycle allowance release also avoids unsigned subtraction underflow when the counter is already zero.

Failures record a safe stage, code and SEO-run reference in snapshot.planning_diagnostic, with an actionable public error. No raw generated payload, SQL or exception message is added to these diagnostics. Invalid generated plans remain rejected. Unknown provider outcomes remain held for review, without automatic generation retries. Definite failures release their usage reservation; failed runs do not silently regenerate.

The previous production cycle's exact failure cannot be reconstructed from its generic historical error. This update does not rewrite that history or automatically launch a paid cycle. After deployment, review the confirmed profile and selected target on a new explicitly authorized cycle. No existing feature or research record is removed.

Validation: seo_content_target_test.php (20 assertions); seo_planning_failure_database_test.php (17 assertions, fake provider/billing, guarded disposable MariaDB). The latter checks malformed response failure, no private response leakage, no tasks on invalid output, allowance release, no repeated AI call, unknown outcome handling, zero-counter safety, and successful valid-plan persistence. Full suite output is included separately. Billing baseline failures remain plans_studio 40/47 and plan_gate 19/24; do not interpret the release as all tests passing.

Rollback: restore the backed-up SeoExpertService.php and restart workers/OPcache. Keep this additive helper if uncertain; the old service does not reference it.
