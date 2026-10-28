-- ============================================================================
-- Tourfecto - إلغاء وضع التركيز: بيرجّع كل الميزات ظاهرة. آمن يتشغّل أكتر من مرة.
-- بيحذف بس التجاوزات اللي ملاحظتها بتبدأ بـ 'focus-mode:' (اللي ضافها ملف ON). باقي التجاوزات ما بتتمسّش.
-- ============================================================================
UPDATE feature_flags SET is_enabled = 1
 WHERE feature_key IN ('ads','email_marketing','social','marketing_assistant','creative_studio','crm',
   'ai_chat_inbox','ai_chat_leads','ai_chat_followup','ai_chat_knowledge','ai_chat_learning','ai_chat_analytics',
   'business_center','revenue','revenue_intelligence','reputation_intelligence','agency');
DELETE FROM user_feature_overrides WHERE note LIKE 'focus-mode:%';
