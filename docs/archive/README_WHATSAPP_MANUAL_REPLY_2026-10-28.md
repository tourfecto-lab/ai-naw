# Tourfecto WhatsApp manual reply + queue clock fix (2026-10-28)

Files reflect the exact user-supplied ChatInboxController.php, ChatManager.php, WhatsAppCloudAPI.php, and QueueManager.php in this chat. Install only after backing up all four installed files and comparing with any newer live edits. Paths in this ZIP reflect expected class locations; locate by filename if the application uses different autoload locations.

Changes:
- Preserve a verified BSUID as the Meta `to` value for text replies, instead of phone normalization removing it. Conventional E.164 phone recipients still normalize as before.
- Preserve website ownership and recent inbound (24-hour) guard in ChatManager; use DB NOW() for comparisons and log a limited Meta HTTP/error code without tokens or message text.
- QueueManager: insert available_at based on database clock (NOW()), matching processDue's NOW() filter and avoiding timezone mismatch. Existing queued jobs are unchanged.
- Does NOT silently retransmit any earlier failed outbound messages. Does NOT alter Meta configuration or the database schema.

Limits: Incoming webhook->CRM details, the worker cron script/schedule, browser polling (panel.js), and exact live Meta rejection reason were not supplied. These cannot be certified by these four files. For near-real-time delivery, verify a worker runs frequently and correctly (cron every minute can add up to ~60s), then inspect the web page's pulse/poll interval. Do not create concurrent uncoordinated workers.

Safety check: run `php -l` on all four files before deployment; test ONE NEW reply to an already inbound conversation within 24h and inspect logs if rejected. Never retry an uncertain outgoing send blindly.
"# ai-naw" 
"# ai-naw" 
"# ai-naw" 
"# ai-naw" 
