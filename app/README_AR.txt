Tourfecto Meta Ads safety patch based on user-uploaded app (2).zip

Changes:
- Keep a partial Meta campaign PAUSED locally if Campaign was created but Ad Set/Ad failed.
- Explicit error includes Meta campaign ID and step; instruct manual reconciliation rather than re-publishing.
- Validate approved creative text before any external Meta write.
- Remove unreachable code in ad set failure logic.

Install:
1. BACK UP current app/Controllers/AdsController.php and app/Services/Ads/MetaAdsAPI.php.
2. From this package copy Controllers/AdsController.php to app/Controllers/AdsController.php and Services/Ads/MetaAdsAPI.php to app/Services/Ads/MetaAdsAPI.php, preserving paths.
3. This archive derives from the latest uploaded app (2).zip; compare if server changed after its export.
4. No SQL needed.
5. Do not test by repeated Publish. Confirm previous attempt and PAUSED status in Meta Ads Manager first.

Limits:
- NO live Meta API test was performed.
- No full cross-request database locking/idempotency key yet.
- No guarantee that campaign/ad set/ad will be created without actual Meta authorization/config.
- Does not enable/activate any ads.
