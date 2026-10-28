<?php

/**
 * Tourfecto - Competitor Intelligence: SSRF Guard
 * @version 1.0.0
 *
 * الموديول ده بيتعامل مع URLs خارجية أدخلها المستخدم (مواقع منافسين)
 * ويعمل عليها HTTP requests من السيرفر - ده بالتعريف سطح هجوم SSRF
 * كلاسيكي (URL يشاور على 127.0.0.1 / 169.254.169.254 / شبكة داخلية).
 *
 * نفس منطق الفحص الموجود بالفعل في WebsiteController::isPubliclyRoutableHost()
 * (لحماية فحص ملكية الموقع)، اتنقل هنا لكلاس مشترك قابل لإعادة الاستخدام
 * بدل تكراره، ومُوسَّع بفحص الـ scheme ومنع منافذ غير http/https.
 */
class SsrfGuard
{
    /** @var string[] Metadata / link-local endpoints يجب منعها دائمًا حتى لو عدّت فحص الـ IP range العام */
    private const BLOCKED_HOSTS = [
        'metadata.google.internal',
        'instance-data',
        'metadata',
        'localhost',
    ];

    /**
     * فحص شامل: هل الـ URL آمن نطلبه من السيرفر؟
     * @param string $url
     * @return array ['safe' => bool, 'reason' => string|null, 'host' => string|null]
     */
    public static function validateUrl(string $url): array
    {
        $url = trim($url);

        if ($url === '') {
            return ['safe' => false, 'reason' => 'empty_url', 'host' => null];
        }

        $parts = parse_url($url);
        if (!$parts || empty($parts['host'])) {
            return ['safe' => false, 'reason' => 'unparseable_url', 'host' => null];
        }

        $scheme = strtolower($parts['scheme'] ?? '');
        if (!in_array($scheme, ['http', 'https'], true)) {
            return ['safe' => false, 'reason' => 'unsupported_scheme', 'host' => $parts['host']];
        }

        $host = strtolower($parts['host']);

        if (in_array($host, self::BLOCKED_HOSTS, true)) {
            return ['safe' => false, 'reason' => 'blocked_host', 'host' => $host];
        }

        if (!self::isPubliclyRoutableHost($host)) {
            return ['safe' => false, 'reason' => 'private_or_unresolvable_host', 'host' => $host];
        }

        // منع منافذ غير قياسية اللي غالبًا بتستخدم للوصول لخدمات داخلية
        // (مثال: 22 SSH، 3306 MySQL، 6379 Redis...) - نسمح فقط بالمنافذ
        // القياسية لـ HTTP/HTTPS أو من غير منفذ محدد أصلاً.
        if (isset($parts['port']) && !in_array((int) $parts['port'], [80, 443, 8080, 8443], true)) {
            return ['safe' => false, 'reason' => 'blocked_port', 'host' => $host];
        }

        return ['safe' => true, 'reason' => null, 'host' => $host];
    }

    public static function isSafe(string $url): bool
    {
        return self::validateUrl($url)['safe'];
    }

    /**
     * بنفس منطق WebsiteController::isPubliclyRoutableHost() - بيحل اسم
     * الدومين لكل سجلاته (IPv4 + IPv6) ويتأكد إن كل IP فعلي
     * مش private/reserved/loopback range، عشان محدش يقدر يستخدم
     * "أضف منافس" كوسيلة يخلي السيرفر يطلب من شبكته الداخلية أو
     * Cloud metadata endpoint.
     *
     * بنفحص كل السجلات مش أول واحد بس: لو الدومين عنده A record عام
     * و AAAA record خاص (مثال: fc00::/7 أو ::1) الـ curl هيتصل عبر IPv6
     * لو متاح - فبمنع أي دومين أي سجل من سجلاته خاص. فشل الـ resolution
     * بالكامل = مرفوض (fail-closed).
     */
    public static function isPubliclyRoutableHost(string $host): bool
    {
        $host = rtrim(strtolower(trim($host)), '.');

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return self::isPublicIp($host);
        }

        $ips = self::resolveAllIps($host);
        if (empty($ips)) {
            return false; // مش قابل للـ resolution أو كل السجلات فاضية
        }

        foreach ($ips as $ip) {
            if (!self::isPublicIp($ip)) {
                return false; // أي سجل واحد خاص = الدومين كله مرفوض
            }
        }

        return true;
    }

    /** هل IP (IPv4 أو IPv6) عنوان عام حقيقي؟ */
    private static function isPublicIp(string $ip): bool
    {
        // IPv4-mapped IPv6 (::ffff:192.168.1.1) - بنفك التغليف ونفحص الـ
        // IPv4 الداخلي، لأن filter_var مش بيراعي حالة الـ mapping دي.
        if (preg_match('/^::ffff:(\d+\.\d+\.\d+\.\d+)$/i', $ip, $m)) {
            return self::isPublicIp($m[1]);
        }

        return (bool) filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    }

    /**
     * بيجمع كل عناوين IP (A + AAAA) للدومين بأكثر طريقة متاحة، مع
     * fallback تدريجي لو `dns_get_record` متعطّلة في البيئة (disable_functions).
     * @return string[]
     */
    private static function resolveAllIps(string $host): array
    {
        $ips = [];

        if (function_exists('dns_get_record')) {
            $records = @dns_get_record($host, DNS_A | DNS_AAAA);
            if (is_array($records)) {
                foreach ($records as $r) {
                    foreach (['ip', 'ipv6'] as $field) {
                        if (isset($r[$field]) && filter_var($r[$field], FILTER_VALIDATE_IP)) {
                            $ips[] = $r[$field];
                        }
                    }
                }
            }
        }

        if (empty($ips) && function_exists('gethostbynamel')) {
            $v4 = @gethostbynamel($host);
            if (is_array($v4)) {
                foreach ($v4 as $ip) {
                    if (filter_var($ip, FILTER_VALIDATE_IP)) {
                        $ips[] = $ip;
                    }
                }
            }
        }

        if (empty($ips)) {
            $one = @gethostbyname($host);
            if ($one !== $host && filter_var($one, FILTER_VALIDATE_IP)) {
                $ips[] = $one;
            }
        }

        return array_values(array_unique($ips));
    }

    /**
     * تطبيع URL لصفحة فرعية (مثال: إضافة /pricing) مع الحفاظ على نفس
     * الـ scheme/host الأساسي للمنافس، لمنع أي محاولة لتمرير URL كامل
     * مختلف (host مختلف) عبر باراميتر page_type.
     */
    public static function buildSubPageUrl(string $baseUrl, string $path): ?string
    {
        $base = parse_url($baseUrl);
        if (!$base || empty($base['host']) || empty($base['scheme'])) {
            return null;
        }
        $port = isset($base['port']) ? ':' . $base['port'] : '';
        return $base['scheme'] . '://' . $base['host'] . $port . '/' . ltrim($path, '/');
    }
}
