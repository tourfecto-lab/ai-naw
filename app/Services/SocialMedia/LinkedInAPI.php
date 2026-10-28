<?php

/**
 * Tourfecto - LinkedIn API - ربط بروفايل شخصي و/أو صفحات شركة، ونشر نص + صورة.
 *
 * OAuth 2.0: openid profile w_member_social (بروفايل شخصي)، و w_organization_social + r_organization_admin
 * لصفحات الشركة (محتاجة موافقة LinkedIn على Community Management API) - لو مش متاحة بنربط البروفايل بس.
 * النشر: POST /rest/posts بالـ Posts API (LinkedIn-Version شهري). الصورة: initializeUpload ← PUT للملف ← نحطها في المنشور.
 * الإعدادات: LINKEDIN_CLIENT_ID / LINKEDIN_CLIENT_SECRET / LINKEDIN_REDIRECT_URI / LINKEDIN_API_VERSION / LINKEDIN_ORG_SCOPES=1
 * @version 1.0.0
 */
final class LinkedInAPI
{
    /** @var callable|null fn(string $method, string $url, array $headers, ?string $body): array{code:int,body:string,headers:array,error:?string} */
    public static $transport = null;

    private string $token;

    public function __construct(string $accessToken)
    {
        $this->token = $accessToken;
    }

    public static function configured(): bool
    {
        return (string) env('LINKEDIN_CLIENT_ID', '') !== '' && (string) env('LINKEDIN_CLIENT_SECRET', '') !== '';
    }

    public static function version(): string
    {
        $v = (string) env('LINKEDIN_API_VERSION', '');
        // LinkedIn بتدعم كل إصدار شهري لمدة سنة تقريبًا: الافتراضي = من 3 شهور (آمن ومستقر)
        return preg_match('/^\d{6}$/', $v) ? $v : gmdate('Ym', strtotime('first day of -3 months'));
    }

    public static function scopes(): string
    {
        $s = ['openid', 'profile', 'w_member_social'];
        if ((string) env('LINKEDIN_ORG_SCOPES', '') === '1') {
            array_push($s, 'w_organization_social', 'r_organization_admin');
        }
        return implode(' ', $s);
    }

    public static function redirectUri(): string
    {
        return (string) (env('LINKEDIN_REDIRECT_URI') ?: rtrim(defined('APP_URL') ? (string) APP_URL : '', '/') . '/social/connect/linkedin/callback');
    }

    public static function authorizeUrl(string $state): string
    {
        return 'https://www.linkedin.com/oauth/v2/authorization?' . http_build_query([
            'response_type' => 'code',
            'client_id' => (string) env('LINKEDIN_CLIENT_ID', ''),
            'redirect_uri' => self::redirectUri(),
            'state' => $state,
            'scope' => self::scopes(),
        ]);
    }

    public static function exchangeCode(string $code): array
    {
        $r = self::call('POST', 'https://www.linkedin.com/oauth/v2/accessToken', ['Content-Type: application/x-www-form-urlencoded'], http_build_query([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'client_id' => (string) env('LINKEDIN_CLIENT_ID', ''),
            'client_secret' => (string) env('LINKEDIN_CLIENT_SECRET', ''),
            'redirect_uri' => self::redirectUri(),
        ]));
        if (!$r['success'] || empty($r['data']['access_token'])) {
            return ['success' => false, 'error' => $r['error'] ?? 'LinkedIn token exchange failed'];
        }
        return ['success' => true, 'access_token' => (string) $r['data']['access_token'], 'expires_in' => (int) ($r['data']['expires_in'] ?? 5184000), 'refresh_token' => $r['data']['refresh_token'] ?? null, 'scope' => (string) ($r['data']['scope'] ?? '')];
    }

    /** البروفايل الشخصي (OpenID userinfo) */
    public function me(): array
    {
        $r = self::call('GET', 'https://api.linkedin.com/v2/userinfo', ['Authorization: Bearer ' . $this->token]);
        return $r['success'] ? ['success' => true, 'urn' => 'urn:li:person:' . (string) ($r['data']['sub'] ?? ''), 'name' => (string) ($r['data']['name'] ?? 'LinkedIn')] : $r;
    }

    /** صفحات الشركات اللي المستخدم أدمن عليها (لو صلاحيات الشركات متاحة) */
    public function organizations(): array
    {
        $r = self::call('GET', 'https://api.linkedin.com/rest/organizationAcls?q=roleAssignee&role=ADMINISTRATOR&state=APPROVED', $this->restHeaders());
        if (!$r['success']) {
            return [];
        }
        $out = [];
        foreach ((array) ($r['data']['elements'] ?? []) as $el) {
            $urn = (string) ($el['organization'] ?? '');
            if (!preg_match('/^urn:li:organization:(\d+)$/', $urn, $m)) {
                continue;
            }
            $org = self::call('GET', 'https://api.linkedin.com/rest/organizations/' . $m[1], $this->restHeaders());
            $out[] = ['urn' => $urn, 'name' => (string) ($org['data']['localizedName'] ?? ('Company ' . $m[1]))];
        }
        return $out;
    }

    /**
     * نص LinkedIn بصيغة "little text": الرموز المحجوزة لازم يتعملها escape وإلا المنشور بيتقطع أو يترفض.
     * الهاشتاجات بتتحول لصيغة {hashtag|\#|tag} عشان تبقى قابلة للضغط. (نقية - بتتختبر)
     */
    public static function littleText(string $text): string
    {
        $tags = [];
        $text = (string) preg_replace_callback('/#([\p{L}\p{N}_]+)/u', static function ($m) use (&$tags) {
            $key = "\u{E000}" . count($tags) . "\u{E001}";
            $tags[$key] = '{hashtag|\\#|' . $m[1] . '}';
            return $key;
        }, $text);
        $text = (string) preg_replace('/([\\\\|{}@\[\]()<>#*_~])/u', '\\\\$1', $text);
        return strtr($text, $tags);
    }

    /** نشر منشور نصي (و/أو صورة) باسم $authorUrn (person أو organization). بيرجّع post_id (urn) */
    public function publish(string $authorUrn, string $text, ?string $imagePath = null): array
    {
        $body = [
            'author' => $authorUrn,
            'commentary' => self::littleText(mb_substr($text, 0, 3000)),
            'visibility' => 'PUBLIC',
            'distribution' => ['feedDistribution' => 'MAIN_FEED', 'targetEntities' => [], 'thirdPartyDistributionChannels' => []],
            'lifecycleState' => 'PUBLISHED',
            'isReshareDisabledByAuthor' => false,
        ];
        if ($imagePath) {
            $img = $this->uploadImage($authorUrn, $imagePath);
            if (!$img['success']) {
                return $img;
            }
            $body['content'] = ['media' => ['id' => $img['urn']]];
        }
        $r = self::call('POST', 'https://api.linkedin.com/rest/posts', array_merge($this->restHeaders(), ['Content-Type: application/json']), json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        if (!$r['success']) {
            return $r;
        }
        $id = (string) ($r['headers']['x-restli-id'] ?? $r['data']['id'] ?? '');
        return $id !== '' ? ['success' => true, 'post_id' => $id] : ['success' => false, 'error' => 'LinkedIn did not return a post id', 'definitive' => false];
    }

    private function uploadImage(string $ownerUrn, string $path): array
    {
        if (!is_file($path) || filesize($path) > 20 * 1024 * 1024) {
            return ['success' => false, 'error' => 'الصورة مش موجودة أو أكبر من 20 ميجا', 'definitive' => true];
        }
        $init = self::call('POST', 'https://api.linkedin.com/rest/images?action=initializeUpload', array_merge($this->restHeaders(), ['Content-Type: application/json']), json_encode(['initializeUploadRequest' => ['owner' => $ownerUrn]]));
        $url = (string) ($init['data']['value']['uploadUrl'] ?? '');
        $urn = (string) ($init['data']['value']['image'] ?? '');
        if (!$init['success'] || $url === '' || $urn === '') {
            return ['success' => false, 'error' => $init['error'] ?? 'LinkedIn image upload init failed', 'definitive' => $init['definitive'] ?? false];
        }
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if (parse_url($url, PHP_URL_SCHEME) !== 'https' || !preg_match('/(^|\.)linkedin\.com$|(^|\.)licdn\.com$/', $host)) {
            return ['success' => false, 'error' => 'Untrusted LinkedIn upload host', 'definitive' => true];
        }
        $put = self::call('PUT', $url, ['Authorization: Bearer ' . $this->token, 'Content-Type: application/octet-stream'], (string) file_get_contents($path));
        if (!$put['success']) {
            return $put;
        }
        return ['success' => true, 'urn' => $urn];
    }

    private function restHeaders(): array
    {
        return ['Authorization: Bearer ' . $this->token, 'LinkedIn-Version: ' . self::version(), 'X-Restli-Protocol-Version: 2.0.0'];
    }

    private static function call(string $method, string $url, array $headers, ?string $body = null): array
    {
        if (self::$transport) {
            $res = (self::$transport)($method, $url, $headers, $body);
        } else {
            $respHeaders = [];
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_HTTPHEADER => array_merge(['Accept: application/json'], $headers),
                CURLOPT_HEADERFUNCTION => static function ($c, string $line) use (&$respHeaders): int {
                    $p = strpos($line, ':');
                    if ($p !== false) {
                        $respHeaders[strtolower(trim(substr($line, 0, $p)))] = trim(substr($line, $p + 1));
                    }
                    return strlen($line);
                },
            ]);
            if ($body !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            }
            $out = curl_exec($ch);
            $res = ['code' => (int) curl_getinfo($ch, CURLINFO_HTTP_CODE), 'body' => is_string($out) ? $out : '', 'headers' => $respHeaders, 'error' => $out === false ? curl_error($ch) : null];
            curl_close($ch);
        }
        if (!empty($res['error'])) {
            return ['success' => false, 'error' => 'Network error: ' . $res['error'], 'definitive' => false];
        }
        $data = json_decode((string) $res['body'], true);
        if ($res['code'] >= 200 && $res['code'] < 300) {
            return ['success' => true, 'data' => is_array($data) ? $data : [], 'headers' => (array) ($res['headers'] ?? [])];
        }
        $msg = is_array($data) ? (string) ($data['message'] ?? $data['error_description'] ?? $data['error'] ?? 'HTTP ' . $res['code']) : 'HTTP ' . $res['code'];
        return ['success' => false, 'error' => 'LinkedIn: ' . $msg, 'definitive' => $res['code'] >= 400 && $res['code'] < 500, 'http_code' => $res['code']];
    }
}
