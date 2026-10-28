<?php

/**
 * Tourfecto - CORS Middleware
 * إعدادات Cross-Origin Resource Sharing
 * @version 1.0.0
 * @author Tourfecto Team
 * @copyright 2026 Tourfecto
 */

class CORSMiddleware
{
    /**
     * @var array $allowedOrigins - النطاقات المسموحة
     */
    private $allowedOrigins = [];

    /**
     * @var array $allowedMethods - الطرق المسموحة
     */
    private $allowedMethods = [];

    /**
     * @var array $allowedHeaders - الرؤوس المسموحة
     */
    private $allowedHeaders = [];

    /**
     * @var array $exposedHeaders - الرؤوس المكشوفة
     */
    private $exposedHeaders = [];

    /**
     * @var int $maxAge - مدة التخزين المؤقت
     */
    private $maxAge = 86400;

    /**
     * @var bool $allowCredentials - السماح بالشهادات
     */
    private $allowCredentials = true;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->allowedOrigins = ALLOWED_ORIGINS ?? ['*'];
        $this->allowedMethods = CORS_ALLOWED_METHODS ?? ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS', 'PATCH'];
        $this->allowedHeaders = CORS_ALLOWED_HEADERS ?? ['Content-Type', 'Authorization', 'X-Requested-With', 'Accept', 'Origin'];
        $this->exposedHeaders = ['X-RateLimit-Limit', 'X-RateLimit-Remaining', 'X-RateLimit-Reset'];
    }

    /**
     * معالجة الطلب
     * @return array|null
     */
    public function handle(): ?array
    {
        $origin = $this->getOrigin();

        // التحقق من النطاق
        if ($this->isOriginAllowed($origin)) {
            $this->setCORSHeaders($origin);
        }

        // معالجة طلبات OPTIONS (Preflight)
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(200);
            exit;
        }

        return null;
    }

    /**
     * تعيين النطاقات المسموحة
     * @param array $origins
     * @return CORSMiddleware
     */
    public function setAllowedOrigins(array $origins): self
    {
        $this->allowedOrigins = $origins;
        return $this;
    }

    /**
     * تعيين الطرق المسموحة
     * @param array $methods
     * @return CORSMiddleware
     */
    public function setAllowedMethods(array $methods): self
    {
        $this->allowedMethods = $methods;
        return $this;
    }

    /**
     * تعيين الرؤوس المسموحة
     * @param array $headers
     * @return CORSMiddleware
     */
    public function setAllowedHeaders(array $headers): self
    {
        $this->allowedHeaders = $headers;
        return $this;
    }

    /**
     * تعيين الرؤوس المكشوفة
     * @param array $headers
     * @return CORSMiddleware
     */
    public function setExposedHeaders(array $headers): self
    {
        $this->exposedHeaders = $headers;
        return $this;
    }

    /**
     * تعيين مدة التخزين المؤقت
     * @param int $maxAge
     * @return CORSMiddleware
     */
    public function setMaxAge(int $maxAge): self
    {
        $this->maxAge = $maxAge;
        return $this;
    }

    /**
     * تعيين السماح بالشهادات
     * @param bool $allow
     * @return CORSMiddleware
     */
    public function setAllowCredentials(bool $allow): self
    {
        $this->allowCredentials = $allow;
        return $this;
    }

    /**
     * الحصول على Origin من الطلب
     * @return string|null
     */
    private function getOrigin(): ?string
    {
        return $_SERVER['HTTP_ORIGIN'] ?? null;
    }

    /**
     * التحقق من السماح للنطاق
     * @param string|null $origin
     * @return bool
     */
    private function isOriginAllowed(?string $origin): bool
    {
        if (!$origin) {
            return false;
        }

        if (in_array('*', $this->allowedOrigins)) {
            return true;
        }

        foreach ($this->allowedOrigins as $allowed) {
            if ($this->matchOrigin($origin, $allowed)) {
                return true;
            }
        }

        return false;
    }

    /**
     * مطابقة النطاق مع النطاق المسموح
     * @param string $origin
     * @param string $allowed
     * @return bool
     */
    private function matchOrigin(string $origin, string $allowed): bool
    {
        // مطابقة تامة
        if ($origin === $allowed) {
            return true;
        }

        // مطابقة مع wildcard
        if (strpos($allowed, '*') !== false) {
            $pattern = str_replace('\*', '.*', preg_quote($allowed, '/'));
            return preg_match('/^' . $pattern . '$/', $origin) === 1;
        }

        // مطابقة النطاق الفرعي
        if (strpos($allowed, '://') !== false) {
            $parts = parse_url($allowed);
            $host = $parts['host'] ?? '';
            $scheme = $parts['scheme'] ?? 'https';

            if ($host && strpos($host, '.') !== false) {
                $pattern = '/^' . preg_quote($scheme, '/') . ':\/\/([^\/]*\.)?' . preg_quote($host, '/') . '(\/.*)?$/';
                return preg_match($pattern, $origin) === 1;
            }
        }

        return false;
    }

    /**
     * تعيين رؤوس CORS
     * @param string $origin
     */
    private function setCORSHeaders(string $origin): void
    {
        // Allow Origin
        header('Access-Control-Allow-Origin: ' . $origin);

        // Allow Credentials
        if ($this->allowCredentials) {
            header('Access-Control-Allow-Credentials: true');
        }

        // Allow Methods
        if (!empty($this->allowedMethods)) {
            header('Access-Control-Allow-Methods: ' . implode(', ', $this->allowedMethods));
        }

        // Allow Headers
        if (!empty($this->allowedHeaders)) {
            header('Access-Control-Allow-Headers: ' . implode(', ', $this->allowedHeaders));
        }

        // Expose Headers
        if (!empty($this->exposedHeaders)) {
            header('Access-Control-Expose-Headers: ' . implode(', ', $this->exposedHeaders));
        }

        // Max Age
        if ($this->maxAge > 0) {
            header('Access-Control-Max-Age: ' . $this->maxAge);
        }

        // Vary
        header('Vary: Origin');
    }

    /**
     * إضافة رؤوس CORS الإضافية
     * @param array $headers
     */
    public function addCustomHeaders(array $headers): void
    {
        foreach ($headers as $name => $value) {
            header($name . ': ' . $value);
        }
    }
}
