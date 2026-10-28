-- ============================================================
-- Tourfecto - Migration: عداد محاولات الإرسال (Retry System)
-- Section 19: لازم نمنع infinite retries - العداد ده بيتزود مع كل
-- محاولة إرسال فعلية (تلقائية من الـ cron أو يدوية من الـ Retry button)،
-- وبيتفحص قبل أي Retry يدوي جديد.
-- @version 1.0.0  @date 2026-08-10
-- ============================================================

ALTER TABLE `review_requests`
    ADD COLUMN `attempts` INT(11) NOT NULL DEFAULT 0
        COMMENT 'عدد محاولات الإرسال الفعلية - يمنع Infinite Retry (الحد الأقصى مُطبَّق في كود الخدمة)'
        AFTER `error_message`;
