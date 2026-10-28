# Tourfecto — Fixes Applied

## Applied to this package

1. Added `database/repair_production.php`.
   - Runs the current migration set in filename order.
   - Tracks completed migrations in `schema_migrations`.
   - Treats already-existing tables/columns/indexes/constraints as idempotent warnings.
   - Verifies tables declared by migrations after the run.
   - Supports `--dry-run` and `--apply`.
2. Added production deployment checklist and repair instructions.
3. Removed publicly accessible diagnostic PHP endpoints from `public_html` and moved them to `maintenance/diagnostics`.
4. Hardened `public_html/.htaccess` against accidental exposure of environment, backup, SQL and diagnostic files.
5. Verified PHP syntax after the changes: **914 PHP files checked, 0 syntax errors**.

## Important limitation

The current execution environment does not have the PHP MySQL PDO driver (`could not find driver`), so the database migrations could not be executed against the supplied live database from this workspace. The repair runner is therefore included for execution on the actual hosting server, where the project's MySQL PDO extension is available.

No real `.env` file is included in the deployment archive to avoid shipping database/API credentials.
