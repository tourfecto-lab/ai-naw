-- Reputation: assign a review to a team member with a reply deadline (additive).
-- Plain ALTER so it runs on MySQL and MariaDB (production reported "MySQL"; ADD COLUMN IF NOT EXISTS is MariaDB-only).
-- Applied by hand on production on 2026-10-31. Running it again fails with "Duplicate column name", which is harmless.
-- The code detects the missing columns and shows "migration pending" instead of failing.
ALTER TABLE `reviews`
    ADD COLUMN `assigned_to` INT NULL DEFAULT NULL,
    ADD COLUMN `assigned_by` INT NULL DEFAULT NULL,
    ADD COLUMN `assigned_at` DATETIME NULL DEFAULT NULL,
    ADD COLUMN `assignment_due_at` DATETIME NULL DEFAULT NULL,
    ADD COLUMN `assignment_note` VARCHAR(500) NULL DEFAULT NULL,
    ADD INDEX `idx_reviews_assigned` (`assigned_to`, `assignment_due_at`);
