-- Tourfecto - chat_messages.platform: اضافة القيمة 'instagram' (رسائل انستجرام DM). اختياري: الكود بيحفظ انستجرام كـ 'other' لحد ما تتطبق.
-- آمن للتشغيل أكتر من مرة، وبيحافظ على كل القيم الموجودة في الـ ENUM على السيرفر (بيقرا التعريف الحالي ويضيف القيمة بس).
SET @tf_type = (SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'chat_messages' AND COLUMN_NAME = 'platform' LIMIT 1);
SET @tf_sql = IF(@tf_type IS NULL OR @tf_type NOT LIKE 'enum(%' OR @tf_type LIKE '%''instagram''%',
    'SELECT 1',
    CONCAT('ALTER TABLE `chat_messages` MODIFY COLUMN `platform` ', LEFT(@tf_type, CHAR_LENGTH(@tf_type) - 1), ',''instagram'') NOT NULL DEFAULT ''whatsapp'''));
PREPARE tf_stmt FROM @tf_sql;
EXECUTE tf_stmt;
DEALLOCATE PREPARE tf_stmt;
