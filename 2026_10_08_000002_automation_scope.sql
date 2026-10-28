-- ============================================================
-- مركز الأتمتة: اختيار المواقع/الحسابات المربوطة اللي كل خدمة
-- بتشتغل عليها. NULL = كل المربوط.
-- (لو العمود موجود بالفعل، repair_production.php بيتخطى الخطأ)
-- @date 2026-10-08
-- ============================================================
ALTER TABLE `automation_policies`
    ADD COLUMN `scope_websites` TEXT NULL COMMENT 'JSON [website_id,...] - NULL = كل المواقع المربوطة' AFTER `graduated_buckets`;
