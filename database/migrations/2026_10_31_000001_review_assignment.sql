-- Reputation: assign a review to a team member with a reply deadline (additive, idempotent, MariaDB).
-- The code detects the missing columns and shows "migration pending" instead of failing, so it is safe to deploy first.
ALTER TABLE `reviews`
    ADD COLUMN IF NOT EXISTS `assigned_to` INT(11) NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `assigned_by` INT(11) NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `assigned_at` DATETIME NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `assignment_due_at` DATETIME NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `assignment_note` VARCHAR(500) NULL DEFAULT NULL;
ALTER TABLE `reviews` ADD INDEX IF NOT EXISTS `idx_reviews_assigned` (`assigned_to`, `assignment_due_at`);
