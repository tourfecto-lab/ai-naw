PHASE 4 — HOSTING UPDATE ONLY
Base: Phase 3, 8fa137e
Source commit: 3e849d6

Back up files and database first.
Extract this archive into the project root, preserving all relative paths.
Replace the matching files. This archive contains only Phase 4 changed/new
runtime files; no .env, vendor, uploads, tests, or complete repository.

Run database/migrations/2026_10_17_000003_content_quality.sql once using
phpMyAdmin or your normal migration process (outside a transaction).
Keep the existing scheduler and queue worker running.
Purge application/browser/CDN caches after upload.

WordPress customers: generate and download a fresh site-bound Tourfecto
Connect 3.6.0 plugin ZIP from the platform and replace the installed plugin.
This archive updates the platform's bundle source; it is NOT a WordPress
plugin installation ZIP. Keep saved settings and existing connections.
PHP connector customers: download a fresh tourfecto-connect.php from the
platform; optional local theme wrappers need site-owner configuration.

Full deployment details:
https://github.com/tourfecto-lab/ai-tour/blob/seo/phase-4/docs/seo/phase-4.md
