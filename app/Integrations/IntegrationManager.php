<?php

/**
 * Tourfecto - Integration Manager
 * إدارة تكاملات الخدمات الخارجية
 */
class IntegrationManager
{
    protected static $integrations = [
        'stripe' => [
            'name'        => 'Stripe',
            'category'    => 'payment',
            'description' => 'بوابة الدفع الإلكتروني واستقبال الاشتراكات.',
            'auth_type'   => 'api_key',
            'env_keys'    => ['STRIPE_API_KEY', 'STRIPE_PUBLIC_KEY', 'STRIPE_WEBHOOK_SECRET'],
            'fields'      => [
                ['name' => 'STRIPE_API_KEY', 'label' => 'Stripe Secret Key', 'type' => 'password'],
                ['name' => 'STRIPE_PUBLIC_KEY', 'label' => 'Stripe Publishable Key', 'type' => 'text'],
                ['name' => 'STRIPE_WEBHOOK_SECRET', 'label' => 'Stripe Webhook Secret', 'type' => 'password']
            ]
        ],
        'google_search_console' => [
            'name'        => 'Google Search Console',
            'category'    => 'seo',
            'description' => 'متابعة أداء وظهور الموقع والكلمات المفتاحية على بحث جوجل.',
            'auth_type'   => 'oauth',
            'env_keys'    => ['GOOGLE_CLIENT_ID', 'GOOGLE_CLIENT_SECRET'],
            'fields'      => [
                ['name' => 'GOOGLE_CLIENT_ID', 'label' => 'Google Client ID', 'type' => 'text'],
                ['name' => 'GOOGLE_CLIENT_SECRET', 'label' => 'Google Client Secret', 'type' => 'password']
            ]
        ],
        'google_business' => [
            'name'        => 'Google Business Profile',
            'category'    => 'reputation',
            'description' => 'إدارة المراجعات ونشر المنشورات والصور على خرائط جوجل.',
            'auth_type'   => 'oauth',
            'env_keys'    => ['GOOGLE_CLIENT_ID', 'GOOGLE_CLIENT_SECRET'],
            'fields'      => [
                ['name' => 'GOOGLE_CLIENT_ID', 'label' => 'Google Client ID', 'type' => 'text'],
                ['name' => 'GOOGLE_CLIENT_SECRET', 'label' => 'Google Client Secret', 'type' => 'password']
            ]
        ],
        'slack' => [
            'name'        => 'Slack',
            'category'    => 'notifications',
            'description' => 'إرسال التنبيهات المباشرة لفريق العمل عبر قنوات سلاك.',
            'auth_type'   => 'webhook',
            'env_keys'    => ['SLACK_WEBHOOK_URL'],
            'fields'      => [
                ['name' => 'SLACK_WEBHOOK_URL', 'label' => 'Slack Webhook URL', 'type' => 'text']
            ]
        ],
        'hubspot' => [
            'name'        => 'HubSpot',
            'category'    => 'crm',
            'description' => 'مزامنة جهات الاتصال وبيانات العملاء المحتملين.',
            'auth_type'   => 'api_key',
            'env_keys'    => ['HUBSPOT_API_KEY'],
            'fields'      => [
                ['name' => 'HUBSPOT_API_KEY', 'label' => 'HubSpot API Key', 'type' => 'password']
            ]
        ],
        'zapier' => [
            'name'        => 'Zapier',
            'category'    => 'automation',
            'description' => 'ربط وتمرير الأحداث إلى آلاف التطبيقات عبر الويب هوك.',
            'auth_type'   => 'webhook',
            'env_keys'    => ['ZAPIER_WEBHOOK_URL'],
            'fields'      => [
                ['name' => 'ZAPIER_WEBHOOK_URL', 'label' => 'Zapier Webhook URL', 'type' => 'text']
            ]
        ]
    ];

    public static function all(): array
    {
        $list = [];
        foreach (self::$integrations as $key => $meta) {
            $configured = self::isConfigured($key);
            
            // إضافة القيم الحالية للحقول إن وجدت في الخادم
            if (isset($meta['fields'])) {
                foreach ($meta['fields'] as &$field) {
                    $field['value'] = self::getEnvValue($field['name']);
                }
            }

            // استخدام المفتاح النصي (مثل stripe) بدلاً من الترقيم (0, 1, 2)
            $list[$key] = array_merge($meta, [
                'id'         => $key,
                'key'        => $key,
                'configured' => $configured,
                'status'     => $configured ? 'active' : 'inactive'
            ]);
        }
        return $list;
    }

    public static function isConfigured(string $key): bool
    {
        $meta = self::$integrations[$key] ?? null;
        if (!$meta || empty($meta['env_keys'])) {
            return false;
        }

        foreach ($meta['env_keys'] as $envKey) {
            if (!empty(self::getEnvValue($envKey))) {
                return true;
            }
        }

        return false;
    }

    public static function get(string $key): ?array
    {
        if (!isset(self::$integrations[$key])) {
            return null;
        }
        $meta = self::$integrations[$key];
        $meta['key'] = $key;
        $meta['configured'] = self::isConfigured($key);
        return $meta;
    }

    private static function getEnvValue(string $key): string
    {
        $val = getenv($key);
        if (empty($val) && defined($key)) {
            $val = constant($key);
        }
        return (string)$val;
    }
}