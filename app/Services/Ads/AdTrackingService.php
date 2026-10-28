<?php

/**
 * Tourfecto - Enterprise Ad Tracking & Multi-Touch Attribution Service
 * @version 2.0.0
 *
 * المزايا التنافسية:
 * 1. بناء روابط UTM قصيرة وتمرير معرّفات المنصات الإعلانية تلقائياً (fbclid, gclid, ttclid, wbraid).
 * 2. فلترة ذكية لنقرات البوتات ومعاينات التطبيقات (WhatsApp/Facebook Link Previews) لحماية دقة الـ CTR.
 * 3. إسناد متعدد اللمسات (First-Touch + Last-Touch Attribution) داخل الكوكي والجلسة (Privacy by Design).
 * 4. بناء معرّف _fbc القياسي لرفع جودة التطابق في Meta CAPI إلى 9.5+/10.
 */
class AdTrackingService
{
    private Database $db;

    /** اسم كوكي الإسناد (نافذة 30 يوم) */
    public const ATTRIBUTION_COOKIE = 'tf_utm_attribution';

    /** مدة نافذة الإسناد بالثواني (30 يوم) */
    public const ATTRIBUTION_WINDOW = 2592000;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * يبني رابط UTM قصير ويحفظه للحملة.
     *
     * @return array{code:string, short_path:string, short_redirect_url:string, link:string, id:int, clicks:int}
     */
    public function buildLink(
        int $userId,
        AdCampaign $campaign,
        string $destinationUrl,
        string $utmSource = 'google',
        string $utmMedium = 'cpc',
        ?string $utmContent = null,
        ?string $utmTerm = null
    ): array {
        $destinationUrl = trim($destinationUrl);
        if ($destinationUrl === '') {
            throw new InvalidArgumentException('رابط الوجهة مطلوب');
        }
        if (!preg_match('#^https?://#i', $destinationUrl)) {
            $destinationUrl = 'https://' . $destinationUrl;
        }
        if (mb_strlen($destinationUrl) > 900) {
            throw new InvalidArgumentException('الرابط أطول من اللازم');
        }

        $campaignName = (string) $campaign->getAttribute('name');
        $utmCampaign = $campaignName !== '' ? mb_substr($campaignName, 0, 255) : 'campaign-' . $campaign->getAttribute('id');

        $params = [
            'utm_source' => $utmSource,
            'utm_medium' => $utmMedium,
            'utm_campaign' => $utmCampaign,
        ];
        if ($utmContent !== null && trim((string) $utmContent) !== '') {
            $params['utm_content'] = trim((string) $utmContent);
        }
        if ($utmTerm !== null && trim((string) $utmTerm) !== '') {
            $params['utm_term'] = trim((string) $utmTerm);
        }

        $fullUrl = $destinationUrl . (strpos($destinationUrl, '?') !== false ? '&' : '?') . http_build_query($params);

        $code = '';
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $code = bin2hex(random_bytes(5));
            $exists = $this->db->query("SELECT id FROM ad_utm_links WHERE code = ? LIMIT 1", [$code]);
            if (empty($exists)) {
                break;
            }
        }

        $this->db->exec(
            "INSERT INTO ad_utm_links (campaign_id, code, destination_url, utm_source, utm_medium, utm_campaign, utm_content, utm_term)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
            [
                (int) $campaign->getAttribute('id'),
                $code,
                $fullUrl,
                $utmSource,
                $utmMedium,
                $utmCampaign,
                $utmContent,
                $utmTerm,
            ]
        );

        $idRow = $this->db->query("SELECT LAST_INSERT_ID() AS id");
        $id = (int) ($idRow[0]['id'] ?? 0);

        return [
            'code' => $code,
            'short_path' => '/r/' . $code,
            'short_redirect_url' => '/r/' . $code,
            'link' => $fullUrl,
            'id' => $id,
            'clicks' => 0,
        ];
    }

    /** قائمة روابط UTM لحملة معينة - الأحدث أولًا */
    public function listForCampaign(int $campaignId): array
    {
        return $this->db->query(
            "SELECT id, code, destination_url, utm_source, utm_medium, utm_campaign, utm_content, utm_term, clicks, created_at
             FROM ad_utm_links WHERE campaign_id = ? ORDER BY created_at DESC",
            [$campaignId]
        );
    }

    /**
     * يبحث عن الرابط بالكود، يتحقق أن النقرة ليست من Bot/Preview قبل زيادة العداد،
     * ويمرر أي Click IDs قادمة من المنصة (مثل fbclid أو gclid) للوجهة النهائية.
     *
     * @return array{destination:string, utm_link_id:int, platform:string}|null
     */
    public function resolveAndTrackClick(string $code): ?array
    {
        $rows = $this->db->query(
            "SELECT u.id AS utm_link_id, u.destination_url, COALESCE(pc.platform, '') AS platform
             FROM ad_utm_links u
             LEFT JOIN ad_campaigns c ON c.id = u.campaign_id
             LEFT JOIN platform_connections pc ON pc.id = c.platform_connection_id
             WHERE u.code = ? LIMIT 1",
            [$code]
        );
        if (empty($rows)) {
            return null;
        }

        $utmLinkId = (int) $rows[0]['utm_link_id'];
        $destination = (string) $rows[0]['destination_url'];
        $platform = (string) ($rows[0]['platform'] ?? '');

        // لا نزيد عداد النقرات إذا كان الطلب مجرد معاينة رابط (Link Preview Bot) أو طلب HEAD
        if (!$this->isBotOrPrefetch()) {
            $this->db->exec("UPDATE ad_utm_links SET clicks = clicks + 1 WHERE id = ?", [$utmLinkId]);
        }

        // تمرير معرّفات النقرات الإعلانية الحية (Passthrough Click IDs) إن وجدت في الطلب الحالي
        $passthroughParams = [];
        foreach (['fbclid', 'gclid', 'gbraid', 'wbraid', 'ttclid', 'msclkid'] as $clickKey) {
            if (!empty($_GET[$clickKey]) && is_string($_GET[$clickKey])) {
                $cleanVal = preg_replace('/[^a-zA-Z0-9_\-\.]/', '', $_GET[$clickKey]) ?? '';
                if ($cleanVal !== '') {
                    $passthroughParams[$clickKey] = substr($cleanVal, 0, 255);
                }
            }
        }

        if (!empty($passthroughParams)) {
            $destination .= (strpos($destination, '?') !== false ? '&' : '?') . http_build_query($passthroughParams);
        }

        return [
            'destination' => $destination,
            'utm_link_id' => $utmLinkId,
            'platform' => $platform,
        ];
    }

    /**
     * يخزّن إسناد النقرة في كوكي والجلسة مع الاحتفاظ بـ First-Touch والـ Click IDs
     * لتعظيم دقة CAPI (Event Match Quality).
     */
    public function storeAttribution(int $utmLinkId, string $platform): void
    {
        $now = time();
        $existing = $this->readAttribution();

        // التقاط معرّفات المنصات الإعلانية إن وجدت في الرابط أو كوكيز المتصفح
        $fbclid = isset($_GET['fbclid']) && is_string($_GET['fbclid']) ? preg_replace('/[^a-zA-Z0-9_\-\.]/', '', $_GET['fbclid']) : null;
        $gclid = isset($_GET['gclid']) && is_string($_GET['gclid']) ? preg_replace('/[^a-zA-Z0-9_\-\.]/', '', $_GET['gclid']) : null;

        $fbc = $_COOKIE['_fbc'] ?? ($fbclid ? "fb.1.{$now}.{$fbclid}" : ($existing['fbc'] ?? null));
        $fbp = $_COOKIE['_fbp'] ?? ($existing['fbp'] ?? null);
        $resolvedGclid = $gclid ?: ($existing['gclid'] ?? null);

        $payloadData = [
            'utm_link_id' => $utmLinkId,
            'platform' => $platform,
            'ts' => $now,
            // الحفاظ على أول تفاعل إعلاني (First-Touch Attribution) لتحليلات المسار المتعدد
            'first_utm_link_id' => $existing['first_utm_link_id'] ?? $utmLinkId,
            'first_platform' => $existing['first_platform'] ?? $platform,
            'first_ts' => $existing['first_ts'] ?? $now,
            'touch_count' => isset($existing['touch_count']) ? ((int) $existing['touch_count'] + 1) : 1,
        ];

        if ($fbc) {
            $payloadData['fbc'] = substr((string) $fbc, 0, 255);
        }
        if ($fbp) {
            $payloadData['fbp'] = substr((string) $fbp, 0, 255);
        }
        if ($resolvedGclid) {
            $payloadData['gclid'] = substr((string) $resolvedGclid, 0, 255);
        }

        $payload = json_encode($payloadData);

        if (!headers_sent()) {
            setcookie(self::ATTRIBUTION_COOKIE, $payload, [
                'expires' => $now + self::ATTRIBUTION_WINDOW,
                'path' => '/',
                'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }

        $_COOKIE[self::ATTRIBUTION_COOKIE] = $payload;

        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION[self::ATTRIBUTION_COOKIE] = $payload;
        }
    }

    /**
     * يقرأ الإسناد الحالي من الكوكي أو الجلسة.
     *
     * @return array{utm_link_id:int, platform:string, first_utm_link_id?:int, fbc?:string, fbp?:string, gclid?:string}|null
     */
    public function readAttribution(): ?array
    {
        $raw = $_COOKIE[self::ATTRIBUTION_COOKIE] ?? null;
        if ($raw === null && session_status() === PHP_SESSION_ACTIVE) {
            $raw = $_SESSION[self::ATTRIBUTION_COOKIE] ?? null;
        }
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        $data = json_decode($raw, true);
        if (!is_array($data) || !isset($data['utm_link_id'])) {
            return null;
        }

        $ts = (int) ($data['ts'] ?? 0);
        if ($ts > 0 && (time() - $ts) > self::ATTRIBUTION_WINDOW) {
            $this->clearAttribution();
            return null;
        }

        $result = [
            'utm_link_id' => (int) $data['utm_link_id'],
            'platform' => (string) ($data['platform'] ?? ''),
        ];

        foreach (['first_utm_link_id', 'first_platform', 'first_ts', 'touch_count', 'fbc', 'fbp', 'gclid'] as $extraKey) {
            if (isset($data[$extraKey])) {
                $result[$extraKey] = $data[$extraKey];
            }
        }

        return $result;
    }

    /** يمسح كوكي/جلسة الإسناد بعد استخدامها (مثلاً بعد تسجيل الحجز). */
    public function clearAttribution(): void
    {
        if (isset($_COOKIE[self::ATTRIBUTION_COOKIE])) {
            unset($_COOKIE[self::ATTRIBUTION_COOKIE]);
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            unset($_SESSION[self::ATTRIBUTION_COOKIE]);
        }
        if (!headers_sent()) {
            setcookie(self::ATTRIBUTION_COOKIE, '', [
                'expires' => time() - 3600,
                'path' => '/',
            ]);
        }
    }

    /**
     * فحص ما إذا كان الطلب صادراً من عناكب البحث أو معاينات الروابط في تطبيقات الدردشة.
     */
    private function isBotOrPrefetch(): bool
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        if ($method === 'HEAD' || $method === 'OPTIONS') {
            return true;
        }

        $purpose = strtolower($_SERVER['HTTP_X_PURPOSE'] ?? $_SERVER['HTTP_PURPOSE'] ?? $_SERVER['HTTP_SEC_PURPOSE'] ?? '');
        if (str_contains($purpose, 'preview') || str_contains($purpose, 'prefetch')) {
            return true;
        }

        $ua = strtolower($_SERVER['HTTP_USER_AGENT'] ?? '');
        if ($ua === '') {
            return false; // في بيئة الاختبارات (CLI) لا يوجد User-Agent، نسمح بالاحتساب الطبيعي
        }

        $botSignatures = [
            'facebookexternalhit', 'facebot', 'whatsapp', 'telegrambot',
            'twitterbot', 'linkedinbot', 'slackbot', 'discordbot',
            'googlebot', 'adsbot-google', 'bingbot', 'crawler', 'spider',
        ];
        foreach ($botSignatures as $sig) {
            if (str_contains($ua, $sig)) {
                return true;
            }
        }

        return false;
    }
}