-- Apply after the previous 99-file bundle and incremental billing patch.
-- MySQL/MariaDB; additive, repeatable. DDL commits implicitly: back up first.
SET @n=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='media_items' AND COLUMN_NAME='request_key');
SET @q=IF(@n=0,'ALTER TABLE media_items ADD COLUMN request_key VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NULL','SELECT 1'); PREPARE cs FROM @q; EXECUTE cs; DEALLOCATE PREPARE cs;
SET @n=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='media_items' AND COLUMN_NAME='generation_meta');
SET @q=IF(@n=0,'ALTER TABLE media_items ADD COLUMN generation_meta TEXT NULL','SELECT 1'); PREPARE cs FROM @q; EXECUTE cs; DEALLOCATE PREPARE cs;
SET @n=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='media_items' AND COLUMN_NAME='generation_stage');
SET @q=IF(@n=0,'ALTER TABLE media_items ADD COLUMN generation_stage VARCHAR(24) NULL','SELECT 1'); PREPARE cs FROM @q; EXECUTE cs; DEALLOCATE PREPARE cs;
SET @n=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='media_items' AND COLUMN_NAME='usage_charged');
SET @q=IF(@n=0,'ALTER TABLE media_items ADD COLUMN usage_charged TINYINT NOT NULL DEFAULT 0','SELECT 1'); PREPARE cs FROM @q; EXECUTE cs; DEALLOCATE PREPARE cs;
SET @n=(SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='media_items' AND INDEX_NAME='uq_media_request');
SET @q=IF(@n=0,'ALTER TABLE media_items ADD UNIQUE KEY uq_media_request(user_id,request_key)','SELECT 1'); PREPARE cs FROM @q; EXECUTE cs; DEALLOCATE PREPARE cs;
SET @n=(SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='media_items' AND INDEX_NAME='idx_media_library');
SET @q=IF(@n=0,'ALTER TABLE media_items ADD KEY idx_media_library(user_id,id)','SELECT 1'); PREPARE cs FROM @q; EXECUTE cs; DEALLOCATE PREPARE cs;
-- Mark historical completed assets as already settled; do not re-charge them.
UPDATE media_items SET usage_charged=1, generation_stage='completed' WHERE status='completed' AND generation_stage IS NULL;
UPDATE media_items SET generation_stage='failed' WHERE status='failed' AND generation_stage IS NULL;
UPDATE media_items SET generation_stage='queued' WHERE status='generating' AND generation_stage IS NULL;
