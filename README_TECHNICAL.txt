TOURFECTO — SOCIAL QUOTA PATCH (PARTIAL, VERIFIED SYNTAX)

What this patch actually fixes:
1) Atomic reservation of publishing slots when approving an AI monthly plan.
2) Concurrent approvals cannot both consume the same remaining slots.
3) The reservation occurs inside the existing monthly approval transaction, so if creating scheduled posts or jobs fails, the quota increment rolls back.
4) Prevents double increment by removing the previous record() call for that monthly plan approval.

What already exists in the source (NOT newly implemented):
- Monthly plan generation uses BusinessHub confirmed website facts, trip booking signals, published articles, reviews, and previous performance data.
- SocialPackages supports account and posting limits and publishing target selection.
- The queue schedules approved posts and downstream performance checks.

NOT finished in this patch:
- Atomic quota handling in manual post creation and team approval paths (still assertPublish + record in SocialMediaController).
- A global transactional quota reservation spanning ALL entry points.
- Exact accounting policy for month boundaries, refunds on cancellation, partial execution and addons.
- Full tests using live database, OAuth, queue worker and permissions.
- SEO keyword-specific cross-module coordination and fully autonomous campaigns.

INSTALL CAREFULLY:
- This patch changes app/Services/SocialMedia/SocialPackages.php and SocialMonthlyPlanService.php.
- Base is tourfecto.pro (33).zip, not a fresh export from the live server. Compare/merge with your live copies before replacing.
- Backup first and run PHP lint. No SQL migration needed if social_usage_monthly exists.
- Do not publish as a test without reviewing exact target accounts and schedule.

VERIFICATION:
- With a disposable test account and known plan limit, try approvals with remaining quota 0 and 1.
- Confirm rejected attempts do not create targets or jobs.
- Confirm successful approval increments usage only once.
- Confirm failed transaction rolls back quota.
- Run two approvals concurrently with one slot remaining: exactly one should succeed.
