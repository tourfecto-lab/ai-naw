<?php

/**
 * Tourfecto - WordPress Connector
 *
 * ربط موقع WordPress بأقل خطوات ممكنة:
 *  1) detect(): هل الموقع WordPress؟ وهل Application Passwords شغّال؟ وإيه رابط شاشة الموافقة الرسمية؟
 *  2) inspectUser(): تجربة بيانات الدخول (Application Password) وقراءة صلاحيات الحساب. حساب أدمن = إثبات ملكية.
 *
 * أمان:
 *  - كل طلب بيتفحص إن الـ host عام (مش IP داخلي) وبيتحمّل redirect يدوي لنفس الموقع بس (www / http→https)،
 *    فبيانات الدخول (Authorization) عمرها ما بتتبعت لموقع تاني.
 *  - بيانات الدخول بتتبعت بـ HTTPS بس.
 *  - الأخطاء بترجع أكواد (reason) مش رسائل خام، والباسورد عمره ما بيتسجّل.
 *
 * @version 1.0.0
 */
class WordPressConnector
{
    /** @var int مهلة الطلب (ثواني) */
    protected $timeout = 12;

    // ------------------------------------------------------------------ أدوات الروابط

    /** https://Example.com/path?x=1 → https://example.com (بيضيف https:// لو ناقصة) */
    public static function normalizeSiteUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        if (!preg_match('#^https?://#i', $url)) {
            $url = 'https://' . ltrim($url, '/');
        }
        $p = parse_url($url);
        if (!$p || empty($p['host'])) {
            return '';
        }
        return strtolower($p['scheme'] ?? 'https') . '://' . strtolower($p['host']) . (!empty($p['port']) ? ':' . $p['port'] : '');
    }

    private static function bareHost(string $host): string
    {
        $host = strtolower(trim($host));
        return preg_replace('/^www\./', '', $host);
    }

    /** نفس الموقع (بغض النظر عن www) */
    public static function hostsEqual(string $a, string $b): bool
    {
        return $a !== '' && $b !== '' && self::bareHost($a) === self::bareHost($b);
    }

    /** نفس الموقع أو subdomain منه (blog.example.com ↔ example.com) */
    public static function hostsRelated(string $a, string $b): bool
    {
        $a = self::bareHost($a);
        $b = self::bareHost($b);
        if ($a === '' || $b === '') {
            return false;
        }
        return $a === $b || substr($a, -strlen('.' . $b)) === '.' . $b || substr($b, -strlen('.' . $a)) === '.' . $a;
    }

    protected function requireHttps(): bool
    {
        return true;
    }

    protected function isPublicHost(string $host): bool
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ip = $host;
        } else {
            $ip = gethostbyname($host);
            if ($ip === $host) {
                return false;
            }
        }
        return (bool) filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    }

    protected function isPublicIp(string $ip): bool
    {
        return (bool) filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    }

    /**
     * فحص الهدف قبل أي اتصال: https + host عام + (اختياري) نفس دومين الموقع المضاف.
     * @return string|null كود الخطأ أو null لو سليم
     */
    public function checkTarget(string $siteUrl, ?string $websiteUrl = null, bool $requireSame = false): ?string
    {
        $siteUrl = self::normalizeSiteUrl($siteUrl);
        $p = parse_url($siteUrl);
        if (!$p || empty($p['host'])) {
            return 'not_wordpress';
        }
        if ($this->requireHttps() && ($p['scheme'] ?? '') !== 'https') {
            return 'not_https';
        }
        if ($websiteUrl !== null) {
            $wh = (string) (parse_url(self::normalizeSiteUrl($websiteUrl), PHP_URL_HOST) ?? '');
            $ok = $requireSame ? self::hostsEqual($p['host'], $wh) : self::hostsRelated($p['host'], $wh);
            if (!$ok) {
                return 'host_mismatch';
            }
        }
        if (!$this->isPublicHost($p['host'])) {
            return 'host_not_public';
        }
        return null;
    }

    // ------------------------------------------------------------------ HTTP آمن

    /**
     * GET واحد مع redirect يدوي (لنفس الموقع بس). الـ headers (زي Authorization) بتتبعت لنفس الموقع بس.
     * @return array{http:int, body:string, headers:array, error:?string, final_url:string}
     */
    protected function http(string $url, array $headers = [], int $maxBytes = 1048576): array
    {
        $startHost = (string) parse_url($url, PHP_URL_HOST);
        $current = $url;
        for ($hop = 0; $hop <= 3; $hop++) {
            $host = (string) parse_url($current, PHP_URL_HOST);
            if ($host === '' || !self::hostsEqual($host, $startHost)) {
                return ['http' => 0, 'body' => '', 'headers' => [], 'error' => 'redirect_other_host', 'final_url' => $current];
            }
            if (!$this->isPublicHost($host)) {
                return ['http' => 0, 'body' => '', 'headers' => [], 'error' => 'host_not_public', 'final_url' => $current];
            }

            $respHeaders = [];
            $body = '';
            $truncated = false;
            $ch = curl_init($current);
            curl_setopt_array($ch, [
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_TIMEOUT => $this->timeout,
                CURLOPT_CONNECTTIMEOUT => 6,
                CURLOPT_ENCODING => '',
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_USERAGENT => 'Tourfecto/1.0 (+https://tourfecto.pro)',
                CURLOPT_HTTPHEADER => array_merge(['Accept: application/json, text/html;q=0.8'], $headers),
                CURLOPT_HEADERFUNCTION => function ($c, $line) use (&$respHeaders) {
                    if (strpos($line, ':') !== false) {
                        [$k, $v] = explode(':', $line, 2);
                        $respHeaders[strtolower(trim($k))] = trim($v);
                    }
                    return strlen($line);
                },
                CURLOPT_WRITEFUNCTION => function ($c, $chunk) use (&$body, &$truncated, $maxBytes) {
                    $body .= $chunk;
                    if (strlen($body) > $maxBytes) {
                        $truncated = true;
                        return 0;
                    }
                    return strlen($chunk);
                },
            ]);
            curl_exec($ch);
            $errno = curl_errno($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $ip = (string) curl_getinfo($ch, CURLINFO_PRIMARY_IP);
            curl_close($ch);

            if ($ip !== '' && !$this->isPublicIp($ip)) {
                return ['http' => $code, 'body' => '', 'headers' => [], 'error' => 'host_not_public', 'final_url' => $current];
            }
            if ($errno && !($errno === 23 && $truncated)) {
                return ['http' => $code, 'body' => '', 'headers' => [], 'error' => 'network', 'final_url' => $current];
            }

            if ($code >= 300 && $code < 400 && !empty($respHeaders['location'])) {
                $next = $this->absoluteUrl($current, $respHeaders['location']);
                if ($next === '') {
                    return ['http' => $code, 'body' => '', 'headers' => $respHeaders, 'error' => 'network', 'final_url' => $current];
                }
                $current = $next;
                continue;
            }
            return ['http' => $code, 'body' => $body, 'headers' => $respHeaders, 'error' => null, 'final_url' => $current];
        }
        return ['http' => 0, 'body' => '', 'headers' => [], 'error' => 'network', 'final_url' => $current];
    }

    private function absoluteUrl(string $base, string $loc): string
    {
        if (preg_match('#^https?://#i', $loc)) {
            return $loc;
        }
        $p = parse_url($base);
        if (!$p || empty($p['host'])) {
            return '';
        }
        $origin = ($p['scheme'] ?? 'https') . '://' . $p['host'] . (!empty($p['port']) ? ':' . $p['port'] : '');
        if (strpos($loc, '//') === 0) {
            return ($p['scheme'] ?? 'https') . ':' . $loc;
        }
        if ($loc !== '' && $loc[0] === '/') {
            return $origin . $loc;
        }
        $dir = isset($p['path']) ? rtrim(substr($p['path'], 0, strrpos($p['path'], '/') + 1), '/') : '';
        return $origin . $dir . '/' . $loc;
    }

    // ------------------------------------------------------------------ 1) التعرف على الموقع

    /**
     * @return array{is_wordpress:bool, https:bool, rest_ok:bool, app_passwords:bool, authorize_url:?string, site_name:?string, signals:array, error:?string}
     */
    public function detect(string $siteUrl): array
    {
        $origin = self::normalizeSiteUrl($siteUrl);
        $out = ['is_wordpress' => false, 'https' => strpos($origin, 'https://') === 0, 'rest_ok' => false, 'app_passwords' => false,
            'authorize_url' => null, 'site_name' => null, 'signals' => [], 'error' => null];
        if ($origin === '') {
            $out['error'] = 'not_wordpress';
            return $out;
        }
        $p = parse_url($origin);
        if (!$this->isPublicHost((string) $p['host'])) {
            $out['error'] = 'host_not_public';
            return $out;
        }

        foreach ([$origin . '/wp-json/', $origin . '/?rest_route=/'] as $restUrl) {
            $r = $this->http($restUrl);
            if ($r['error']) {
                $out['error'] = $r['error'];
                continue;
            }
            $data = json_decode($r['body'], true);
            if ($r['http'] === 200 && is_array($data) && isset($data['namespaces']) && is_array($data['namespaces'])
                && in_array('wp/v2', $data['namespaces'], true)) {
                $out['is_wordpress'] = true;
                $out['rest_ok'] = true;
                $out['error'] = null;
                $out['signals'][] = 'rest_api';
                $out['site_name'] = isset($data['name']) ? (string) $data['name'] : null;
                $auth = $data['authentication']['application-passwords']['endpoints']['authorization'] ?? null;
                if (is_string($auth) && preg_match('#^https://#i', $auth)) {
                    $authHost = (string) parse_url($auth, PHP_URL_HOST);
                    // رابط الموافقة لازم يكون على نفس الموقع (حماية من موقع بيرجّع رابط لموقع تاني)
                    if (self::hostsEqual($authHost, (string) $p['host'])) {
                        $out['app_passwords'] = true;
                        $out['authorize_url'] = $auth;
                        $out['signals'][] = 'application_passwords';
                    }
                }
                return $out;
            }
            // الـ REST اتفتح لكن مش WordPress REST: نكمل على الإشارات
            if ($r['http'] === 401 || $r['http'] === 403) {
                $out['error'] = 'forbidden';
            }
        }

        // مفيش REST: ندوّر على إشارات في الصفحة الرئيسية
        $home = $this->http($origin . '/');
        if (!$home['error'] && $home['http'] >= 200 && $home['http'] < 400) {
            $html = substr($home['body'], 0, 400000);
            if (preg_match('#/wp-content/|/wp-includes/#i', $html)) {
                $out['signals'][] = 'wp_content';
            }
            if (preg_match('#<meta[^>]+name=["\']generator["\'][^>]+content=["\']WordPress#i', $html)) {
                $out['signals'][] = 'generator_meta';
            }
            if (!empty($out['signals'])) {
                $out['is_wordpress'] = true;
                $out['error'] = $out['error'] ?: 'rest_disabled';
            }
        }
        if (!$out['is_wordpress'] && !$out['error']) {
            $out['error'] = 'not_wordpress';
        }
        return $out;
    }

    // ------------------------------------------------------------------ 2) تجربة بيانات الدخول

    /**
     * @return array{success:bool, reason:string, http:int, user:?array, wp_code:string}
     *   reason: ok | wrong_password | wrong_username | bad_credentials | app_passwords_disabled | auth_header_stripped | unauthorized
     *           | rest_disabled | not_wordpress | forbidden | invalid_response | network | host_not_public | not_https | redirect_other_host
     */
    public function inspectUser(string $siteUrl, string $username, string $appPassword): array
    {
        $origin = self::normalizeSiteUrl($siteUrl);
        $fail = function (string $reason, int $http = 0, string $code = '') {
            return ['success' => false, 'reason' => $reason, 'http' => $http, 'user' => null, 'wp_code' => $code];
        };
        if ($origin === '') {
            return $fail('not_wordpress');
        }
        if ($this->requireHttps() && strpos($origin, 'https://') !== 0) {
            return $fail('not_https');
        }

        // مسافات الباسورد اختيارية عند WordPress، لكن بنشيل الأطراف بس
        $auth = 'Authorization: Basic ' . base64_encode($username . ':' . trim($appPassword));
        $last = null;
        foreach ([$origin . '/wp-json/wp/v2/users/me?context=edit', $origin . '/?rest_route=/wp/v2/users/me&context=edit'] as $url) {
            $r = $this->http($url, [$auth]);
            if ($r['error']) {
                return $fail($r['error'], $r['http']);
            }
            $data = json_decode($r['body'], true);
            if ($r['http'] === 200 && is_array($data) && isset($data['id'])) {
                $roles = is_array($data['roles'] ?? null) ? $data['roles'] : [];
                $caps = is_array($data['capabilities'] ?? null) ? $data['capabilities'] : [];
                $isAdmin = in_array('administrator', $roles, true) || !empty($caps['manage_options']);
                $canPublish = $isAdmin || !empty($caps['publish_posts']) || !empty($caps['edit_posts']);
                return [
                    'success' => true, 'reason' => 'ok', 'http' => 200, 'wp_code' => '',
                    'user' => [
                        'id' => (int) $data['id'], 'name' => (string) ($data['name'] ?? $username), 'slug' => (string) ($data['slug'] ?? ''),
                        'roles' => array_values($roles), 'is_admin' => $isAdmin, 'can_publish' => $canPublish,
                    ],
                ];
            }
            $last = $this->classifyFailure($r['http'], $data);
            // 404 على الطريقة الأولى (روابط ثابتة مقفولة أو مسار مختلف): نجرّب ?rest_route=
            if ($r['http'] !== 404 && !($data === null && $r['http'] === 200)) {
                break;
            }
        }
        $last = $last ?: $fail('invalid_response');

        // 401 بدون سبب صريح من WordPress عن بيانات الدخول: ممكن البيانات سليمة لكن Application Passwords مقفول على الموقع نفسه.
        // WordPress بيشيل رابط الموافقة من رد /wp-json/ لما الميزة مقفولة، فنفحصه هنا بدل ما نلوم العميل.
        if (in_array($last['reason'], ['unauthorized', 'auth_header_stripped'], true)) {
            $probe = $this->detect($origin);
            if ($probe['rest_ok'] && !$probe['app_passwords']) {
                $last['reason'] = 'app_passwords_disabled';
            }
        }
        return $last;
    }

    private function classifyFailure(int $http, $data): array
    {
        $code = is_array($data) ? (string) ($data['code'] ?? '') : '';
        $reason = 'forbidden';
        if ($code === 'incorrect_password') {
            $reason = 'wrong_password';
        } elseif (in_array($code, ['invalid_username', 'invalid_email'], true)) {
            $reason = 'wrong_username';
        } elseif ($code === 'authentication_failed') {
            $reason = 'bad_credentials';
        } elseif ($code === 'rest_not_logged_in') {
            $reason = 'auth_header_stripped';   // WordPress ما اتعرّفش على أي مستخدم: الهيدر ما وصلش أو الميزة مقفولة (بنفرّق بالفحص اللي بعده)
        } elseif (strpos($code, 'application_passwords_disabled') === 0) {
            $reason = 'app_passwords_disabled';
        } elseif ($http === 404) {
            $reason = ($code === 'rest_no_route' || $code === 'rest_disabled') ? 'rest_disabled' : 'not_wordpress';
        } elseif ($http === 401) {
            $reason = 'unauthorized';           // 401 غير معروف السبب: ما نقولش إن الباسورد غلط
        } elseif ($http === 403) {
            $reason = ($code === 'rest_forbidden' || $code === 'rest_disabled') ? 'rest_disabled' : 'forbidden';
        } elseif ($http === 200 || $http === 0) {
            $reason = 'invalid_response';
        }
        return ['success' => false, 'reason' => $reason, 'http' => $http, 'user' => null, 'wp_code' => preg_replace('/[^a-z0-9_\-]/i', '', $code)];
    }
}
