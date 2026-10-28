-- ============================================================
-- Tourfecto Business Control Center - Migration: DB quality pass
-- Phase 20: Database Quality
-- @version 1.0.0  @date 2026-08-15
--
-- إضافية بالكامل: ADD CONSTRAINT / ADD INDEX فقط. لا DROP ولا تعديل
-- على أي عمود موجود. الهدف: سد فجوات التكامل المرجعي المتبقية في
-- جداول الموديول مقارنةً بمعايير بقية المشروع (اللي بيربط user_id
-- بـusers بـON DELETE CASCADE في أغلب الجداول).
--
-- الفجوات اللي اتمسدت:
--   1. business_members.user_id كان ليها index بس مفيش FK - حذف حساب
--      عضو كان بيسبّب صف عضو "يتيم" غير قابل للربط.
--   2. business_members.invited_by_user_id - مفيش index ولا FK.
--   3. business_members.invited_email - مفيش index مع إن lookup
--      الدعوات المعلقة بيعتمد عليها.
--   4. business_api_keys.created_by_user_id - مفيش index ولا FK.
--
-- اختيارات ON DELETE:
--   - business_members.user_id -> CASCADE: حذف الحساب بيحذف عضويته
--     (نفس معيار بقية المشروع؛ العضوية بلا مستخدم مالهاش معنى).
--   - invited_by_user_id -> SET NULL: حذف اللي دعا ميمسحش الدعوة
--     نفسها، بس بيخلي المرجع NULL (الدعوة لسه شغالة للبريد المستهدف).
--   - business_api_keys.created_by_user_id -> SET NULL: المفتاح بيتبع
--     الـBusiness مش المنشئ؛ حذف حساب اللي أنشأه ميمسحش المفتاح (لو
--     اتحوّلت ملكية الـBusiness لمستخدم تاني الأول، المفاتيح لازم تفضل
--     شغالة). المرجع بس بيتنضّف. العمود اتعمل nullable في جدول
--     business_api_keys نفسه (هجرة 000056).
-- ============================================================

-- 1) business_members.user_id -> users ON DELETE CASCADE
ALTER TABLE `business_members`
    ADD CONSTRAINT `fk_member_user`
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

-- 2) business_members.invited_by_user_id -> users ON DELETE SET NULL
ALTER TABLE `business_members`
    ADD INDEX `idx_member_invited_by` (`invited_by_user_id`),
    ADD CONSTRAINT `fk_member_invited_by`
    FOREIGN KEY (`invited_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

-- 3) business_members.invited_email -> index لدعم lookup الدعوات المعلقة
ALTER TABLE `business_members`
    ADD INDEX `idx_member_invited_email` (`invited_email`);

-- 4) business_api_keys.created_by_user_id -> users ON DELETE SET NULL
ALTER TABLE `business_api_keys`
    ADD INDEX `idx_business_api_key_creator` (`created_by_user_id`),
    ADD CONSTRAINT `fk_business_api_key_creator`
    FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;
