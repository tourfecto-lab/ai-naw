# Customer-cycle quality review update

Base 747ab91, branch seo/content-publication-polish.

Customer cycle 10 reached draft after scanning 87 pages, with a measured `luxor day tour` target (volume 90, difficulty 0), and created 38 reviewable tasks. This verifies the previous target-selection and transaction-context fixes on the deployed platform. It does not prove a ranking improvement or full-site coverage.

Review found incorrect TouristTrip/related-trip proposals for destination collections and a functional quiz, plus unsupported duration/family assertions in the article draft. Only the reviewed breadcrumb task for /tours was selected for the live execution test. No article or incorrect trip proposals were approved.

Changes:
- Quiz routes are outside marketing scope. Plural collection headings and existing ItemList/CollectionPage schema cannot establish a single bookable trip. Actual individual trips on /tour and /tours routes remain supported; safe collection breadcrumbs remain supported.
- The execution worker checks old unapplied trip-schema and related-trip proposals against fresh page evidence too. This does not remove or rewrite existing active rules.
- Article validation requires literal confirmed business/offer evidence for numeric durations and specified positive family-suitability assertions. Audience demographics, competitor excerpts, keywords and campaign goals cannot prove these claims. These conservative checks cover the observed cases; they are not a complete semantic fact checker. Different wording may require human confirmation, and every draft still requires editorial review.
- New briefs store observed same-site page URLs. Markdown links to unobserved internal targets need review. External citations remain independent and are not thereby verified. Historical briefs without this evidence list retain their existing link behavior.
- A generated draft rejected by quality checks creates a visible client confirmation task, while the safe remainder of the plan can complete. Publication checks also run against stored quality briefs, including older unapproved drafts. Editing text alone does not confirm customer facts or update stale FAQ answers.

Installation: back up the affected platform files; upload the archive's app directory preserving its paths; restart persistent queue workers and reload PHP OPcache. No SQL, customer connector replacement, or credentials are required. The archive includes the prior selector helper so no class map generation is needed. Do not approve all old tasks: review their purpose and evidence individually. This update does not regenerate plans or initiate provider calls.

Offline verification: seo_customer_quality_test.php 26 assertions; seo_content_target_test.php 20 assertions; seo_planning_failure_database_test.php 20 assertions including a real transaction proving rejected content becomes a needs-client task. Providers and billing are fakes in this integration test; persistence uses the guarded disposable database. Full required suites were run: 34/36 checks pass. Existing baseline billing failures remain plans_studio 40/47 and plan_gate 19/24. Test output is included.

Rollback: restore the backed-up runtime files together, then restart workers/OPcache. No database rollback is needed. Existing reviewed customer content and tasks are retained.
