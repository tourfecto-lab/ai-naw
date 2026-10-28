<?php

/**
 * Tourfecto - Rate Limit Middleware
 * تحديد معدل الطلبات للحماية من الهجمات
 * @version 1.0.0
 * @author Tourfecto Team
 * @copyright 2026 Tourfecto
 */

class RateLimitMiddleware
{
    /**
     * @var RateLimiter $rateLimiter - نظام تحديد المعدل
     */
    private $rateLimiter;

    /**
     * @var array $limits - حدود المعدلات حسب المسارات
     */
    private $limits = [];

    /**
     * @var string $identifier - المعرف المستخدم للتحديد
     */
    private $identifier = '';

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->rateLimiter = new RateLimiter();
        $this->loadLimits();
        $this->resolveIdentifier();
    }

    /**
     * معالجة الطلب
     * @return array|null
     */
    public function handle(): ?array
    {
        // استثناء بعض المسارات
        if ($this->isExcludedRoute()) {
            return null;
        }

        // الحصول على حدود المسار الحالي
        $limit = $this->getRouteLimit();

        if (!$limit) {
            return null;
        }

        // التحقق من المعدل
        $result = $this->rateLimiter->checkWithDetails(
            $this->identifier,
            $limit['type'] ?? 'default',
            $limit['max'] ?? 100,
            $limit['window'] ?? 60
        );

        if (!$result['allowed']) {
            if (!headers_sent()) {
                http_response_code(429);
            }
            $retry = (int) ($result['reset_in'] ?? 60);
            // صفحة عادية في المتصفح: رسالة مفهومة بتحدّث نفسها، بدل كود JSON
            $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
            $accept = (string) ($_SERVER['HTTP_ACCEPT'] ?? '');
            if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && strpos($uri, '/api/') !== 0 && stripos($accept, 'text/html') !== false && !headers_sent()) {
                header('Content-Type: text/html; charset=utf-8');
                header('Retry-After: ' . $retry);
                $ar = (function_exists('current_lang') ? current_lang() : 'ar') === 'ar';
                $t = $ar ? ['لحظة واحدة…', 'الصفحة بتتفتح تاني لوحدها خلال ثواني.', 'حدّث الصفحة'] : ['One moment…', 'This page will reload by itself in a few seconds.', 'Reload'];
                $wait = max(3, min(60, $retry));
                echo '<!doctype html><html lang="' . ($ar ? 'ar' : 'en') . '" dir="' . ($ar ? 'rtl' : 'ltr') . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><meta http-equiv="refresh" content="' . $wait . '"><title>Tourfecto</title>'
                    . '<style>body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:#0b1220;color:#e8edf7;font-family:Tahoma,Arial,sans-serif;text-align:center;padding:16px}a{color:#EFB05E}</style></head>'
                    . '<body><div><h1 style="font-size:22px">' . $t[0] . '</h1><p style="color:#9aa6bd">' . $t[1] . '</p><p><a href="">' . $t[2] . '</a></p></div></body></html>';
                exit;
            }
            return [
                'success' => false,
                'error' => 'طلبات كتير أوي - من فضلك انتظر لحظة وحاول تاني',
                'code' => 429,
                'retry_after' => $retry,
                'limit' => $result['max'] ?? 0,
                'remaining' => 0
            ];
        }

        // إضافة رؤوس المعدل
        $this->addRateLimitHeaders($result);

        return null;
    }

    /**
     * تعيين حدود مخصصة لمسار معين
     * @param string $route
     * @param int $max
     * @param int $window
     * @param string $type
     * @return RateLimitMiddleware
     */
    public function setLimit(string $route, int $max, int $window = 60, string $type = 'default'): self
    {
        $this->limits[$route] = [
            'max' => $max,
            'window' => $window,
            'type' => $type
        ];
        return $this;
    }

    /**
     * تعيين معرف مخصص
     * @param string $identifier
     * @return RateLimitMiddleware
     */
    public function setIdentifier(string $identifier): self
    {
        $this->identifier = $identifier;
        return $this;
    }

    /**
     * تحديد المعرف المستخدم
     */
    private function resolveIdentifier(): void
    {
        if ($this->identifier) {
            return;
        }

        // استخدام API Key إذا كان موجوداً
        $apiKey = $this->getApiKey();
        if ($apiKey) {
            $this->identifier = 'api_' . $apiKey;
            return;
        }

        // استخدام معرف المستخدم إذا كان موجوداً
        // الميدل وير ده بيشتغل قبل AuthMiddleware، فـ auth_user_id لسه مش متحدد - الجلسة هي اللي بتعرّف المستخدم
        $userId = $_SERVER['auth_user_id'] ?? (session_status() === PHP_SESSION_ACTIVE ? ($_SESSION['user_id'] ?? null) : null);
        if ($userId) {
            $this->identifier = 'user_' . $userId;
            return;
        }

        // استخدام IP
        $this->identifier = 'ip_' . $this->getClientIP();
    }

    /**
     * الحصول على API Key من الطلب
     *
     * تصحيح أمني (2026-08-20): كان أي قيمة X-API-Key اتبعت على طول
     * كمعرّف لحظر المعدل (api_{key})، فكان أي حد يقدر يتجاوز الـ Rate
     * Limiter بالكامل بمجرد ما يبعت مفتاح جديد (زي "audit-12345") في
     * كل طلب - عشان مفيش تحقق من إن المفتاح ده حقيقي. الحل: بنقبل
     * المفتاح كمعرّف بس لو شكله مفتاح حقيقي معرّف في المنصة (المفاتيح
     * الشخصية بتبدأ بـ tf_pk_ والمفاتيح الشريكة بـ tf_live_). أي
     * قيمة عشوائية بتسقط لمعرّف الـ IP العادي فيبقى الحظر شغال.
     *
     * @return string|null
     */
    private function getApiKey(): ?string
    {
        $headers = getallheaders();

        $candidate = null;
        if (isset($headers['X-API-Key'])) {
            $candidate = $headers['X-API-Key'];
        } elseif (isset($_GET['api_key'])) {
            $candidate = $_GET['api_key'];
        }

        if ($candidate === null || $candidate === '') {
            return null;
        }

        // قيم مسرّبة من تطبيقات خارجية (زي "audit-…" أو "Bearer …") مش
        // مفاتيح حقيقية - متستخدمهاش كمعرّف أبدًا.
        if (strpos($candidate, 'tf_pk_') === 0 || strpos($candidate, 'tf_live_') === 0) {
            return $candidate;
        }

        return null;
    }

    /**
     * الحصول على IP العميل
     * @return string
     */
    private function getClientIP(): string
    {
        $ips = [
            $_SERVER['HTTP_CF_CONNECTING_IP'] ?? null,
            $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null,
            $_SERVER['HTTP_X_REAL_IP'] ?? null,
            $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'
        ];

        foreach ($ips as $ip) {
            if ($ip) {
                // في حالة وجود عدة IPs في X-Forwarded-For
                $ip = explode(',', $ip)[0];
                $ip = trim($ip);

                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        return '0.0.0.0';
    }

    /**
     * الحصول على حدود المسار الحالي
     * @return array|null
     */
    private function getRouteLimit(): ?array
    {
        $currentRoute = $this->normalizeApiVersion($_SERVER['REQUEST_URI'] ?? '');
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

        // البحث عن تطابق في الحدود المخصصة
        foreach ($this->limits as $route => $limit) {
            if (strpos($currentRoute, $route) !== false) {
                return $limit;
            }
        }

        // حدود افتراضية حسب الطريقة
        $defaultLimits = [
            'GET' => ['max' => 200, 'window' => 60, 'type' => 'read'],
            'POST' => ['max' => 50, 'window' => 60, 'type' => 'write'],
            'PUT' => ['max' => 30, 'window' => 60, 'type' => 'write'],
            'DELETE' => ['max' => 20, 'window' => 60, 'type' => 'write']
        ];

        // مستخدم مسجّل دخول: كل صفحة في اللوحة بتعمل 10-20 طلب API، فـ 200 في الدقيقة
        // كانت بتقفل العميل (429) لو اتنقل بين كام صفحة بسرعة. الحماية من الزوار المجهولين زي ما هي.
        if (strpos($this->identifier, 'user_') === 0 && $method === 'GET') {
            return ['max' => 600, 'window' => 60, 'type' => 'read'];
        }

        return $defaultLimits[$method] ?? ['max' => 100, 'window' => 60, 'type' => 'default'];
    }

    /**
     * إضافة رؤوس المعدل
     * @param array $result
     */
    protected function addRateLimitHeaders(array $result): void
    {
        if (headers_sent()) {
            return;
        }
        header('X-RateLimit-Limit: ' . ($result['max'] ?? 0));
        header('X-RateLimit-Remaining: ' . ($result['remaining'] ?? 0));
        header('X-RateLimit-Reset: ' . ($result['reset_in'] ?? 0));
    }

    /**
     * التحقق من استثناء المسار
     * @return bool
     */
    private function isExcludedRoute(): bool
    {
        $excludedRoutes = [
            '/health',
            '/ping',
            '/api/webhook',
            '/api/chat/webhook',
            '/api/review/webhook',
            // رابط تأكيد البريد والخروج: لازم يشتغلوا دايمًا (الرابط عليه حماية خاصة بيه في AuthController)
            '/verify-email/',
            '/logout',
        ];

        $currentRoute = $this->normalizeApiVersion($_SERVER['REQUEST_URI'] ?? '');

        foreach ($excludedRoutes as $route) {
            if (strpos($currentRoute, $route) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * تطبيع /api/v1/xxx إلى /api/xxx قبل مطابقة أنماط المسارات - نفس
     * الـ alias المطبّق في index.php لدعم API Versioning (راجع
     * PartnerAuthMiddleware / index.php للتفاصيل)، عشان حدود المعدل
     * المخصصة (زي /api/auth/login) تفضل شغالة برضه على /api/v1/auth/login.
     */
    private function normalizeApiVersion(string $uri): string
    {
        return preg_replace('#^/api/v(\d+)/#', '/api/', $uri) ?? $uri;
    }

    /**
     * تحميل الحدود الافتراضية
     */
    private function loadLimits(): void
    {
        $this->limits = [
            '/api/auth/login' => ['max' => 5, 'window' => 300, 'type' => 'auth'],
            '/api/auth/register' => ['max' => 3, 'window' => 3600, 'type' => 'auth'],
            '/api/auth/forgot-password' => ['max' => 3, 'window' => 3600, 'type' => 'auth'],
            '/api/auth/reset-password' => ['max' => 5, 'window' => 3600, 'type' => 'auth'],
            '/api/auth/resend-verification' => ['max' => 5, 'window' => 3600, 'type' => 'auth'],
            '/api/ai/analyze' => ['max' => 20, 'window' => 3600, 'type' => 'ai'],
            '/api/chat/send' => ['max' => 50, 'window' => 60, 'type' => 'chat'],
            '/api/review/webhook' => ['max' => 10, 'window' => 60, 'type' => 'webhook']
        ];
    }
}
