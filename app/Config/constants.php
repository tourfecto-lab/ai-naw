<?php

/**
 * Tourfecto - الثوابت العامة
 * @version 1.0.0
 * @author Tourfecto Team
 * @copyright 2026 Tourfecto
 */

// ============================================
// ثوابت الأدوار والصلاحيات
// ============================================
define('ROLES', [
    'super_admin' => 1,
    'admin' => 2,
    'manager' => 3,
    'agent' => 4,
    'user' => 5
]);

define('PERMISSIONS', [
    'view_dashboard' => 'view_dashboard',
    'manage_users' => 'manage_users',
    'manage_subscriptions' => 'manage_subscriptions',
    'manage_websites' => 'manage_websites',
    'manage_ai_analysis' => 'manage_ai_analysis',
    'manage_reviews' => 'manage_reviews',
    'manage_chat' => 'manage_chat',
    'manage_settings' => 'manage_settings',
    'view_reports' => 'view_reports',
    'export_data' => 'export_data',
]);

// ============================================
// ثوابت خطط الاشتراك
// ============================================
define('SUBSCRIPTION_PLANS', [
    'starter' => [
        'id' => 'starter',
        'name' => 'الباقة الأساسية',
        'price_monthly' => 49.00,
        'price_yearly' => 490.00,
        'features' => [
            'ai_analysis' => 50,
            'competitor_analysis' => 5,
            'chat_credits' => 100,
            'review_credits' => 10,
            'whatsapp_bot' => true,
            'auto_pilot' => false,
            'multiple_websites' => 1,
            'advanced_analytics' => false
        ]
    ],
    'professional' => [
        'id' => 'professional',
        'name' => 'الباقة الاحترافية',
        'price_monthly' => 99.00,
        'price_yearly' => 990.00,
        'features' => [
            'ai_analysis' => 200,
            'competitor_analysis' => 20,
            'chat_credits' => 500,
            'review_credits' => 50,
            'whatsapp_bot' => true,
            'auto_pilot' => true,
            'multiple_websites' => 3,
            'advanced_analytics' => false
        ]
    ],
    'enterprise' => [
        'id' => 'enterprise',
        'name' => 'الباقة المؤسسية',
        'price_monthly' => 299.00,
        'price_yearly' => 2990.00,
        'features' => [
            'ai_analysis' => 1000,
            'competitor_analysis' => 100,
            'chat_credits' => 2000,
            'review_credits' => 200,
            'whatsapp_bot' => true,
            'auto_pilot' => true,
            'multiple_websites' => 10,
            'advanced_analytics' => true
        ]
    ]
]);

// ============================================
// ثوابت منصات التقييم
// ============================================
define('REVIEW_PLATFORMS', [
    'tripadvisor' => [
        'name' => 'TripAdvisor',
        'icon' => 'tripadvisor',
        'color' => '#34E0A1',
        'api_endpoint' => 'https://api.tripadvisor.com/v1'
    ],
    'google_business' => [
        'name' => 'Google Business',
        'icon' => 'google',
        'color' => '#4285F4',
        'api_endpoint' => 'https://mybusiness.googleapis.com/v4'
    ],
    'booking' => [
        'name' => 'Booking.com',
        'icon' => 'booking',
        'color' => '#003580',
        'api_endpoint' => 'https://api.booking.com/v1'
    ],
    'expedia' => [
        'name' => 'Expedia',
        'icon' => 'expedia',
        'color' => '#003366',
        'api_endpoint' => 'https://api.expedia.com/v1'
    ],
    'trustpilot' => [
        'name' => 'Trustpilot',
        'icon' => 'trustpilot',
        'color' => '#00B67A',
        'api_endpoint' => 'https://api.trustpilot.com/v1'
    ]
]);

// ============================================
// ثوابت منصات المحادثة
// ============================================
define('CHAT_PLATFORMS', [
    'whatsapp' => [
        'name' => 'WhatsApp',
        'icon' => 'whatsapp',
        'color' => '#25D366',
        'enabled' => true
    ],
    'telegram' => [
        'name' => 'Telegram',
        'icon' => 'telegram',
        'color' => '#0088CC',
        'enabled' => false
    ],
    'messenger' => [
        'name' => 'Facebook Messenger',
        'icon' => 'messenger',
        'color' => '#00B2FF',
        'enabled' => true
    ],
    'instagram' => [
        'name' => 'Instagram',
        'icon' => 'instagram',
        'color' => '#E1306C',
        'enabled' => true
    ],
    'email' => [
        'name' => 'Email',
        'icon' => 'email',
        'color' => '#6B7280',
        'enabled' => true
    ],
    'webchat' => [
        'name' => 'Web Chat',
        'icon' => 'chat',
        'color' => '#0077be',
        'enabled' => true
    ]
]);

// ============================================
// ثوابت الحالات العامة
// ============================================
define('STATUS_CODES', [
    'success' => 200,
    'created' => 201,
    'accepted' => 202,
    'no_content' => 204,
    'bad_request' => 400,
    'unauthorized' => 401,
    'forbidden' => 403,
    'not_found' => 404,
    'method_not_allowed' => 405,
    'too_many_requests' => 429,
    'server_error' => 500,
    'service_unavailable' => 503
]);

define('STATUS_MESSAGES', [
    'success' => 'تمت العملية بنجاح',
    'created' => 'تم الإنشاء بنجاح',
    'accepted' => 'تم قبول الطلب',
    'bad_request' => 'طلب غير صحيح',
    'unauthorized' => 'غير مصرح به',
    'forbidden' => 'ممنوع الوصول',
    'not_found' => 'غير موجود',
    'server_error' => 'خطأ في الخادم'
]);

// ============================================
// ثوابت أنواع التقارير
// ============================================
define('REPORT_TYPES', [
    'seo' => 'تحليل SEO',
    'aeo' => 'تحليل AEO',
    'geo' => 'تحليل GEO',
    'full' => 'تحليل شامل',
    'competitor' => 'تحليل المنافسين',
    'sentiment' => 'تحليل المشاعر',
    'performance' => 'تحليل الأداء'
]);

// ============================================
// ثوابت تنسيقات الملفات
// ============================================
define('EXPORT_FORMATS', [
    'pdf' => 'PDF',
    'csv' => 'CSV',
    'excel' => 'Excel',
    'json' => 'JSON',
    'xml' => 'XML'
]);

// ============================================
// ثوابت العملات
// ============================================
define('CURRENCIES', [
    'USD' => ['code' => 'USD', 'symbol' => '$', 'name' => 'US Dollar'],
    'EUR' => ['code' => 'EUR', 'symbol' => '€', 'name' => 'Euro'],
    'GBP' => ['code' => 'GBP', 'symbol' => '£', 'name' => 'British Pound'],
    'EGP' => ['code' => 'EGP', 'symbol' => 'E£', 'name' => 'Egyptian Pound'],
    'SAR' => ['code' => 'SAR', 'symbol' => '﷼', 'name' => 'Saudi Riyal'],
    'AED' => ['code' => 'AED', 'symbol' => 'د.إ', 'name' => 'UAE Dirham'],
    'KWD' => ['code' => 'KWD', 'symbol' => 'د.ك', 'name' => 'Kuwaiti Dinar'],
    'BHD' => ['code' => 'BHD', 'symbol' => 'د.ب', 'name' => 'Bahraini Dinar']
]);

// ============================================
// ثوابت الألوان للواجهات
// ============================================
define('COLOR_PALETTE', [
    'primary' => '#0077be',
    'secondary' => '#6c757d',
    'success' => '#28a745',
    'danger' => '#dc3545',
    'warning' => '#ffc107',
    'info' => '#17a2b8',
    'light' => '#f8f9fa',
    'dark' => '#343a40',
    'gold' => '#FFD700',
    'tripadvisor' => '#34E0A1',
    'google' => '#4285F4',
    'whatsapp' => '#25D366'
]);

// ============================================
// ثوابت حدود النظام
// ============================================
define('SYSTEM_LIMITS', [
    'max_competitors' => 5,
    'max_websites' => 10,
    'max_users' => 100,
    'max_reviews_per_day' => 1000,
    'max_chat_messages_per_day' => 10000,
    'max_ai_requests_per_day' => 500,
    'max_file_size' => 20 * 1024 * 1024, // 20MB
    'max_execution_time' => 300, // 5 دقائق
    'memory_limit' => 256 * 1024 * 1024 // 256MB
]);

// ============================================
// ثوابت إعدادات البريد
// ============================================
define('EMAIL_TEMPLATES', [
    'welcome' => 'emails/welcome',
    'subscription_confirm' => 'emails/subscription_confirm',
    'subscription_expired' => 'emails/subscription_expired',
    'report_ready' => 'emails/report_ready',
    'review_response' => 'emails/review_response',
    'chat_approval' => 'emails/chat_approval'
]);

// ============================================
// AI Chat Platform - Rate Limiting (بند 22)
// ============================================
define('AI_CHAT_RATE_LIMIT_MAX', (int) (env('AI_CHAT_RATE_LIMIT_MAX') ?: 20));
define('AI_CHAT_RATE_LIMIT_WINDOW_SECONDS', (int) (env('AI_CHAT_RATE_LIMIT_WINDOW_SECONDS') ?: 60));

// ============================================
// ثوابت الـ Queues
// ============================================
define('QUEUE_NAMES', [
    'default' => 'default',
    'emails' => 'emails',
    'reports' => 'reports',
    'ai_analysis' => 'ai_analysis',
    'webhooks' => 'webhooks',
    'notifications' => 'notifications'
]);
