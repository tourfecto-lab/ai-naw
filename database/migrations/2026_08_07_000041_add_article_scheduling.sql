-- ============================================================
-- Tourfecto - Migration: جدولة نشر المقالات (Article Scheduling)
-- بيسمح للعميل يجهّز مقال ويحدد تاريخ/وقت نشر مستقبلي على موقعه،
-- بدل ما يضطر يرجع بنفسه يضغط "نشر الآن" وقتها. التنفيذ الفعلي
-- بيحصل عن طريق نفس نظام الـ Queue الموجود بالفعل (جدول jobs +
-- cron/process_queue.php) - مفيش نظام جديد، استغلال للبنية الجاهزة.
-- @version 1.0.0  @date 2026-08-07
-- ============================================================

ALTER TABLE `ai_articles`
    MODIFY COLUMN `status` ENUM('generating','completed','failed','scheduled','schedule_failed') NOT NULL DEFAULT 'generating';

ALTER TABLE `ai_articles`
    ADD COLUMN `scheduled_at` TIMESTAMP NULL DEFAULT NULL COMMENT 'وقت النشر المجدول (لو status=scheduled)' AFTER `error_message`,
    ADD COLUMN `scheduled_job_id` INT(11) DEFAULT NULL COMMENT 'معرف المهمة في جدول jobs - لازم عشان نقدر نلغي الجدولة لاحقًا' AFTER `scheduled_at`;

ALTER TABLE `ai_articles`
    ADD INDEX `idx_scheduled_at` (`scheduled_at`);
