<?php

/**
 * Tourfecto - Partner Auth Middleware
 * مصادقة مستقلة تمامًا عن AuthMiddleware (اللي مصمم لجلسة/توكن المستخدم
 * العادي). دي بوابة الدخول لشركاء خارجيين (Partner API): تتحقق من
 * X-API-Key، تتأكد إن المفتاح نشط ومعاه الصلاحية (scope) المطلوبة،
 * وتطبّق حد معدل طلبات خاص بكل مفتاح (مش نفس حد المستخدم العادي).
 *
 * الاستخدام في الـ routes بنفس أسلوب SubscriptionMiddleware الحالي:
 *   $router->get('/api/partner/x', 'PartnerController', 'x',
 *       ['PartnerAuthMiddleware:reputation:read']);
 *
 * @version 1.0.0
 */

class PartnerAuthMiddleware
{
    /** @var string|null $requiredScope - الصلاحية المطلوبة لهذا المسار */
    private $requiredScope = null;

    /** @var PartnerApiKey|null $partner - سجل الشريك بعد التحقق بنجاح */
    private $partner = null;

    /**
     * دعم صيغة 'PartnerAuthMiddleware:scope_name' من الـ Router
     * (نفس آلية applyModifier المستخدمة بالفعل في SubscriptionMiddleware)
     */
    public function applyModifier(string $modifier): void
    {
        $this->requiredScope = $modifier;
    }

    public function handle(): ?array
    {
        $rawKey = $this->getKeyFromRequest();

        if (!$rawKey) {
            return $this->reject(401, 'Missing X-API-Key header');
        }

        $partner = PartnerApiKey::verify($rawKey);
        if (!$partner) {
            return $this->reject(401, 'Invalid or revoked API key');
        }

        if ($this->requiredScope && !$partner->hasScope($this->requiredScope)) {
            return $this->reject(403, "API key does not have the required scope: {$this->requiredScope}");
        }

        $rateLimitResult = $this->checkRateLimit($partner, $rawKey);
        if ($rateLimitResult !== null) {
            return $rateLimitResult;
        }

        $this->partner = $partner;
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $partner->touchUsage($ip);

        // إتاحة بيانات الشريك للـ Controller (نفس أسلوب $_SERVER['auth_user'] في AuthMiddleware)
        $_SERVER['auth_partner'] = $partner->toPublicArray();
        $_SERVER['auth_partner_id'] = $partner->getAttribute('id');

        return null;
    }

    /**
     * حد معدل طلبات خاص بكل مفتاح على حدة (rate_limit_per_minute بتاعه
     * هو، مش حد ثابت للجميع زي RateLimitMiddleware العادي) - عشان شريك
     * كبير يقدر ياخد حد أعلى من شريك تجريبي بدون أي تغيير في الكود.
     */
    private function checkRateLimit(PartnerApiKey $partner, string $rawKey): ?array
    {
        if (!class_exists('RateLimiter')) {
            return null;
        }

        try {
            $limiter = new RateLimiter();
            $maxPerMinute = (int) $partner->getAttribute('rate_limit_per_minute');
            $allowed = $limiter->checkApiKey('partner:' . $partner->getAttribute('key_prefix'), $maxPerMinute, 60);

            if (!$allowed) {
                return $this->reject(429, 'Rate limit exceeded for this API key');
            }
        } catch (Throwable $e) {
            // تجاهل فشل حد المعدل نفسه - أولوية استمرار خدمة الشريك أعلى
            // من دقة العداد لو حصل عطل مؤقت في نظام الـ Rate Limiting
        }

        return null;
    }

    private function getKeyFromRequest(): ?string
    {
        $headers = function_exists('getallheaders') ? (getallheaders() ?: []) : [];
        $key = $headers['X-API-Key'] ?? ($headers['x-api-key'] ?? null);

        if (!$key && isset($_SERVER['HTTP_X_API_KEY'])) {
            $key = $_SERVER['HTTP_X_API_KEY'];
        }

        return $key ? trim($key) : null;
    }

    private function reject(int $code, string $message): array
    {
        http_response_code($code);
        return [
            'success' => false,
            'error' => $message,
            'code' => $code,
        ];
    }

    public function getPartner(): ?PartnerApiKey
    {
        return $this->partner;
    }
}
