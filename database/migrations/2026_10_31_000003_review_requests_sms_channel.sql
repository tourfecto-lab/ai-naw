-- Reputation: allow the SMS channel on review requests.
-- The SMS option, the sender and the Twilio check already existed, but review_requests.channel only accepted
-- 'whatsapp' and 'email', so saving an SMS request failed ("Data truncated for column channel").
-- Widening an ENUM is additive: existing rows keep their values. Valid on MySQL and MariaDB. Apply by hand.
ALTER TABLE `review_requests` MODIFY COLUMN `channel` ENUM('whatsapp','email','sms') NOT NULL DEFAULT 'whatsapp';
