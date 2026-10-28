-- Tourfecto - Creative Studio Pro (2026-10-21)
-- يكمّل جداول/أعمدة الاستوديو اللي الكود بيعتمد عليها ومكانتش موجودة في أي migration داخل المستودع
-- (حجز الرصيد، المجلدات، سلة المهملات، نسخة رابط النشر، تكلفة المزود) + فهارس للحارس والسجل الموحّد.
-- MySQL/MariaDB؛ آمن للتشغيل أكتر من مرة (بيضيف اللي ناقص بس). خد نسخة احتياطية قبل التشغيل.

SET @n=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='media_items' AND COLUMN_NAME='folder_id');
SET @q=IF(@n=0,'ALTER TABLE media_items ADD COLUMN folder_id INT NULL','SELECT 1'); PREPARE cs FROM @q; EXECUTE cs; DEALLOCATE PREPARE cs;
SET @n=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='media_items' AND COLUMN_NAME='is_favorite');
SET @q=IF(@n=0,'ALTER TABLE media_items ADD COLUMN is_favorite TINYINT(1) NOT NULL DEFAULT 0','SELECT 1'); PREPARE cs FROM @q; EXECUTE cs; DEALLOCATE PREPARE cs;
SET @n=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='media_items' AND COLUMN_NAME='deleted_at');
SET @q=IF(@n=0,'ALTER TABLE media_items ADD COLUMN deleted_at DATETIME NULL','SELECT 1'); PREPARE cs FROM @q; EXECUTE cs; DEALLOCATE PREPARE cs;
SET @n=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='media_items' AND COLUMN_NAME='provider_cost');
SET @q=IF(@n=0,'ALTER TABLE media_items ADD COLUMN provider_cost DECIMAL(10,4) NULL','SELECT 1'); PREPARE cs FROM @q; EXECUTE cs; DEALLOCATE PREPARE cs;
SET @n=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='media_items' AND COLUMN_NAME='publish_version');
SET @q=IF(@n=0,'ALTER TABLE media_items ADD COLUMN publish_version INT NOT NULL DEFAULT 0','SELECT 1'); PREPARE cs FROM @q; EXECUTE cs; DEALLOCATE PREPARE cs;

-- الحارس بيدور على الطلبات الجارية، والمكتبة بتفلتر بالحالة والسلة
SET @n=(SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='media_items' AND INDEX_NAME='idx_media_status_stage');
SET @q=IF(@n=0,'ALTER TABLE media_items ADD KEY idx_media_status_stage(status,generation_stage)','SELECT 1'); PREPARE cs FROM @q; EXECUTE cs; DEALLOCATE PREPARE cs;
SET @n=(SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='media_items' AND INDEX_NAME='idx_media_user_deleted');
SET @q=IF(@n=0,'ALTER TABLE media_items ADD KEY idx_media_user_deleted(user_id,deleted_at,id)','SELECT 1'); PREPARE cs FROM @q; EXECUTE cs; DEALLOCATE PREPARE cs;

CREATE TABLE IF NOT EXISTS creative_reservations (
    media_item_id INT NOT NULL,
    user_id INT NOT NULL,
    subscription_id INT NULL,
    period_start DATETIME NULL,
    feature_key VARCHAR(60) NOT NULL,
    source ENUM('plan','wallet') NOT NULL,
    amount DECIMAL(10,2) NOT NULL DEFAULT 0,
    status ENUM('held','captured','released') NOT NULL DEFAULT 'held',
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (media_item_id),
    KEY idx_creative_res_user (user_id,source,status),
    KEY idx_creative_res_sub (subscription_id,feature_key,source,status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS creative_folders (
    id INT NOT NULL AUTO_INCREMENT,
    user_id INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_creative_folders_user (user_id,name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- مشاهد "سكربت → فيديو" بتتسجل في video_scripts.scenes (TEXT)؛ نكبّرها لـ MEDIUMTEXT عشان الوصف الإنجليزي لكل مشهد
SET @t=(SELECT DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='video_scripts' AND COLUMN_NAME='scenes');
SET @q=IF(@t='text','ALTER TABLE video_scripts MODIFY COLUMN scenes MEDIUMTEXT NULL COMMENT ''JSON array من المشاهد''','SELECT 1'); PREPARE cs FROM @q; EXECUTE cs; DEALLOCATE PREPARE cs;
