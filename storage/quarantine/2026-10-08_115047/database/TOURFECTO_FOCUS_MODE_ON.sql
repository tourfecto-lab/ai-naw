-- ============================================================================
-- Tourfecto - وضع التركيز (Focus Mode): بيخبّي من قايمة العميل الميزات اللي مش في المسار الأساسي.
-- مهم تعرفه:
--  * بيخبّي من القايمة الجانبية بس. الصفحات والـ API بتفضل شغّالة لو حد فتح الرابط مباشرة، ومفيش بيانات بتتحذف.
--  * أي عميل عنده بيانات فعلية في ميزة بتتخبّى (حملة إعلانات، منشور، إيرادات، GBP) بياخد تجاوز شخصي فتفضل ظاهرة له.
--  * بيرجع بالكامل بملف TOURFECTO_FOCUS_MODE_OFF.sql.  آمن يتشغّل أكتر من مرة.
-- ملاحظة فنية: القايمة بتطابق مفتاح العنصر مع feature_flags.feature_key بالحرف، فمفاتيح مش موجودة
-- (زي ai_chat_inbox) بنضيفها هنا. فلاج "chat" القديم ما كانش بيخبّي حاجة من القايمة.
-- ============================================================================

-- 1) أعلام الميزات المخفية (بتتضاف لو مش موجودة، وبتتقفل لو موجودة)
INSERT INTO feature_flags (feature_key, label, is_enabled) VALUES
  ('ads', 'الإعلانات', 0),
  ('email_marketing', 'التسويق بالبريد', 0),
  ('social', 'السوشيال ميديا', 0),
  ('marketing_assistant', 'مساعد التسويق الذكي', 0),
  ('creative_studio', 'Creative Studio', 0),
  ('crm', 'إدارة العملاء (CRM)', 0),
  ('ai_chat_inbox', 'صندوق الوارد (الشات)', 0),
  ('ai_chat_leads', 'عملاء الشات المحتملين', 0),
  ('ai_chat_followup', 'متابعة الشات', 0),
  ('ai_chat_knowledge', 'قاعدة معرفة الشات', 0),
  ('ai_chat_learning', 'تعلّم الشات', 0),
  ('ai_chat_analytics', 'تحليلات الشات', 0),
  ('business_center', 'مركز الأعمال', 0),
  ('revenue', 'الإيرادات', 0),
  ('revenue_intelligence', 'ذكاء الإيرادات', 0),
  ('reputation_intelligence', 'ذكاء السمعة', 0),
  ('agency', 'الوكالات (White Label)', 0)
ON DUPLICATE KEY UPDATE is_enabled = VALUES(is_enabled);

-- 2) الميزات الأساسية: نتأكد إنها مفتوحة
UPDATE feature_flags SET is_enabled = 1
 WHERE feature_key IN ('website_optimizer', 'website_builder', 'ai_assistant', 'ai_articles', 'competitor_monitoring', 'reputation');

-- 3) حماية العملاء الحاليين: اللي عنده بيانات في ميزة بتتخبّى بتفضل ظاهرة له
--    (INSERT IGNORE بيسيب أي تجاوز موجود زي ما هو. الملاحظة 'focus-mode:' بتتستخدم للتراجع.)
INSERT IGNORE INTO user_feature_overrides (user_id, feature_key, is_enabled, note)
  SELECT DISTINCT user_id, 'ads', 1, 'focus-mode: لديه حملات إعلانية' FROM ad_campaigns WHERE user_id IS NOT NULL;
INSERT IGNORE INTO user_feature_overrides (user_id, feature_key, is_enabled, note)
  SELECT DISTINCT user_id, 'social', 1, 'focus-mode: لديه منشورات' FROM social_posts WHERE user_id IS NOT NULL;
INSERT IGNORE INTO user_feature_overrides (user_id, feature_key, is_enabled, note)
  SELECT DISTINCT user_id, 'revenue', 1, 'focus-mode: لديه سجلات إيرادات' FROM rev_revenue_records WHERE user_id IS NOT NULL;
INSERT IGNORE INTO user_feature_overrides (user_id, feature_key, is_enabled, note)
  SELECT DISTINCT user_id, 'revenue_intelligence', 1, 'focus-mode: لديه سجلات إيرادات' FROM rev_revenue_records WHERE user_id IS NOT NULL;
INSERT IGNORE INTO user_feature_overrides (user_id, feature_key, is_enabled, note)
  SELECT DISTINCT user_id, 'gbp_content', 1, 'focus-mode: لديه محتوى GBP' FROM gbp_content WHERE user_id IS NOT NULL;
