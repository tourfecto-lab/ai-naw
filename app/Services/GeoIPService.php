<?php

/**
 * Tourfecto - GeoIP Service
 * تحديد الموقع الجغرافي التقريبي لعنوان IP (الدولة/المدينة/المنطقة/الإحداثيات)
 * يُستخدم في سجل تسجيل الدخول (login_history) وتتبع الزوار (visitor_logs)
 *
 * ملاحظة: يعتمد على ip-api.com (نسخة مجانية بدون مفتاح، بحد أقصى 45 طلب/دقيقة).
 * النتائج تُخزَّن كاش لمدة 24 ساعة لكل IP لتقليل عدد الطلبات الخارجية.
 * أي فشل في الاتصال لا يجب أن يوقف تسجيل الدخول أو تتبع الزيارة، لذلك كل شيء
 * هنا محاط بـ try/catch ويرجع مصفوفة فارغة آمنة عند الفشل.
 *
 * @version 1.0.0
 * @author Tourfecto Team
 * @copyright 2026 Tourfecto
 */

class GeoIPService
{
    /** @var int مهلة الاتصال بالثواني */
    private const TIMEOUT = 2;

    /** @var int مدة الكاش بالثواني (24 ساعة) */
    private const CACHE_TTL = 86400;

    /**
     * الحصول على بيانات الموقع الجغرافي لعنوان IP معيّن (مع كاش)
     * @param string $ip
     * @return array{country:?string, city:?string, region:?string, latitude:?float, longitude:?float}
     */
    public static function lookup(string $ip): array
    {
        $empty = [
            'country' => null,
            'city' => null,
            'region' => null,
            'latitude' => null,
            'longitude' => null,
        ];

        if (!self::isPublicIp($ip)) {
            return array_merge($empty, ['country' => 'Local/Private']);
        }

        try {
            if (class_exists('Cache')) {
                $cache = new Cache();
                return $cache->remember('geoip_' . md5($ip), function () use ($ip, $empty) {
                    return self::fetch($ip) ?? $empty;
                }, self::CACHE_TTL);
            }

            return self::fetch($ip) ?? $empty;
        } catch (Throwable $e) {
            if (class_exists('Logger')) {
                Logger::warning('GeoIP lookup failed', ['ip' => $ip, 'message' => $e->getMessage()]);
            }
            return $empty;
        }
    }

    /**
     * تنفيذ طلب الـ HTTP الفعلي لخدمة GeoIP الخارجية
     * @param string $ip
     * @return array|null
     */
    private static function fetch(string $ip): ?array
    {
        $url = 'http://ip-api.com/json/' . urlencode($ip) . '?fields=status,country,regionName,city,lat,lon';

        $context = stream_context_create([
            'http' => [
                'timeout' => self::TIMEOUT,
                'ignore_errors' => true,
            ],
        ]);

        $response = @file_get_contents($url, false, $context);

        if ($response === false) {
            return null;
        }

        $data = json_decode($response, true);

        if (!is_array($data) || ($data['status'] ?? '') !== 'success') {
            return null;
        }

        return [
            'country' => $data['country'] ?? null,
            'city' => $data['city'] ?? null,
            'region' => $data['regionName'] ?? null,
            'latitude' => isset($data['lat']) ? (float) $data['lat'] : null,
            'longitude' => isset($data['lon']) ? (float) $data['lon'] : null,
        ];
    }

    /**
     * التحقق من أن الـ IP عام (مش لوكال/داخلي) قبل عمل طلب خارجي عليه
     * @param string $ip
     * @return bool
     */
    private static function isPublicIp(string $ip): bool
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }

        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }
}
