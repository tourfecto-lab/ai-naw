-- ============================================================
-- Tourfecto - Migration: توسيع enum api_type في جدول api_usage_logs
-- ليشمل موفري الذكاء الاصطناعي الجدد (Phase 2: AI Provider Layer)
--
-- هذا التعديل إضافي بالكامل (Additive):
-- - لا يحذف أي عمود أو جدول
-- - لا يحذف أي بيانات موجودة
-- - القيم القديمة (gemini, whatsapp, tripadvisor, google, stripe, paypal, other)
--   تفضل شغالة زي ما هي بالظبط
-- - بيضيف بس 3 قيم جديدة: openai, deepseek, kimi
--
-- السبب: الـProviders الجداد (OpenAIProvider/DeepSeekProvider/KimiProvider) في
-- app/Services/AI/Providers/ بيسجّلوا استخدامهم في نفس الجدول ده (زي GeminiClient
-- بالظبط) - محتاجين enum يقبل القيم دي، وإلا الـINSERT هيفشل بصمت أو يتحول لقيمة
-- افتراضية غلط حسب إعدادات MySQL sql_mode.
-- ============================================================

ALTER TABLE `api_usage_logs`
    MODIFY COLUMN `api_type` ENUM(
        'gemini', 'openai', 'deepseek', 'kimi',
        'whatsapp', 'tripadvisor', 'google', 'stripe', 'paypal', 'other'
    ) NOT NULL COMMENT 'نوع الـ API - تم توسيعه في Phase 2 ليشمل موفري AI الجدد';
