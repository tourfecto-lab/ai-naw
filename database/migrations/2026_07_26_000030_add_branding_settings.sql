-- ============================================================
-- Tourfecto - Migration: إعدادات هوية الموقع (اسم، لوجو، رقم، تواصل)
-- بتستخدم نفس جدول system_settings الموجود بالفعل - إضافة تصنيف جديد
-- 'branding' بس، مفيش جدول جديد.
-- @version 1.0.0  @date 2026-07-26
-- ============================================================

INSERT INTO `system_settings` (`setting_key`, `setting_value`, `is_secret`, `category`) VALUES
    ('site_name', 'Tourfecto', 0, 'branding'),
    ('site_logo_url', '', 0, 'branding'),
    ('site_logo_height', '32', 0, 'branding'),
    ('site_favicon_url', '', 0, 'branding'),
    ('contact_phone', '', 0, 'branding'),
    ('contact_email', '', 0, 'branding'),
    ('site_address', '', 0, 'branding')
ON DUPLICATE KEY UPDATE `setting_key` = `setting_key`;
