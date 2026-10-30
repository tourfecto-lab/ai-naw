# AI Voice & Sales Agent: deployment checklist

Everything ships OFF. Nothing here buys numbers, places calls or charges wallets until the flags below are set.

## 1. Migrations (apply by hand, in this order)
`2026_10_31_000006` … `2026_10_31_000014` (`database/migrations/`). 006-011 were edited in place before release: a database that already ran an
earlier copy of them needs the new columns added manually (see `php tools/migration_report.php`).

## 2. Cron (not in the central scheduler: add on the server)
| Entry | Suggested schedule | What it does |
|---|---|---|
| `cron/voice_outbound.php` | every minute | dials queued outbound jobs (compliance re-checked at dial time) |
| `cron/voice_postcall.php` | every 2 minutes | CRM lead, booking, WhatsApp/email follow-up after calls |
| `cron/voice_followups.php` | hourly | abandoned-booking recovery, trip reminders |
| `cron/voice_supervisor.php` | hourly | AI call supervisor (capped per company per day) |
| `cron/voice_billing.php` | daily | number rental accrual, transcript/recording retention purge, monthly invoices |

## 3. Platform settings (all default to off/empty)
`voice_live_operations_enabled` (0), `voice_allow_own_provider_accounts` (0), `voice_billing_enabled` (0), `voice_marketing_countries` (empty),
`voice_recording_countries` (empty; only after legal review), `voice_rates_json` (empty; per-minute cost estimates), `voice_platform_max_concurrent`,
`voice_stream_url` + `voice_bridge_secret` (only if the realtime bridge is run; it uses a paid model).
Provider keys: `TWILIO_VOICE_*`, `TELNYX_*` (see `.env.example`).
