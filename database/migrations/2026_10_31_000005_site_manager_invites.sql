-- ============================================================
-- Tourfecto - Email invitations for per-site manager accounts
-- The owner can invite someone by email; the invited person opens a one-time link and chooses their own password,
-- so no password travels by email or chat. Only a SHA-256 hash of the link token is stored.
-- Hand-applied on the server; safe to run twice (MariaDB ADD COLUMN IF NOT EXISTS).
-- ============================================================

ALTER TABLE `site_manager_accounts`
  ADD COLUMN IF NOT EXISTS `email` VARCHAR(190) NULL DEFAULT NULL AFTER `display_name`,
  ADD COLUMN IF NOT EXISTS `invite_token_hash` CHAR(64) NULL DEFAULT NULL AFTER `password_hash`,
  ADD COLUMN IF NOT EXISTS `invite_expires` DATETIME NULL DEFAULT NULL AFTER `invite_token_hash`;

ALTER TABLE `site_manager_accounts`
  ADD INDEX IF NOT EXISTS `idx_site_manager_invite` (`invite_token_hash`);
