<?php

/**
 * Tourfecto - Device Detector
 * تحليل الـ User Agent لاستخراج نوع الجهاز والمتصفح ونظام التشغيل
 * يُستخدم في سجل تسجيل الدخول (login_history) وتتبع الزوار (visitor_logs)
 * @version 1.0.0
 * @author Tourfecto Team
 * @copyright 2026 Tourfecto
 */

class DeviceDetector
{
    /**
     * تحليل الـ User Agent بالكامل
     * @param string $userAgent
     * @return array ['device_type' => ..., 'browser' => ..., 'platform' => ...]
     */
    public static function parse(string $userAgent): array
    {
        return [
            'device_type' => self::detectDeviceType($userAgent),
            'browser' => self::detectBrowser($userAgent),
            'platform' => self::detectPlatform($userAgent),
        ];
    }

    /**
     * تحديد نوع الجهاز
     * @param string $ua
     * @return string
     */
    public static function detectDeviceType(string $ua): string
    {
        if ($ua === '' || $ua === 'Unknown') {
            return 'unknown';
        }

        if (preg_match('/bot|crawl|spider|slurp|facebookexternalhit/i', $ua)) {
            return 'bot';
        }

        if (preg_match('/tablet|ipad/i', $ua)) {
            return 'tablet';
        }

        if (preg_match('/mobile|android|iphone|ipod|blackberry|windows phone/i', $ua)) {
            return 'mobile';
        }

        return 'desktop';
    }

    /**
     * تحديد اسم المتصفح
     * @param string $ua
     * @return string
     */
    public static function detectBrowser(string $ua): string
    {
        $browsers = [
            'Edg' => 'Edge',
            'OPR' => 'Opera',
            'Opera' => 'Opera',
            'Chrome' => 'Chrome',
            'CriOS' => 'Chrome (iOS)',
            'Firefox' => 'Firefox',
            'FxiOS' => 'Firefox (iOS)',
            'Safari' => 'Safari',
            'MSIE' => 'Internet Explorer',
            'Trident' => 'Internet Explorer',
        ];

        foreach ($browsers as $needle => $name) {
            if (stripos($ua, $needle) !== false) {
                return $name;
            }
        }

        return 'Unknown';
    }

    /**
     * تحديد نظام التشغيل
     * @param string $ua
     * @return string
     */
    public static function detectPlatform(string $ua): string
    {
        $platforms = [
            'Windows NT 10' => 'Windows 10/11',
            'Windows NT 6.3' => 'Windows 8.1',
            'Windows NT 6.2' => 'Windows 8',
            'Windows NT 6.1' => 'Windows 7',
            'Windows' => 'Windows',
            'Mac OS X' => 'macOS',
            'iPhone' => 'iOS',
            'iPad' => 'iPadOS',
            'Android' => 'Android',
            'Linux' => 'Linux',
            'CrOS' => 'ChromeOS',
        ];

        foreach ($platforms as $needle => $name) {
            if (stripos($ua, $needle) !== false) {
                return $name;
            }
        }

        return 'Unknown';
    }
}
