TOURFECTO — HISTORICAL SOCIAL READ PREVIEW (PARTIAL)

FILES TO UPLOAD UNDER YOUR SITE ROOT (preserving paths):
app/Controllers/SocialMediaController.php
app/routes/api.php

NEW READ-ONLY ENDPOINT:
GET /api/social/history?connection_id=YOUR_CONNECTION_ID&limit=30
Requires an authenticated session, connected Facebook/Instagram account belonging to this user, and rate limit.
Returns an initial batch of existing Meta posts with counts and dates. Does not post, delete, import, or write to DB.

WHAT THIS DOES NOT YET DO:
- Show imported posts in Posts & Calendar (requires UI and storage change)
- Paginate across old posts from years ago
- Merge history into Overview stats / engagement or scheduled publishing
- Synchronize histories in background
- Verify Meta token availability or the output on production

BACKUP both files before uploading. The provided archive is based on tourfecto.pro (32).zip, not a verified live server snapshot; DO NOT overwrite newer versions without comparing first.
The previously uploaded language and campaign PAUSED patch must stay installed; compare existing files before applying.
