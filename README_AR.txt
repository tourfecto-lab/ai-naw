Tourfecto WhatsApp webhook routing diagnosis — 2026-10-28

Contains only app/Controllers/WhatsAppCloudController.php, based on the latest single PHP file uploaded by the user.
Back up the existing file, then upload app/Controllers/WhatsAppCloudController.php to the same relative path.
No SQL migrations. No Meta configuration edits. No credentials included.

After deploying, a new real WhatsApp event must arrive before the routing summary is populated.
Use Check incoming messages, then inspect the authenticated diagnose response:
checks.last_routing_summary: at_utc, matched, messages_queued, statuses_queued, unknown, skipped
checks.this_number_last_event_at and checks.inbox_queue

matched = 0, unknown > 0: event phone_number_id is not matched by an active connection (Meta test events may be synthetic).
matched > 0, messages_queued = 0: no new valid incoming message was enqueued (it may have been a status or duplicate).
messages_queued > 0: event entered durable queue; check inbox_queue for queued/failed jobs and worker.
No new summary after new event: webhook may not have reached this controller, invalid signature, or write failure.

IMPORTANT: This patch provides safe and persistent evidence; it does not guarantee repair of missing real incoming delivery.
