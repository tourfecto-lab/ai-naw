-- Replace the platform favicon only when a custom favicon setting already exists.
-- With no existing setting, the updated default files are used automatically.
UPDATE system_settings SET setting_value='/assets/icons/tourfecto-favicon.png?v=tourfecto-gold-t-20261012' WHERE setting_key='site_favicon_url';
