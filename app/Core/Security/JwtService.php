<?php

/**
 * Tourfecto - JWT Service
 * تنفيذ ذاتي بسيط لتوقيع JWT بخوارزمية HS256، بدون أي مكتبة خارجية
 * (composer.json الحالي معندوش أي مكتبة JWT، وإضافة composer require
 * محتاجة SSH على السيرفر - غير متاح حسب التعليقات في index.php).
 * HS256 قياسي 100% ومتوافق مع أي مكتبة JWT تانية لو احتجتوا تتحققوا
 * منه بره PHP لاحقًا.
 *
 * الاستخدام:
 *   $token = JwtService::issue(['sub' => $userId, 'type' => 'access'], 900);
 *   $payload = JwtService::verify($token); // null لو غير صالح/منتهي
 *
 * @version 1.0.0
 */

class JwtService
{
    /**
     * توليد JWT موقّع.
     * @param array $claims بيانات إضافية (زي 'sub' لـ user id، 'type' لنوع التوكن)
     * @param int $ttlSeconds مدة الصلاحية بالثواني
     * @return string
     */
    public static function issue(array $claims, int $ttlSeconds): string
    {
        $header = self::base64UrlEncode(json_encode([
            'typ' => 'JWT',
            'alg' => 'HS256',
        ]));

        $now = time();
        $payload = self::base64UrlEncode(json_encode(array_merge($claims, [
            'iat' => $now,
            'exp' => $now + $ttlSeconds,
            'iss' => 'tourfecto',
        ]), JSON_UNESCAPED_UNICODE));

        $signature = self::sign("$header.$payload");

        return "$header.$payload.$signature";
    }

    /**
     * التحقق من JWT وإرجاع الـ payload لو صالح، أو null لو مش صالح/منتهي/
     * موقّع بمفتاح غلط. بيستخدم hash_equals لمقارنة التوقيع (مقاوم لـ
     * timing attacks - نفس مبدأ password_verify في PartnerApiKey).
     * @return array|null
     */
    public static function verify(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }
        [$header, $payload, $signature] = $parts;

        $expectedSignature = self::sign("$header.$payload");
        if (!hash_equals($expectedSignature, $signature)) {
            return null;
        }

        $decoded = json_decode(self::base64UrlDecode($payload), true);
        if (!is_array($decoded)) {
            return null;
        }

        if (!isset($decoded['exp']) || time() >= (int) $decoded['exp']) {
            return null; // منتهي الصلاحية
        }

        return $decoded;
    }

    private static function sign(string $data): string
    {
        $secret = defined('JWT_SECRET') ? JWT_SECRET : '';
        return self::base64UrlEncode(hash_hmac('sha256', $data, $secret, true));
    }

    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $data): string
    {
        $padded = str_pad($data, strlen($data) % 4 === 0 ? strlen($data) : strlen($data) + (4 - strlen($data) % 4), '=');
        return base64_decode(strtr($padded, '-_', '+/')) ?: '';
    }
}
