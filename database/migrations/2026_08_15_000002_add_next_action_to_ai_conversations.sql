-- ============================================
-- Tourfecto - AI Chat Platform
-- Migration إضافية (تحسين تنافسي): حفظ "الإجراء التالي الموصى به" الذي
-- يحدده AIConversationEngine (next_action) كعمود حقيقي على المحادثة،
-- بدل أن يكون نصًا مؤقتًا فقط.
--
-- لماذا؟ لزي الـIntercom Fin/Ada - الإجابة لا تكتفي بالرد، بل تخبر
-- الموظف بأفضل خطوة تالية (اسأل عن الوجهة/التواريخ/الميزانية، أرسل عرض
-- سعر، احجز...) وتوقيتها، فتصبح الـUnified Inbox دليل عمل حقيقي.
--
-- ملحوظة: ملف migration منفصل عمدًا حتى لا تُمس الملف الأصلي
-- 2026_08_08_000001_create_ai_chat_platform_tables.sql (تثبيت كسري آمن).
-- ============================================

ALTER TABLE `ai_conversations`
    ADD COLUMN `next_recommended_action` VARCHAR(50) DEFAULT NULL
        COMMENT 'آخر إجراء تالي موصى به من AIConversationEngine (next_action: ask_destination, ask_dates, ask_budget, send_quote, handoff_to_human...)'
        AFTER `ai_summary`;

ALTER TABLE `ai_conversations`
    ADD INDEX `idx_next_recommended_action` (`next_recommended_action`);
