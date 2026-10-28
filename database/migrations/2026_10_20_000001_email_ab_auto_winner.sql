-- Tourfecto - A/B Auto-Winner (اختبار على جزء ثم إرسال الفائز للباقي)
-- holdout_percent = نسبة الجمهور اللي بتتحجز (0 = السلوك القديم: كل الجمهور يتقسم بين أ وب)
-- كل ALTER في جملة مستقلة (نمط المشروع: تجاهل Duplicate column عند إعادة التشغيل).
SET NAMES utf8mb4;
ALTER TABLE `email_ab_tests` ADD COLUMN `holdout_percent` TINYINT(3) UNSIGNED NOT NULL DEFAULT 0;
ALTER TABLE `email_ab_tests` ADD COLUMN `auto_winner` TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE `email_ab_tests` ADD COLUMN `test_hours` INT(11) NOT NULL DEFAULT 24 COMMENT 'أقل مدة انتظار بعد بدء الاختبار قبل اختيار الفائز';
ALTER TABLE `email_ab_tests` ADD COLUMN `started_at` DATETIME NULL DEFAULT NULL COMMENT 'UTC';
ALTER TABLE `email_ab_tests` ADD COLUMN `holdout_campaign_id` INT(11) NULL DEFAULT NULL;
ALTER TABLE `email_ab_tests` ADD COLUMN `holdout_sent_at` DATETIME NULL DEFAULT NULL;
