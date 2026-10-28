-- ============================================================================
-- Tourfecto - تسجيل استخدام Claude (Anthropic) في سجل استهلاك AI
-- المشكلة: api_usage_logs.api_type ENUM مفيهوش 'anthropic'. بدون الملف ده التسجيل بيروح تحت "other".
-- additive: بيضيف قيمة في آخر القائمة (القيم الحالية بترتيبها). آمن يتشغّل أكتر من مرة.
-- ============================================================================
ALTER TABLE `api_usage_logs` MODIFY COLUMN `api_type`
  ENUM('gemini','openai','deepseek','kimi','whatsapp','tripadvisor','google','stripe','paypal','other','anthropic') NOT NULL;
