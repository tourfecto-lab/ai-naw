TOURFECTO - META BUSINESS ASSETS (READ ONLY)

FILES: app/Controllers/AdsController.php, app/Services/Ads/MetaAdsAPI.php, app/routes/api.php

Built from source version tourfecto.pro (33).zip. CAUTION: this is NOT the current server snapshot. Before uploading, merge the new methods/route/UI with your current files rather than overwriting later unrelated edits.

New page component: /ads/connections -> Meta Business Assets -> Load business assets.
API: GET /api/ads/meta/business-assets, authenticated and verified via resolveAdsAccess('viewer'). Uses owner's encrypted Meta token, not a client-supplied token. No asset writes. Shows owned/client pages and ad accounts, and warns on per-edge permission failures.

NO SQL. NO PAID AD CREATION. NO DISCONNECT.

Testing: Load /ads/connections, click Load business assets; ensure the portfolio and authorized assets show. If permission denied or portfolios empty, do not claim business_management works; verify approved Meta scope and token rights.

IMPORTANT: A successful UI read is evidence for the review video, not a guarantee of Meta approval. Review the page UI before recording.
