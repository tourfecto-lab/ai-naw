<?php

/**
 * Tourfecto - Logging Middleware
 * تسجيل جميع الطلبات والاستجابات للتحليل والمراقبة
 * @version 1.0.0
 * @author Tourfecto Team
 * @copyright 2026 Tourfecto
 */

class LoggingMiddleware
{
    /**
     * @var Logger $logger - نظام التسجيل
     */
    private $logger;

    /**
     * @var array $excludedPaths - المسارات المستثناة
     */
    private $excludedPaths = [];

    /**
     * @var array $sensitiveFields - الحقول الحساسة
     */
    private $sensitiveFields = [
        'password', 'token', 'api_key', 'secret', 'credit_card', 'cvv'
    ];

    /**
     * @var float $startTime - وقت بدء الطلب
     */
    private $startTime;

    /**
     * @var string $requestId - معرف الطلب الفريد
     */
    private $requestId;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->logger = new Logger();
        $this->startTime = microtime(true);
        $this->requestId = $this->generateRequestId();
        $this->loadExcludedPaths();
    }

    /**
     * معالجة الطلب
     * @return array|null
     */
    public function handle(): ?array
    {
        // تسجيل بداية الطلب
        $this->logRequest();

        // تسجيل عند الانتهاء
        register_shutdown_function([$this, 'logResponse']);

        return null;
    }

    /**
     * تسجيل الطلب
     */
    public function logRequest(): void
    {
        // استثناء بعض المسارات
        if ($this->isExcluded()) {
            return;
        }

        $requestData = [
            'request_id' => $this->requestId,
            'method' => $_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN',
            'uri' => $_SERVER['REQUEST_URI'] ?? '/',
            'ip' => $this->getClientIP(),
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
            'referer' => $_SERVER['HTTP_REFERER'] ?? null,
            'query' => $this->sanitizeData($_GET),
            'body' => $this->getRequestBody(),
            'headers' => $this->getRequestHeaders(),
            'timestamp' => date('Y-m-d H:i:s')
        ];

        // إضافة بيانات المستخدم إذا كانت موجودة
        if (isset($_SERVER['auth_user_id'])) {
            $requestData['user_id'] = $_SERVER['auth_user_id'];
        }

        $this->logger->info('Request', $requestData);
    }

    /**
     * تسجيل الاستجابة
     */
    public function logResponse(): void
    {
        // استثناء بعض المسارات
        if ($this->isExcluded()) {
            return;
        }

        $duration = (microtime(true) - $this->startTime) * 1000;

        $responseData = [
            'request_id' => $this->requestId,
            'duration_ms' => round($duration, 2),
            'status_code' => http_response_code(),
            'timestamp' => date('Y-m-d H:i:s')
        ];

        $this->logger->info('Response', $responseData);

        // تسجيل الاستعلامات البطيئة
        if ($duration > 1000) {
            $this->logger->warning('Slow Request', [
                'request_id' => $this->requestId,
                'duration_ms' => $duration,
                'uri' => $_SERVER['REQUEST_URI'] ?? '/'
            ]);
        }
    }

    /**
     * الحصول على نص الطلب
     * @return array|string
     */
    private function getRequestBody()
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

        if (in_array($method, ['POST', 'PUT', 'PATCH'])) {
            $contentType = $_SERVER['CONTENT_TYPE'] ?? '';

            if (strpos($contentType, 'application/json') !== false) {
                $input = file_get_contents('php://input');
                $data = json_decode($input, true);
                return $this->sanitizeData($data ?: $input);
            }

            return $this->sanitizeData($_POST);
        }

        return [];
    }

    /**
     * الحصول على رؤوس الطلب
     * @return array
     */
    private function getRequestHeaders(): array
    {
        $headers = getallheaders();

        // إخفاء الرؤوس الحساسة
        $sensitiveHeaders = ['Authorization', 'Cookie', 'X-API-Key'];
        foreach ($sensitiveHeaders as $header) {
            if (isset($headers[$header])) {
                $headers[$header] = 'REDACTED';
            }
        }

        return $headers;
    }

    /**
     * تنظيف البيانات الحساسة
     * @param mixed $data
     * @return mixed
     */
    private function sanitizeData($data)
    {
        if (is_array($data)) {
            $sanitized = [];
            foreach ($data as $key => $value) {
                // التحقق من الحقول الحساسة
                $isSensitive = false;
                foreach ($this->sensitiveFields as $field) {
                    if (stripos($key, $field) !== false) {
                        $isSensitive = true;
                        break;
                    }
                }

                if ($isSensitive) {
                    $sanitized[$key] = 'REDACTED';
                } elseif (is_array($value)) {
                    $sanitized[$key] = $this->sanitizeData($value);
                } else {
                    // قص النصوص الطويلة
                    if (is_string($value) && strlen($value) > 1000) {
                        $sanitized[$key] = substr($value, 0, 1000) . '... [TRUNCATED]';
                    } else {
                        $sanitized[$key] = $value;
                    }
                }
            }
            return $sanitized;
        }

        return $data;
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
     * توليد معرف طلب فريد
     * @return string
     */
    private function generateRequestId(): string
    {
        return uniqid('req_', true) . '_' . substr(md5(microtime()), 0, 8);
    }

    /**
     * التحقق من استثناء المسار
     * @return bool
     */
    private function isExcluded(): bool
    {
        $currentPath = $_SERVER['REQUEST_URI'] ?? '';

        foreach ($this->excludedPaths as $path) {
            if (strpos($currentPath, $path) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * تحميل المسارات المستثناة
     */
    private function loadExcludedPaths(): void
    {
        $this->excludedPaths = [
            '/health',
            '/ping',
            '/favicon.ico',
            '/robots.txt'
        ];
    }

    /**
     * إضافة مسار مستثنى
     * @param string $path
     */
    public function addExcludedPath(string $path): void
    {
        $this->excludedPaths[] = $path;
    }

    /**
     * الحصول على إحصائيات الطلبات
     * @return array
     */
    public function getStats(): array
    {
        // يمكن توسيع هذا لجلب إحصائيات من قاعدة البيانات
        return [
            'request_id' => $this->requestId,
            'start_time' => $this->startTime,
            'duration' => (microtime(true) - $this->startTime) * 1000
        ];
    }
}
