<?php

/**
 * Tourfecto - حماية CSRF عامة لكل الطلبات (فحص المصدر)
 *
 * أي طلب بيغيّر بيانات (POST/PUT/PATCH/DELETE) جاي من متصفح مسجّل دخول
 * (معتمد على كوكي الجلسة) لازم يكون جاي من نفس الموقع. ده أسلوب OWASP
 * "Verifying Origin With Standard Headers": المتصفحات الحديثة بتبعت Origin
 * مع أي POST، وموقع خارجي مايقدرش يزوّره.
 *
 *  - Origin موجود: لازم الهوست بتاعه = هوست الموقع (أو APP_URL أو PLATFORM_HOSTS).
 *  - Origin مش موجود: نبص على Referer بنفس الطريقة.
 *  - الاتنين مش موجودين: نسمح بس لو الطلب JSON أو فيه X-Requested-With
 *    (موقع خارجي مايقدرش يبعت دول من غير إذن CORS)، غير كده بنرفض.
 *
 * مابيتطبقش على:
 *  - طلبات من غير جلسة مسجّلة (مفيش حاجة تتسرق).
 *  - طلبات فيها Authorization (مفاتيح API / JWT - مش معتمدة على الكوكي).
 *  - الـ Webhooks والصفحات العامة اللي بتتنادى من برّه بطبيعتها
 *    (Apple callback، شات الموقع، فورمز CRM العامة، إلغاء الاشتراك، المواقع المولّدة).
 *
 * @version 1.0.0
 */
class RequestOriginGuard
{
    /** مسارات بتتنادى من برّه الموقع بطبيعتها (Webhooks / Widgets / OAuth callbacks) */
    private const EXEMPT = [
        '#/webhooks?(/|$)#i',
        '#^/auth/[a-z]+/callback$#',
        '#^/api/public/#',
        '#^/api/crm/public/#',
        '#^/api/crm/email-track#',
        '#^/api/email-marketing/unsubscribe/#',
        '#^/api/chat/send$#',
        '#^/api/v1/#',
        '#^/sites/#',
    ];

    /**
     * @return array|null null = مسموح، أو مصفوفة خطأ 403
     */
    public static function check(): ?array
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            return null;
        }
        // مفيش جلسة مسجّلة = مفيش صلاحيات تتستغل
        if (empty($_SESSION['user']) && empty($_SESSION['user_id'])) {
            return null;
        }
        if (!empty($_SERVER['HTTP_AUTHORIZATION']) || !empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            return null;
        }
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        foreach (self::EXEMPT as $re) {
            if (preg_match($re, $path)) {
                return null;
            }
        }

        $allowed = self::allowedHosts();
        $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
        if ($origin !== '' && $origin !== 'null') {
            return self::hostOk($origin, $allowed) ? null : self::deny($origin);
        }
        $referer = (string) ($_SERVER['HTTP_REFERER'] ?? '');
        if ($referer !== '') {
            return self::hostOk($referer, $allowed) ? null : self::deny($referer);
        }
        // من غير Origin ولا Referer (متصفحات قديمة/خصوصية): JSON أو XHR مايتبعتش من موقع تاني من غير CORS
        $ct = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? ''));
        if (strpos($ct, 'application/json') === 0 || !empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
            return null;
        }
        return self::deny('(none)');
    }

    private static function allowedHosts(): array
    {
        $hosts = [];
        $add = function ($h) use (&$hosts) {
            $h = strtolower(trim((string) $h));
            if ($h === '') {
                return;
            }
            $h = preg_replace('#^https?://#', '', $h);
            $h = explode('/', $h)[0];
            $hosts[$h] = true;
            $bare = preg_replace('/^www\./', '', $h);
            $hosts[$bare] = true;
            $hosts['www.' . $bare] = true;
        };
        $add($_SERVER['HTTP_HOST'] ?? '');
        $add($_SERVER['HTTP_X_FORWARDED_HOST'] ?? '');
        if (defined('APP_URL')) {
            $add(APP_URL);
        }
        $extra = function_exists('env') ? (string) (env('PLATFORM_HOSTS') ?: '') : '';
        foreach (explode(',', $extra) as $h) {
            $add($h);
        }
        return $hosts;
    }

    private static function hostOk(string $url, array $allowed): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $port = parse_url($url, PHP_URL_PORT);
        if ($host === '') {
            return false;
        }
        return isset($allowed[$host]) || ($port && isset($allowed[$host . ':' . $port]));
    }

    private static function deny(string $source): array
    {
        if (class_exists('Logger')) {
            try {
                Logger::warning('CSRF: request blocked (cross-site origin)', [
                    'path' => parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH),
                    'source' => mb_substr($source, 0, 120),
                    'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
                ]);
            } catch (Throwable $e) {
            }
        }
        http_response_code(403);
        return ['success' => false, 'error' => 'الطلب ده مرفوض لأنه جاي من موقع تاني. لو انت اللي عامله، حدّث الصفحة وجرّب تاني.', 'code' => 403];
    }
}
