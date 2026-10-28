<?php

/**
 * Tourfecto - Integration Manager
 * @version 1.0.0
 *
 * نقطة الدخول الوحيدة لأي كنترولر/سيرفس عايز يكلّم API خارجي:
 *
 *   $result = IntegrationManager::get('stripe')->request('create_charge', [...]);
 *
 * بيقرأ التسجيل من app/Config/integrations.php، يتأكد إن المتغيرات في
 * .env موجودة، ويبني نسخة واحدة (singleton لكل platform) من كلاس
 * التكامل بتاعها. لو الـ platform مش مسجّلة أو الإعدادات ناقصة، بيرمي
 * Exception واضح بدل ما يفشل بصمت.
 */
class IntegrationManager
{
    private static array $registry = [];
    private static array $instances = [];

    private static function loadRegistry(): void
    {
        if (empty(self::$registry)) {
            self::$registry = require __DIR__ . '/../Config/integrations.php';
        }
    }

    /** كل الـ platforms المسجّلة (للوحة تحكم "إدارة التكاملات" مثلاً) */
    public static function all(): array
    {
        self::loadRegistry();
        return self::$registry;
    }

    /** كل الـ platforms المتاحة فعليًا (مفاتيحها موجودة في .env) */
    public static function available(): array
    {
        self::loadRegistry();
        return array_filter(self::$registry, fn (string $key) => self::isConfigured($key), ARRAY_FILTER_USE_KEY);
    }

    public static function isConfigured(string $platformKey): bool
    {
        self::loadRegistry();
        if (!isset(self::$registry[$platformKey])) {
            return false;
        }
        foreach (self::$registry[$platformKey]['env_keys'] as $envKey) {
            $value = self::envValue($envKey);
            if (!$value || strpos((string)$value, 'your-') === 0) {
                return false;
            }
        }
        $enabledEnv = self::$registry[$platformKey]['enabled_env'] ?? null;
        if ($enabledEnv !== null) {
            $enabled = self::envValue($enabledEnv);
            if (filter_var($enabled, FILTER_VALIDATE_BOOLEAN) === false) {
                return false;
            }
        }
        return true;
    }

    /**
     * قراءة قيمة متغير إعداد - الأولوية لـ system_settings (الإعدادات
     * المحفوظة من لوحة الأدمن)، وبعدين الثابت/env. لو في خطأ (جدول مش
     * موجود) بنكمل على الـ env.
     */
    private static function envValue(string $envKey): string
    {
        if (class_exists('SystemSettingsService')) {
            try {
                $settings = new SystemSettingsService();
                foreach (self::settingKeysForEnv($envKey) as $settingKey) {
                    $dbValue = $settings->get($settingKey, '');
                    if ($dbValue !== '') {
                        return $dbValue;
                    }
                }
            } catch (Throwable $e) {
                // نتجاهل - بنكمل على الـ env
            }
        }
        if (defined($envKey)) {
            return (string) constant($envKey);
        }
        return (string) (function_exists('env') ? env($envKey) : getenv($envKey));
    }

    private static function settingKeysForEnv(string $envKey): array
    {
        $map = [
            'GEMINI_API_KEY' => 'gemini_api_key',
            'GOOGLE_CLIENT_ID' => 'google_client_id',
            'GOOGLE_CLIENT_SECRET' => 'google_client_secret',
            'GOOGLE_MAPS_API_KEY' => 'google_maps_api_key',
            'GOOGLE_ADS_DEVELOPER_TOKEN' => 'google_ads_developer_token',
            'GOOGLE_ADS_LOGIN_CUSTOMER_ID' => 'google_ads_login_customer_id',
            'GOOGLE_ADS_OAUTH_REDIRECT_URI' => 'google_ads_oauth_redirect_uri',
            'META_APP_ID' => 'meta_app_id',
            'META_APP_SECRET' => 'meta_app_secret',
            'MAIL_HOST' => 'mail_host',
            'MAIL_PORT' => 'mail_port',
            'MAIL_USERNAME' => 'mail_username',
            'MAIL_PASSWORD' => 'mail_password',
            'MAIL_ENCRYPTION' => 'mail_encryption',
            'MAIL_FROM_ADDRESS' => 'mail_from_address',
            'MAIL_FROM_NAME' => 'mail_from_name',
            'SUPPORT_WHATSAPP_NUMBER' => 'support_whatsapp_number',
        ];

        $keys = [];
        if (isset($map[$envKey])) {
            $keys[] = $map[$envKey];
        }
        $keys[] = 'integration_' . strtolower($envKey);
        return $keys;
    }

    /**
     * @throws Exception لو الـ platform مش مسجّلة أو الكلاس بتاعها مش موجود
     */
    public static function get(string $platformKey): IntegrationInterface
    {
        self::loadRegistry();

        if (!isset(self::$registry[$platformKey])) {
            throw new Exception("Integration '{$platformKey}' غير مسجّلة في app/Config/integrations.php");
        }

        if (isset(self::$instances[$platformKey])) {
            return self::$instances[$platformKey];
        }

        $className = self::$registry[$platformKey]['class'];
        if (!class_exists($className)) {
            throw new Exception("كلاس التكامل '{$className}' للـ platform '{$platformKey}' مش موجود. راجع autoload_classmap.php");
        }

        $instance = new $className();
        if (!($instance instanceof IntegrationInterface)) {
            throw new Exception("كلاس '{$className}' لازم يعمل implement لـ IntegrationInterface");
        }

        self::$instances[$platformKey] = $instance;
        return $instance;
    }

    /** metadata الخاصة بـ platform معينة (auth_type, oauth_scope...) */
    public static function meta(string $platformKey): ?array
    {
        self::loadRegistry();
        return self::$registry[$platformKey] ?? null;
    }
}
