<?php

/**
 * Tourfecto - GBP Setup Status Service
 * Setup Wizard حقيقي: بيفحص فعليًا حالة كل متطلبات تشغيل GBP (Maps،
 * OAuth Client، الاتصال الفعلي لكل موقع) بدل ما يخترع حالة "شغال".
 * @version 1.0.0
 * @since 2026-08-09 (GBP Module Upgrade)
 */
class GbpSetupStatusService
{
    /** @var Database */
    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * حالة إعداد النظام العامة (مش خاصة بموقع معيّن): Maps + OAuth Client.
     * كل حالة من: connected | missing | error
     */
    public function systemStatus(): array
    {
        $envMapsKey = defined('GOOGLE_MAPS_API_KEY') ? GOOGLE_MAPS_API_KEY : '';
        $mapsKey = class_exists('SystemSettingsService')
            ? (new SystemSettingsService())->get('google_maps_api_key', $envMapsKey)
            : $envMapsKey;
        $mapsKey = is_real_secret($mapsKey) ? $mapsKey : '';

        $oauth = new GoogleOAuthClient();

        return [
            'google_maps' => [
                'status' => $mapsKey !== '' ? 'connected' : 'missing',
                'label' => 'Google Maps',
                'detail' => $mapsKey !== ''
                    ? 'مفتاح Maps API مضبوط'
                    : 'يحتاج GOOGLE_MAPS_API_KEY من لوحة الأدمن أو ملف .env',
            ],
            'oauth_client' => [
                'status' => $oauth->isConfigured() ? 'connected' : 'missing',
                'label' => 'OAuth Client (Google Cloud)',
                'detail' => $oauth->isConfigured()
                    ? 'بيانات OAuth Client مضبوطة على مستوى النظام'
                    : 'يحتاج GOOGLE_CLIENT_ID / GOOGLE_CLIENT_SECRET / GOOGLE_OAUTH_REDIRECT_URI',
            ],
            'business_profile_api' => [
                // ملحوظة أمانة: مفيش endpoint واحد يأكد "تفعيل" Business Profile
                // API من غير طلب فعلي بتوكن مستخدم حقيقي (وده بيحصل وقت
                // OAuth flow نفسه). هنا بنقدر بس نأكد إن العميل (Client ID)
                // مهيّأ؛ التفعيل الفعلي على مستوى Google Cloud Project
                // بيتأكد لحظة أول محاولة ربط حقيقية (Action Required وقتها لو رفضت).
                'status' => $oauth->isConfigured() ? 'action_required' : 'missing',
                'label' => 'Google Business Profile API Access',
                'detail' => $oauth->isConfigured()
                    ? 'اتفعّل تفعيل الـ API من Google Cloud Console يتأكد فعليًا عند أول محاولة ربط'
                    : 'اربط أولاً OAuth Client، بعدين فعّل Business Profile API من Google Cloud',
            ],
        ];
    }

    /**
     * حالة الاتصال والمزامنة لكل مواقع المستخدم (Connection Center).
     */
    public function connectionsForUser(int $userId): array
    {
        $rows = $this->db->query(
            "SELECT pc.id, pc.website_id, pc.status, pc.last_error, pc.last_synced_at,
                    pc.external_account_id, pc.external_location_id, pc.external_location_name,
                    pc.token_expires_at, w.company_name, w.main_url
             FROM platform_connections pc
             JOIN websites w ON w.id = pc.website_id
             WHERE pc.user_id = ? AND pc.platform = 'google_business'
             ORDER BY pc.id DESC",
            [$userId]
        );

        return array_map(function ($row) {
            $tokenExpired = empty($row['token_expires_at']) ? true : (strtotime($row['token_expires_at']) <= time());
            return [
                'connection_id' => (int) $row['id'],
                'website_id' => (int) $row['website_id'],
                'website_name' => $row['company_name'] ?: $row['main_url'],
                'status' => $row['status'],
                'last_error' => $row['last_error'],
                'last_synced_at' => $row['last_synced_at'],
                'location_name' => $row['external_location_name'],
                'account_id' => $row['external_account_id'],
                'location_id' => $row['external_location_id'],
                'token_expired' => $tokenExpired,
            ];
        }, $rows);
    }

    /** كل مواقع المستخدم مع علامة إذا كانت مربوطة أم لا (لعرض "Add Location" بشكل صحيح) */
    public function websitesWithConnectionState(int $userId): array
    {
        $rows = $this->db->query(
            "SELECT w.id, w.company_name, w.main_url,
                    pc.id AS connection_id, pc.status AS connection_status
             FROM websites w
             LEFT JOIN platform_connections pc
                    ON pc.website_id = w.id AND pc.platform = 'google_business' AND pc.user_id = w.user_id
             WHERE w.user_id = ?
             ORDER BY w.id DESC",
            [$userId]
        );

        return array_map(function ($row) {
            return [
                'website_id' => (int) $row['id'],
                'website_name' => $row['company_name'] ?: $row['main_url'],
                'connected' => !empty($row['connection_id']) && $row['connection_status'] === 'connected',
                'connection_id' => $row['connection_id'] ? (int) $row['connection_id'] : null,
            ];
        }, $rows);
    }
}
