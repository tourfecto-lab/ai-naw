-- ============================================
-- Tourfecto - Migration: GBP Posts Edit/Cancel Support
-- إضافة عمود واحد بس عشان نقدر نلغي (Cancel) منشور مجدول قبل ما ينشر -
-- محتاجين نعرف إيه صف الـ Job المرتبط في جدول `jobs` عشان نمسحه/نوقفه
-- قبل ما الـ Cron يشغّله. من غير العمود ده مفيش طريقة آمنة تربط
-- GbpScheduledPost بصف الـ job المقابل له في جدول jobs.
-- @version 1.0.0
-- @date 2026-08-11 (GBP Module Upgrade - Round 6: Posts Edit/Delete)
-- ============================================

ALTER TABLE `gbp_scheduled_posts`
    ADD COLUMN `queue_job_id` INT(11) NULL DEFAULT NULL COMMENT 'يشير لـ jobs.id - لإلغاء المهمة المجدولة قبل تنفيذها' AFTER `platform_connection_id`;
