<?php

/**
 * Tourfecto - OTA Controller
 * ربط منصات وساطة السياحة (OTA - Online Travel Agencies) بحساب كل عميل:
 * GetYourGuide و Viator. زي باقي التكاملات، كل عميل بيربط حساب الـ
 * Partner/Affiliate الخاص بيه هو (مش حساب واحد عام للمنصة كلها).
 *
 * ملاحظة مهمة: المنصتين دول بيدّوا مفتاح API بس بعد ما يوافقوا على حساب
 * الشريك (partner account) - مفيش تسجيل ذاتي فوري زي Stripe مثلاً.
 * العميل لازم يطلب حساب Partner من:
 *   - GetYourGuide: https://partner.getyourguide.com/
 *   - Viator:       https://partnerresources.viator.com/ (أو يتواصل مع
 *                    affiliateapi@viator.com لو المفتاح مش ظاهر في حسابه)
 *
 * نفس نمط ChatController::connectUltraMsg بالظبط: بنتحقق من صحة المفتاح
 * فعليًا (نداء حقيقي على API الشريك) قبل ما نحفظه، وبنخزّنه مشفّر في
 * platform_connections (نفس الجدول المستخدم لـ Google/TripAdvisor/UltraMsg).
 * @version 1.0.0
 */
class OTAController extends Controller
{
    /** الإعدادات الخاصة بكل منصة OTA مدعومة */
    private function platformMeta(string $platform): ?array
    {
        $map = [
            'getyourguide' => [
                'label' => 'GetYourGuide',
                'credential_field' => 'access_token', // X-ACCESS-TOKEN
                'credential_label' => 'Access Token',
            ],
            'viator' => [
                'label' => 'Viator',
                'credential_field' => 'api_key', // exp-api-key
                'credential_label' => 'API Key',
            ],
        ];
        return $map[$platform] ?? null;
    }

    private function client(string $platform, string $credential): ?object
    {
        if ($platform === 'getyourguide') {
            return new GetYourGuideAPI($credential);
        }
        if ($platform === 'viator') {
            return new ViatorAPI($credential);
        }
        return null;
    }

    /** GET /api/ota/status?website_id=&platform= */
    public function getStatus(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $websiteId = (int) $this->get('website_id');
        $platform = (string) $this->get('platform', '');
        if (!$websiteId || !$this->platformMeta($platform)) {
            return $this->error('Invalid request', 422);
        }

        $website = (new Website())->find($websiteId);
        if (!$website || (int) $website->getAttribute('user_id') !== (int) $this->user['id']) {
            return $this->error('Website not found', 404);
        }
        $connections = (new PlatformConnection())->where([
            'website_id' => $websiteId,
            'platform' => $platform,
            'status' => 'connected',
        ], [], 1);

        return $this->success([
            'connected' => !empty($connections),
            'connection_state' => !empty($connections) ? 'saved' : 'disconnected',
            'live_verified' => false,
            'booking_sync_enabled' => false,
            'inventory_sync_enabled' => false,
            'message' => 'Saved partner credentials do not enable supplier booking or inventory synchronization.',
            'external_account_id' => !empty($connections) ? $connections[0]->getAttribute('external_account_id') : null,
            'connected_at' => !empty($connections) ? $connections[0]->getAttribute('created_at') : null,
        ]);
    }

    /** POST /api/ota/connect  { website_id, platform: getyourguide|viator, credential, partner_id? } */
    public function connect(array $params = []): array
    {
        if ($csrfError = $this->verifyCsrf()) return $csrfError;
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $websiteId = (int) $this->get('website_id');
        $platform = (string) $this->get('platform', '');
        $credential = trim((string) $this->get('credential', ''));
        $partnerId = trim((string) $this->get('partner_id', ''));

        $meta = $this->platformMeta($platform);
        if (!$websiteId || !$meta || $credential === '') {
            return $this->error('Website and API credential are required', 422);
        }

        $website = (new Website())->find($websiteId);
        if (!$website || (int) $website->getAttribute('user_id') !== (int) $this->user['id']) {
            return $this->error('Website not found', 404);
        }

        // تحقق فعلي إن المفتاح صحيح قبل ما نحفظه - بدل ما نحفظ ونكتشف
        // بعدين وقت أول مزامنة إن المفتاح غلط أو الحساب لسه مش معتمد.
        $client = $this->client($platform, $credential);
        $verify = $client->verifyToken();
        if (!$verify['success']) {
            return $this->error($verify['error'] ?? 'Unable to verify the credential', 422);
        }

        try {
            $encryption = new Encryption();
            $existing = (new PlatformConnection())->where([
                'website_id' => $websiteId,
                'platform' => $platform,
            ], [], 1);

            $connection = new PlatformConnection([
                'website_id' => $websiteId,
                'user_id' => $this->user['id'],
                'platform' => $platform,
                'access_token' => $encryption->encrypt($credential),
                'external_account_id' => $partnerId !== '' ? $partnerId : null,
                'external_location_name' => $meta['label'] . ($partnerId !== '' ? ' - ' . $partnerId : ''),
                'status' => 'connected',
                'last_error' => null,
            ]);

            if (!empty($existing)) {
                $connection->setKeyValue($existing[0]->getAttribute('id'));
            }
            $connection->save();

            $this->log('OTA Connected', ['website_id' => $websiteId, 'platform' => $platform]);

            return $this->success([], 'Connected to ' . $meta['label'] . ' successfully');
        } catch (Exception $e) {
            Logger::error('Connect OTA Error', ['platform' => $platform, 'message' => $e->getMessage()]);
            return $this->error('Unable to save the connection', 500);
        }
    }

    /** POST /api/ota/disconnect/{platform}/{website_id} */
    public function disconnect(array $params = []): array
    {
        if ($csrfError = $this->verifyCsrf()) return $csrfError;
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $platform = (string) ($params['platform'] ?? '');
        $websiteId = (int) ($params['website_id'] ?? 0);
        if (!$this->platformMeta($platform)) {
            return $this->error('Unsupported platform', 422);
        }

        try {
            $website = (new Website())->find($websiteId);
        if (!$website || (int) $website->getAttribute('user_id') !== (int) $this->user['id']) {
            return $this->error('Website not found', 404);
        }
        $connections = (new PlatformConnection())->where(['website_id' => $websiteId, 'platform' => $platform]);
            foreach ($connections as $conn) {
                if ((int) $conn->getAttribute('user_id') === (int) $this->user['id']) {
                    $conn->delete();
                }
            }
            return $this->success([], 'Disconnected');
        } catch (Exception $e) {
            Logger::error('Disconnect OTA Error', ['platform' => $platform, 'message' => $e->getMessage()]);
            return $this->error('Unable to disconnect', 500);
        }
    }
}
