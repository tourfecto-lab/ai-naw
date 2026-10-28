<?php

/**
 * Tourfecto - تكوين WhatsApp API
 * @version 1.0.0
 * @author Tourfecto Team
 * @copyright 2026 Tourfecto
 */

// ============================================
// إعدادات WhatsApp الأساسية
// ============================================
define('WHATSAPP_ENABLED', filter_var(env('WHATSAPP_ENABLED') ?: false, FILTER_VALIDATE_BOOLEAN));
define('WHATSAPP_API_TYPE', env('WHATSAPP_API_TYPE') ?: 'cloud'); // cloud, ultramsg, wati
define('WHATSAPP_API_VERSION', env('WHATSAPP_API_VERSION') ?: (env('META_API_VERSION') ?: 'v25.0')); // كان v18.0 (موقوف من Meta)

// ============================================
// إعدادات WhatsApp Cloud API
// ============================================
define('WHATSAPP_CLOUD_BASE_URL', 'https://graph.facebook.com/' . WHATSAPP_API_VERSION);
define('WHATSAPP_PHONE_ID', env('WHATSAPP_PHONE_ID') ?: '');
define('WHATSAPP_ACCESS_TOKEN', env('WHATSAPP_ACCESS_TOKEN') ?: '');
define('WHATSAPP_BUSINESS_ACCOUNT_ID', env('WHATSAPP_BUSINESS_ACCOUNT_ID') ?: '');
define('WHATSAPP_WEBHOOK_VERIFY_TOKEN', env('WHATSAPP_WEBHOOK_VERIFY_TOKEN') ?: '');

// ============================================
// إعدادات UltraMsg API
// ============================================
define('ULTRAMSG_ENABLED', filter_var(env('ULTRAMSG_ENABLED') ?: false, FILTER_VALIDATE_BOOLEAN));
define('ULTRAMSG_INSTANCE_ID', env('ULTRAMSG_INSTANCE_ID') ?: '');
define('ULTRAMSG_API_KEY', env('ULTRAMSG_API_KEY') ?: '');
define('ULTRAMSG_BASE_URL', 'https://api.ultramsg.com/' . ULTRAMSG_INSTANCE_ID);

// ============================================
// إعدادات Wati API
// ============================================
define('WATI_ENABLED', filter_var(env('WATI_ENABLED') ?: false, FILTER_VALIDATE_BOOLEAN));
define('WATI_API_KEY', env('WATI_API_KEY') ?: '');
define('WATI_BASE_URL', env('WATI_BASE_URL') ?: 'https://live-server.wati.io');

// ============================================
// إعدادات الرسائل
// ============================================
define('WHATSAPP_MESSAGE_TIMEOUT', 30);
define('WHATSAPP_MAX_MESSAGE_LENGTH', 4096);
define('WHATSAPP_MAX_RETRIES', 3);
define('WHATSAPP_RETRY_DELAY', 2); // ثانية

// ============================================
// إعدادات أنواع الرسائل المدعومة
// ============================================
define('WHATSAPP_SUPPORTED_MESSAGE_TYPES', [
    'text',
    'image',
    'video',
    'audio',
    'document',
    'location',
    'button',
    'template',
    'interactive'
]);

// ============================================
// إعدادات القوالب
// ============================================
define('WHATSAPP_TEMPLATES', [
    'welcome' => [
        'name' => 'welcome_message',
        'language' => 'ar',
        'components' => [
            'body' => 'مرحباً بك في Tourfecto! كيف يمكننا مساعدتك؟',
            'buttons' => [
                'استفسار عن رحلة',
                'حجز جديد',
                'تواصل مع خدمة العملاء'
            ]
        ]
    ],
    'booking_confirmation' => [
        'name' => 'booking_confirmation',
        'language' => 'ar',
        'components' => [
            'body' => 'تم تأكيد حجزك بنجاح! رقم الحجز: {booking_id}',
            'buttons' => [
                'عرض التفاصيل',
                'تواصل مع الدعم'
            ]
        ]
    ],
    'review_request' => [
        'name' => 'review_request',
        'language' => 'ar',
        'components' => [
            'body' => 'كيف كانت تجربتك معنا؟ نود سماع رأيك!',
            'buttons' => [
                'تقييم إيجابي',
                'تقييم محايد',
                'تقييم سلبي'
            ]
        ]
    ]
]);

// ============================================
// إعدادات الوسائط
// ============================================
define('WHATSAPP_MEDIA_MAX_SIZE', [
    'image' => 5 * 1024 * 1024, // 5MB
    'video' => 16 * 1024 * 1024, // 16MB
    'audio' => 16 * 1024 * 1024, // 16MB
    'document' => 100 * 1024 * 1024 // 100MB
]);

define('WHATSAPP_MEDIA_ALLOWED_TYPES', [
    'image' => ['jpeg', 'png', 'webp', 'gif'],
    'video' => ['mp4', 'mov', 'avi'],
    'audio' => ['mp3', 'wav', 'ogg'],
    'document' => ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'txt']
]);

// ============================================
// إعدادات الأمان
// ============================================
define('WHATSAPP_WEBHOOK_SECRET', env('WHATSAPP_WEBHOOK_SECRET') ?: '');
define('WHATSAPP_IP_WHITELIST', [
    '127.0.0.1',
    '::1',
    '34.0.0.0/8', // Meta IP ranges
    '35.0.0.0/8'
]);

// ============================================
// إعدادات التسجيل
// ============================================
define('WHATSAPP_LOG_ENABLED', true);
define('WHATSAPP_LOG_PATH', TOURFECTO_STORAGE . '/logs/whatsapp.log');
define('WHATSAPP_LOG_LEVEL', 'info'); // debug, info, warning, error
