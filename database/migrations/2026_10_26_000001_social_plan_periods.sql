-- Tourfecto - الخطة: شهرية أو نص شهرية (15 يوم) من أي يوم، أو دايمة (بتتجدد لوحدها قبل نهاية الفترة بـ 5 أيام).
-- آمن للتشغيل أكتر من مرة، وكله أعمدة جديدة بقيم افتراضية (الخطط القديمة = شهرية من غير تجديد).
SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'social_monthly_plans' AND COLUMN_NAME = 'renewed_plan_id');
SET @q := IF(@has = 0, 'ALTER TABLE `social_monthly_plans`
    ADD COLUMN `period_type` VARCHAR(12) NOT NULL DEFAULT ''month'' COMMENT ''month|half'',
    ADD COLUMN `starts_on` DATE NULL, ADD COLUMN `ends_on` DATE NULL,
    ADD COLUMN `renew` TINYINT(1) NOT NULL DEFAULT 0 COMMENT ''1 = خطة دايمة'',
    ADD COLUMN `auto_approve` TINYINT(1) NOT NULL DEFAULT 0 COMMENT ''اعتماد الدورات الجاية تلقائيًا'',
    ADD COLUMN `parent_plan_id` BIGINT UNSIGNED NULL, ADD COLUMN `renewed_plan_id` BIGINT UNSIGNED NULL,
    ADD COLUMN `renew_error` VARCHAR(300) NULL, ADD COLUMN `renew_checked_at` DATETIME NULL,
    ADD KEY `ix_social_monthly_renew` (`renew`, `renewed_plan_id`, `ends_on`)', 'SELECT 1');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
UPDATE `social_monthly_plans` SET `starts_on` = CONCAT(`month_key`, '-01'), `ends_on` = LAST_DAY(CONCAT(`month_key`, '-01')) WHERE `starts_on` IS NULL;
