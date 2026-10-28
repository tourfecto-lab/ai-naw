-- ============================================================
-- Tourfecto - Migration: إضافة أعمدة model و feature لجدول api_usage_logs
-- (Phase 4: Usage & Cost Tracking)
--
-- إضافي بالكامل (Additive):
-- - لا يحذف أي عمود أو جدول
-- - لا يحذف أي بيانات موجودة
-- - العمودين الجداد Nullable من غير Default إجباري، عشان أي INSERT قديم
--   (زي اللي جوه GeminiClient.php نفسه، اللي متعمّدين ميتلمسش) يفضل شغال
--   زي ما هو بالظبط من غير أي تعديل عليه.
--
-- السبب: عشان لوحة تكلفة الأدمن (Admin Cost Dashboard) تقدر تجاوب على
-- "العميل ده كلفني كام؟" و"أي Provider/Model أغلى؟" و"أي Feature بتستهلك
-- أكتر؟" - لازم نعرف مين استخدم إيه، مش بس قد إيه اتكلف بالجملة.
--
-- ملحوظة مهمة: عمود user_id في الجدول ده اتحول لـNullable بالفعل في
-- 2026_07_19_000019_add_user_profile_settings.sql (تعديل سابق موثّق لنفس
-- السبب بالظبط - عشان GeminiClient.php ميتسجّلش فيه user_id). التعديل ده
-- بيكمل نفس المبدأ.
-- ============================================================

ALTER TABLE `api_usage_logs`
    ADD COLUMN `model` VARCHAR(100) NULL DEFAULT NULL COMMENT 'اسم الموديل المحدد (gpt-4o-mini, deepseek-chat, gemini-flash-latest...)' AFTER `endpoint`,
    ADD COLUMN `feature` VARCHAR(100) NULL DEFAULT NULL COMMENT 'الميزة اللي سببت الطلب (seo_analysis, chat, review_reply...)' AFTER `model`,
    ADD INDEX `idx_model` (`model`),
    ADD INDEX `idx_feature` (`feature`);
