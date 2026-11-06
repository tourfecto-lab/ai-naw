<?php

/**
 * Tourfecto - Visitor Tracking Middleware
 * تتبع زوار الموقع التسويقي وأيضًا تصفح العملاء داخل المنصة بعد تسجيل الدخول
 * @version 1.0.0
 * @author Tourfecto Team
 * @copyright 2026 Tourfecto
 *
 * ملاحظة: مُسجَّل ضمن الميدل وير العام (Global Middleware) في public_html/index.php
 * لأنه لازم يشتغل على كل طلب GET بصرف النظر عن الـ Route (زي LoggingMiddleware).
 * أي فشل هنا (قاعدة بيانات، جدول ناقص...) لازم ميوقفش الموقع، فكل حاجة محاطة بـ try/catch.
 */

class VisitorTrackingMiddleware
{
    /** @var array المسارات المستثناة من التتبع (أصول ثابتة، API، فحوصات صحية) */
    private $excludedPrefixes = [
        '/assets', '/favicon.ico', '/robots.txt', '/sitemap.xml', '/.well-known',
        '/health', '/ping', '/api/', '/i18n-dict',
    ];

    /**
     * وكلاء مش زوار: فحص التشغيل بتاع المنصة نفسها وسكريبتات وزواحف. من غير كوكي، كل طلب منهم كان بيتحسب «زائر جديد»
     * (فحص التشغيل لوحده كان بيطلّع آلاف «زوار» من فرنسا).
     */
    public const NON_HUMAN_AGENT = '/TourfectoOpsCheck|HeadlessChrome|python-requests|python-urllib|aiohttp|curl\/|wget|Go-http-client|axios|node-fetch|okhttp|libwww|Java\/|GuzzleHttp|PostmanRuntime|bot\b|crawl|spider|slurp|scrapy|facebookexternalhit|Bytespider|GPTBot|ClaudeBot|PerplexityBot|Lighthouse|UptimeRobot|Pingdom|monitor/i';

    public static function isNonHumanAgent(?string $userAgent): bool
    {
        $ua = (string) $userAgent;
        return $ua === '' || preg_match(self::NON_HUMAN_AGENT, $ua) === 1;
    }

    /** @var string اسم كوكي معرّف الزائر الثابت */
    private const VISITOR_COOKIE = 'tf_visitor_id';

    public function handle(): ?array
    {
        try {
            $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
            if ($method !== 'GET') {
                return null;
            }

            $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

            foreach ($this->excludedPrefixes as $prefix) {
                if (strpos($path, $prefix) === 0) {
                    return null;
                }
            }

            $this->track($path);
        } catch (Throwable $e) {
            if (class_exists('Logger')) {
                Logger::warning('VisitorTrackingMiddleware failed', ['message' => $e->getMessage()]);
            }
        }

        return null;
    }

    /**
     * تسجيل الزيارة في جدول visitor_logs
     * @param string $path
     */
    private function track(string $path): void
    {
        $visitorId = $_COOKIE[self::VISITOR_COOKIE] ?? null;

        if (!$visitorId) {
            $visitorId = bin2hex(random_bytes(16));
            setcookie(self::VISITOR_COOKIE, $visitorId, [
                'expires' => time() + (86400 * 365),
                'path' => '/',
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }

        $ip = function_exists('get_client_ip') ? get_client_ip() : ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        $userAgent = function_exists('get_user_agent') ? get_user_agent() : ($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown');

        if (self::isNonHumanAgent($userAgent)) {
            return;
        }
        // تجاهل تتبع الروبوتات/الزواحف عشان الإحصائيات متتلوثش
        $device = class_exists('DeviceDetector') ? DeviceDetector::parse($userAgent) : ['device_type' => null, 'browser' => null, 'platform' => null];
        if (($device['device_type'] ?? null) === 'bot') {
            return;
        }

        $userId = $_SESSION['user_id'] ?? ($_SERVER['auth_user_id'] ?? null);
        $isAuthenticated = $userId ? 1 : 0;

        // عميل جاي من لينك موظف/حملة: نربط حسابه بالشركة (مرة واحدة)، من غير ما نلمس مسار التسجيل أو الـ OAuth
        if ($userId && !empty($_COOKIE['tf_lead_ref']) && class_exists('PlatformOutreachService')) {
            try {
                (new PlatformOutreachService())->attributeSignup((int) $userId, (string) $_COOKIE['tf_lead_ref']);
            } catch (Throwable $e) {
                if (class_exists('Logger')) {
                    Logger::warning('Lead attribution failed', ['message' => $e->getMessage()]);
                }
            }
            setcookie('tf_lead_ref', '', ['expires' => time() - 3600, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
        }

        $geo = class_exists('GeoIPService') ? GeoIPService::lookup($ip) : ['country' => null, 'city' => null];

        $db = Database::getInstance();
        $sql = "INSERT INTO visitor_logs
                    (visitor_id, user_id, session_id, page_url, referrer, ip_address, user_agent,
                     device_type, browser, platform, country, city, is_authenticated, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";

        $db->exec($sql, [
            $visitorId,
            $userId,
            session_id() ?: null,
            mb_substr($path, 0, 500),
            isset($_SERVER['HTTP_REFERER']) ? mb_substr($_SERVER['HTTP_REFERER'], 0, 500) : null,
            $ip,
            $userAgent,
            $device['device_type'],
            $device['browser'],
            $device['platform'] ?? null,
            $geo['country'] ?? null,
            $geo['city'] ?? null,
            $isAuthenticated,
        ]);
    }
}
