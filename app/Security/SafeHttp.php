<?php

/**
 * Tourfecto - طلبات HTTP آمنة لروابط بيكتبها العميل (حماية SSRF)
 *
 * فحص المواقع والزحف بيطلبوا روابط العميل من السيرفر. من غير حماية، أي عميل يقدر
 * يحط رابط (أو رابط بيحوّل بـ redirect) لعنوان داخلي زي 127.0.0.1 أو 169.254.169.254
 * أو قاعدة البيانات، والسيرفر هيطلبه ويرجّع النتيجة.
 *
 * هنا:
 *  - http/https بس، وبورتات الويب العادية.
 *  - الدومين لازم كل عناوينه تكون IP عامة (مش داخلية/محجوزة).
 *  - بنثبّت الـ IP اللي اتفحص (CURLOPT_RESOLVE) عشان DNS مايتغيّرش بين الفحص والطلب.
 *  - الـ redirects بنمشي فيها بنفسنا ونفحص كل خطوة.
 *
 * للتطوير المحلي بس: SSRF_ALLOW_PRIVATE=true في .env
 */
class SafeHttp
{
    private const PORTS = [80, 443, 8080, 8443];

    /** @return array{ok:bool, ip?:string, host?:string, port?:int, error?:string} */
    public static function check(string $url): array
    {
        $p = parse_url($url);
        $scheme = strtolower((string) ($p['scheme'] ?? ''));
        $host = strtolower(trim((string) ($p['host'] ?? ''), '[]'));
        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            return ['ok' => false, 'error' => 'رابط غير صالح'];
        }
        $port = (int) ($p['port'] ?? ($scheme === 'https' ? 443 : 80));
        if (!in_array($port, self::PORTS, true)) {
            return ['ok' => false, 'error' => 'البورت ده مش مسموح'];
        }
        if (self::allowPrivate()) {
            return ['ok' => true, 'host' => $host, 'port' => $port];
        }
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ips = [$host];
        } else {
            if ($host === 'localhost' || substr($host, -6) === '.local' || substr($host, -9) === '.internal') {
                return ['ok' => false, 'error' => 'عنوان داخلي'];
            }
            $ips = @gethostbynamel($host) ?: [];
            foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $r) {
                if (!empty($r['ipv6'])) {
                    $ips[] = $r['ipv6'];
                }
            }
            if (!$ips) {
                return ['ok' => false, 'error' => 'الدومين مش بيرد (DNS)'];
            }
        }
        foreach ($ips as $ip) {
            if (!self::isPublicIp($ip)) {
                return ['ok' => false, 'error' => 'الرابط بيشاور على عنوان داخلي'];
            }
        }
        return ['ok' => true, 'ip' => $ips[0], 'host' => $host, 'port' => $port];
    }

    public static function isPublicIp(string $ip): bool
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }
        // نطاقات مش مغطية في الفلتر: CGNAT 100.64/10، والـ IPv4 المتحطة جوه IPv6
        if (preg_match('/^100\.(6[4-9]|[7-9]\d|1[01]\d|12[0-7])\./', $ip)) {
            return false;
        }
        if (stripos($ip, '::ffff:') === 0) {
            return self::isPublicIp(substr($ip, 7));
        }
        return true;
    }

    /**
     * ينفّذ طلب cURL بنفس الخيارات اللي كانت مستخدمة، لكن بيمشي الـ redirects بنفسه ويفحص كل خطوة.
     * @return array{response:string|false, code:int, error:string, redirects:int, header_size:int, url:string}
     */
    public static function exec(string $url, array $opts, int $maxRedirects = 5): array
    {
        unset($opts[CURLOPT_FOLLOWLOCATION], $opts[CURLOPT_MAXREDIRS]);
        $redirects = 0;
        while (true) {
            $c = self::check($url);
            if (!$c['ok']) {
                return ['response' => false, 'code' => 0, 'error' => 'blocked: ' . $c['error'], 'redirects' => $redirects, 'header_size' => 0, 'url' => $url];
            }
            $ch = curl_init($url);
            $hop = $opts + [CURLOPT_RETURNTRANSFER => true];
            $hop[CURLOPT_FOLLOWLOCATION] = false;
            if (defined('CURLOPT_PROTOCOLS')) {
                $hop[CURLOPT_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
            }
            if (!empty($c['ip'])) {
                $ip = strpos($c['ip'], ':') !== false ? '[' . $c['ip'] . ']' : $c['ip'];
                $hop[CURLOPT_RESOLVE] = [$c['host'] . ':' . $c['port'] . ':' . $ip];
            }
            curl_setopt_array($ch, $hop);
            $response = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            $next = (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL);
            curl_close($ch);
            if (in_array($code, [301, 302, 303, 307, 308], true) && $next !== '' && $redirects < $maxRedirects) {
                $url = $next;
                $redirects++;
                continue;
            }
            return ['response' => $response, 'code' => $code, 'error' => $error, 'redirects' => $redirects, 'header_size' => $headerSize, 'url' => $url];
        }
    }

    private static function allowPrivate(): bool
    {
        $v = function_exists('env') ? env('SSRF_ALLOW_PRIVATE', false) : getenv('SSRF_ALLOW_PRIVATE');
        return $v === true || in_array(strtolower((string) $v), ['1', 'true', 'yes'], true);
    }
}
