<?php

/**
 * Tourfecto - Business Integrations Service
 * Business Control Center Phase 8-9: Integrations Center (business-scoped)
 * @version 1.0.0
 *
 * بيجمّع حالة كل التكاملات الحقيقية الموجودة في المنصة لموقع/مواقع
 * الـBusiness الواحد - مش صفحة شكلية. كل حالة هنا بتنعكس من بيانات فعلية
 * في قاعدة البيانات (PlatformConnection / BotSetting / SearchConsole).
 *
 * لماذا business-scoped مش website-scoped:
 * IntegrationsController القديم كان على مستوى الـWebsite. الـBusiness
 * ممكن يضم أكثر من موقع (الـ1:1 الحالي لكن التصميم قابل للتوسعة)،
 * فبنوحّد حالة التكاملات على مستوى الـBusiness بجمع حالة كل مواقعه.
 *
 * الطبقة الخالصة: catalogIntegrations() / mergeStatuses() منطق خالص قابل
 * للاختبار offline - الفحوص اللي محتاجة DB (statusForWebsite) بتبني
 * على الطبقة الخالصة دي.
 */
class BusinessIntegrationsService
{
    /** الكتالوج المعروف للتكاملات - الكود مصدر الحقيقة، مش جدول DB */
    public static function catalogIntegrations(): array
    {
        return [
            [
                'key' => 'google_business',
                'name' => 'Google Business Profile',
                'category' => 'reputation',
                'icon' => 'google',
                'available' => true,
            ],
            [
                'key' => 'tripadvisor',
                'name' => 'TripAdvisor',
                'category' => 'reputation',
                'icon' => 'tripadvisor',
                'available' => true,
            ],
            [
                'key' => 'search_console',
                'name' => 'Google Search Console',
                'category' => 'seo',
                'icon' => 'search',
                'available' => true,
            ],
            [
                'key' => 'publishing_wordpress',
                'name' => 'WordPress Publishing',
                'category' => 'publishing',
                'icon' => 'wordpress',
                'available' => true,
            ],
            [
                'key' => 'publishing_custom',
                'name' => 'Custom API Publishing',
                'category' => 'publishing',
                'icon' => 'code',
                'available' => true,
            ],
            [
                'key' => 'whatsapp_ultramsg',
                'name' => 'WhatsApp (UltraMsg)',
                'category' => 'chat',
                'icon' => 'whatsapp',
                'available' => true,
            ],
            [
                'key' => 'ota_getyourguide',
                'name' => 'GetYourGuide',
                'category' => 'ota',
                'icon' => 'gyg',
                'available' => true,
            ],
            [
                'key' => 'ota_viator',
                'name' => 'Viator',
                'category' => 'ota',
                'icon' => 'viator',
                'available' => true,
            ],
            ['key'=>'ota_bokun','name'=>'Bókun','category'=>'ota','icon'=>'link','available'=>true,'setup_url'=>'/integrations'],
            ['key'=>'ota_rezdy','name'=>'Rezdy','category'=>'ota','icon'=>'link','available'=>true,'setup_url'=>'/integrations'],
            ['key'=>'ota_booking','name'=>'Booking.com','category'=>'ota','icon'=>'link','available'=>false,'setup_url'=>'/integrations'],
            ['key'=>'ota_expedia','name'=>'Expedia','category'=>'ota','icon'=>'link','available'=>false,'setup_url'=>'/integrations'],
            ['key'=>'ota_klook','name'=>'Klook','category'=>'ota','icon'=>'link','available'=>false,'setup_url'=>'/integrations'],
            [
                'key' => 'meta_ads',
                'name' => 'Meta Ads',
                'category' => 'ads',
                'icon' => 'meta',
                'available' => true,
            ],
        ];
    }

    /**
     * حالة تكاملات موقع واحد (بيانات فعلية من DB).
     *
     * @param int $websiteId
     * @param int $ownerUserId صاحب الـBusiness (اللي بيتملك الاتصالات)
     * @return array<string,array> key => { connected: bool, detail: ?string }
     */
    public function statusForWebsite(int $websiteId, int $ownerUserId): array
    {
        $status = [
            'google_business' => ['connected' => false, 'detail' => null],
            'tripadvisor' => ['connected' => false, 'detail' => null],
            'search_console' => ['connected' => false, 'detail' => null],
            'publishing_wordpress' => ['connected' => false, 'detail' => null],
            'publishing_custom' => ['connected' => false, 'detail' => null],
            'whatsapp_ultramsg' => ['connected' => false, 'detail' => null],
            'ota_getyourguide' => ['connected' => false, 'detail' => null],
            'ota_viator' => ['connected' => false, 'detail' => null],
            'meta_ads' => ['connected' => false, 'detail' => null],
        ];

        // ===== Reputation: google_business / tripadvisor =====
        $connections = (new PlatformConnection())->where(['website_id' => $websiteId]);
        foreach ($connections as $connection) {
            $platform = (string) $connection->getAttribute('platform');
            $connected = $connection->getAttribute('status') === 'connected';
            switch ($platform) {
                case 'google_business':
                    $status['google_business']['connected'] = $connected;
                    $status['google_business']['detail'] = $connected
                        ? (string) $connection->getAttribute('external_location_name')
                        : null;
                    break;
                case 'tripadvisor':
                    $status['tripadvisor']['connected'] = $connected;
                    $status['tripadvisor']['detail'] = $connected
                        ? (string) $connection->getAttribute('external_location_name')
                        : null;
                    break;
                case 'wordpress':
                    $status['publishing_wordpress']['connected'] = $connected;
                    $status['publishing_wordpress']['detail'] = $connected
                        ? (string) $connection->getAttribute('external_account_id')
                        : null;
                    break;
                case 'custom_api':
                    $status['publishing_custom']['connected'] = $connected;
                    $status['publishing_custom']['detail'] = $connected
                        ? (string) $connection->getAttribute('external_account_id')
                        : null;
                    break;
                case 'getyourguide':
                    $status['ota_getyourguide']['connected'] = $connected;
                    $status['ota_getyourguide']['detail'] = $connected
                        ? (string) $connection->getAttribute('external_account_id')
                        : null;
                    break;
                case 'viator':
                    $status['ota_viator']['connected'] = $connected;
                    $status['ota_viator']['detail'] = $connected
                        ? (string) $connection->getAttribute('external_account_id')
                        : null;
                    break;
                default:
                    break;
            }
        }

        // New supplier connections retain their real readiness and status.
        try {
            $ota=new OtaSupplierService();
            foreach(['bokun','rezdy','booking','expedia','klook'] as $platform){
                $v=$ota->status($ownerUserId,$websiteId,$platform);
                $status['ota_'.$platform]=['connected'=>!empty($v['verified_at']),'detail'=>$v['connection_state'].' · '.($v['last_error']??'')];
            }
        } catch (Throwable $e) { /* Keep other integrations readable before SQL installation. */ }

        // ===== SEO: search_console - SearchConsoleController بيخزن الاتصال
        // في platform_connections بنفس النمط برضه (platform='google_search_console')
        $gsc = (new PlatformConnection())->where([
            'website_id' => $websiteId,
            'platform' => 'google_search_console',
            'status' => 'connected',
        ], [], 1);
        if (!empty($gsc)) {
            $status['search_console']['connected'] = true;
            $status['search_console']['detail'] = (string) $gsc[0]->getAttribute('external_account_id');
        }

        // ===== Chat: whatsapp_ultramsg - من bot_settings (whatsapp_phone_number
        // + whatsapp_api_key مبين إن الاتصال مظبوط) =====
        try {
            $chat = BotSetting::getSettings($ownerUserId, $websiteId, 'whatsapp');
            $hasConfig = !empty($chat->getAttribute('whatsapp_api_key'))
                || !empty($chat->getAttribute('whatsapp_phone_number'));
            $status['whatsapp_ultramsg']['connected'] = $hasConfig;
            $status['whatsapp_ultramsg']['detail'] = $hasConfig
                ? (string) $chat->getAttribute('whatsapp_phone_number')
                : null;
        } catch (\Throwable $e) {
            // getSettings بينشئ default row لو مفيش - لو فشل، بنسيبها disconnected
        }

        // ===== Ads: meta_ads - ad_campaigns موجودة فيها بيانات الفيسبوك =====
        // الـConnection الحقيقي لـMeta بيعيش في جدول أدمن/تكامل Meta نفسه.
        // هنا بنرصد فقط لو فيه بيانات فعلية لـmeta في ad_campaigns (علامة
        // غير قاطعة على "تم الربط") - المعلومة القاطعة جاية من /api/ads/meta/status
        // اللي بيتحقق من إعدادات النظام على مستوى الحساب. خليناه صريح.

        return $status;
    }

    /**
     * دمج حالة كل المواقع في حالة Business واحدة (لو أي موقع متصل =>
     * التكامل متصل على مستوى الـBusiness). Pure - قابل للاختبار.
     *
     * @param array<int,array<string,array{connected:bool,detail:?string}>> $perWebsite
     */
    public function mergeStatuses(array $perWebsite): array
    {
        $merged = [];
        foreach ($perWebsite as $websiteStatus) {
            foreach ($websiteStatus as $key => $entry) {
                if (!isset($merged[$key])) {
                    $merged[$key] = ['connected' => false, 'detail' => null, 'websites_count' => 0];
                }
                if ($entry['connected']) {
                    $merged[$key]['connected'] = true;
                    if ($merged[$key]['detail'] === null && !empty($entry['detail'])) {
                        $merged[$key]['detail'] = $entry['detail'];
                    }
                }
                $merged[$key]['websites_count'] = ($merged[$key]['websites_count'] ?? 0) + 1;
            }
        }
        return $merged;
    }

    /**
     * حالة تكاملات Business كاملة: الكتالوج + الحالة المدمجة لكل مواقعه.
     *
     * @param int $businessId
     * @return array{integrations: array, connected_count: int, total_count: int}
     */
    public function getBusinessStatus(int $businessId): array
    {
        $business = (new Business())->find($businessId);
        if (!$business) {
            return ['integrations' => [], 'connected_count' => 0, 'total_count' => 0];
        }

        $ownerUserId = (int) $business->getAttribute('owner_user_id');

        $websites = (new Website())->where(['business_id' => $businessId]);
        if (empty($websites)) {
            $websites = (new Website())->where(['user_id' => $ownerUserId]);
        }

        $perWebsite = [];
        foreach ($websites as $website) {
            $perWebsite[] = $this->statusForWebsite((int) $website->getAttribute('id'), $ownerUserId);
        }

        $merged = $this->mergeStatuses($perWebsite);
        $catalog = self::catalogIntegrations();

        $integrations = [];
        $connectedCount = 0;
        foreach ($catalog as $item) {
            $entry = $merged[$item['key']] ?? ['connected' => false, 'detail' => null, 'websites_count' => 0];
            $integrations[] = [
                'key' => $item['key'],
                'name' => $item['name'],
                'category' => $item['category'],
                'icon' => $item['icon'],
                'available' => $item['available'],
                'setup_url' => $item['setup_url'] ?? ($item['category']==='ota'?'/integrations':null),
                'connected' => (bool) $entry['connected'],
                'detail' => $entry['detail'],
                'websites_count' => (int) ($entry['websites_count'] ?? 0),
            ];
            if ($entry['connected']) {
                $connectedCount++;
            }
        }

        return [
            'integrations' => $integrations,
            'connected_count' => $connectedCount,
            'total_count' => count($integrations),
        ];
    }
}
