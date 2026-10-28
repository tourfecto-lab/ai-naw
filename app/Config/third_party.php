<?php

/**
 * Tourfecto - Third-Party Integrations Config
 * @version 1.0.0
 *
 * ثوابت خدمات الطرف الثالث (بحث/تحليلات/تواصل/CRM) - كلها بتقرأ من .env
 * عن طريق env() (اللي بيقرا $_ENV ثم $_SERVER ثم getenv). الكلاسات
 * المسؤولة عن المنطق الفعلي في app/Services/Integrations/.
 */

// ============================================
// Algolia Search
// ============================================
define('ALGOLIA_ENABLED', filter_var(env('ALGOLIA_ENABLED') ?: false, FILTER_VALIDATE_BOOLEAN));
define('ALGOLIA_APP_ID', env('ALGOLIA_APP_ID') ?: '');
define('ALGOLIA_SEARCH_API_KEY', env('ALGOLIA_SEARCH_API_KEY') ?: '');
define('ALGOLIA_WRITE_API_KEY', env('ALGOLIA_WRITE_API_KEY') ?: '');

// ============================================
// Hotjar / Contentsquare
// ============================================
define('CONTENTSQUARE_ENABLED', filter_var(env('CONTENTSQUARE_ENABLED') ?: false, FILTER_VALIDATE_BOOLEAN));
define('CONTENTSQUARE_TAG_ID', env('CONTENTSQUARE_TAG_ID') ?: '');

// ============================================
// Mixpanel
// ============================================
define('MIXPANEL_ENABLED', filter_var(env('MIXPANEL_ENABLED') ?: false, FILTER_VALIDATE_BOOLEAN));
define('MIXPANEL_TOKEN', env('MIXPANEL_TOKEN') ?: '');

// ============================================
// Slack
// ============================================
define('SLACK_ENABLED', filter_var(env('SLACK_ENABLED') ?: false, FILTER_VALIDATE_BOOLEAN));
define('SLACK_BOT_TOKEN', env('SLACK_BOT_TOKEN') ?: '');
define('SLACK_DEFAULT_CHANNEL', env('SLACK_DEFAULT_CHANNEL') ?: '#general');

// ============================================
// Calendly
// ============================================
define('CALENDLY_ENABLED', filter_var(env('CALENDLY_ENABLED') ?: false, FILTER_VALIDATE_BOOLEAN));
define('CALENDLY_API_TOKEN', env('CALENDLY_API_TOKEN') ?: '');

// ============================================
// Zoom (Server-to-Server OAuth)
// ============================================
define('ZOOM_ENABLED', filter_var(env('ZOOM_ENABLED') ?: false, FILTER_VALIDATE_BOOLEAN));
define('ZOOM_ACCOUNT_ID', env('ZOOM_ACCOUNT_ID') ?: '');
define('ZOOM_CLIENT_ID', env('ZOOM_CLIENT_ID') ?: '');
define('ZOOM_CLIENT_SECRET', env('ZOOM_CLIENT_SECRET') ?: '');

// ============================================
// Zapier
// ============================================
define('ZAPIER_ENABLED', filter_var(env('ZAPIER_ENABLED') ?: false, FILTER_VALIDATE_BOOLEAN));
define('ZAPIER_WEBHOOK_URL', env('ZAPIER_WEBHOOK_URL') ?: '');

// ============================================
// HubSpot
// ============================================
define('HUBSPOT_ENABLED', filter_var(env('HUBSPOT_ENABLED') ?: false, FILTER_VALIDATE_BOOLEAN));
define('HUBSPOT_API_KEY', env('HUBSPOT_API_KEY') ?: '');

// ============================================
// OneSignal
// ============================================
define('ONESIGNAL_ENABLED', filter_var(env('ONESIGNAL_ENABLED') ?: false, FILTER_VALIDATE_BOOLEAN));
define('ONESIGNAL_APP_ID', env('ONESIGNAL_APP_ID') ?: '');
define('ONESIGNAL_REST_API_KEY', env('ONESIGNAL_REST_API_KEY') ?: '');
