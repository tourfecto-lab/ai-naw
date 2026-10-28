<?php
/**
 * Tourfecto - CSRF Protection
 * نظام حماية من هجمات CSRF (Cross-Site Request Forgery)
 * @version 1.0.0
 * @author Tourfecto Team
 * @copyright 2026 Tourfecto
 */

class CSRFProtection {
    /**
     * @var Database $db - اتصال قاعدة البيانات
     */
    private $db;
    
    /**
     * @var Session $session - نظام الجلسات
     */
    private $session;
    
    /**
     * @var string $tokenName - اسم التوكن
     */
    private $tokenName = 'csrf_token';
    
    /**
     * @var int $tokenLifetime - عمر التوكن (ثانية)
     */
    private $tokenLifetime = 3600;
    
    /**
     * @var array $excludedRoutes - المسارات المستثناة
     */
    private $excludedRoutes = [];
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->db = Database::getInstance();
        $this->session = new Session();
        $this->loadExcludedRoutes();
    }
    
    /**
     * توليد توكن CSRF
     * @return string
     */
    public function generateToken(): string {
        $token = bin2hex(random_bytes(32));
        
        // تخزين التوكن في الجلسة
        $this->session->set($this->tokenName, $token);
        
        // تخزين في قاعدة البيانات مع وقت الانتهاء
        $sql = "INSERT INTO csrf_tokens 
                (token, expires_at, created_at) 
                VALUES 
                (:token, DATE_ADD(NOW(), INTERVAL :lifetime SECOND), NOW())";
        
        $this->db->query($sql, [
            ':token' => $token,
            ':lifetime' => $this->tokenLifetime
        ]);
        
        return $token;
    }
    
    /**
     * الحصول على توكن CSRF الحالي
     * @return string|null
     */
    public function getToken(): ?string {
        $token = $this->session->get($this->tokenName);
        
        if (!$token) {
            $token = $this->generateToken();
        }
        
        return $token;
    }
    
    /**
     * التحقق من صحة التوكن
     * @param string $token
     * @return bool
     */
    public function validateToken(string $token): bool {
        // استثناء بعض المسارات
        if ($this->isRouteExcluded()) {
            return true;
        }
        
        // التحقق من التوكن في الجلسة
        $sessionToken = $this->session->get($this->tokenName);
        if (!$sessionToken || !hash_equals($sessionToken, $token)) {
            return false;
        }
        
        // التحقق من التوكن في قاعدة البيانات
        $sql = "SELECT id FROM csrf_tokens 
                WHERE token = :token 
                AND expires_at > NOW() 
                LIMIT 1";
        
        $result = $this->db->query($sql, [':token' => $token]);
        
        if (empty($result)) {
            return false;
        }
        
        // حذف التوكن بعد الاستخدام (One-time use)
        $this->invalidateToken($token);
        
        return true;
    }
    
    /**
     * إبطال التوكن
     * @param string $token
     * @return bool
     */
    public function invalidateToken(string $token): bool {
        $sql = "DELETE FROM csrf_tokens WHERE token = :token";
        $result = $this->db->query($sql, [':token' => $token]);
        
        $this->session->delete($this->tokenName);
        
        return $result !== false;
    }
    
    /**
     * التحقق من الطلب
     * @param string $method
     * @param string $token
     * @return bool
     */
    public function validateRequest(string $method = 'POST', ?string $token = null): bool {
        // الطرق الآمنة لا تحتاج للتحقق
        $safeMethods = ['GET', 'HEAD', 'OPTIONS', 'TRACE'];
        if (in_array(strtoupper($method), $safeMethods)) {
            return true;
        }
        
        // استثناء بعض المسارات
        if ($this->isRouteExcluded()) {
            return true;
        }
        
        // الحصول على التوكن من الطلب
        if ($token === null) {
            $token = $_POST[$this->tokenName] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
        }
        
        if ($token === null) {
            Logger::warning('CSRF Token Missing', [
                'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
                'uri' => $_SERVER['REQUEST_URI'] ?? null
            ]);
            return false;
        }
        
        return $this->validateToken($token);
    }
    
    /**
     * الحصول على حقل CSRF المخفي
     * @return string
     */
    public function getHiddenField(): string {
        $token = $this->getToken();
        return '<input type="hidden" name="' . $this->tokenName . '" value="' . $token . '">';
    }
    
    /**
     * الحصول على Meta Tag CSRF
     * @return string
     */
    public function getMetaTag(): string {
        $token = $this->getToken();
        return '<meta name="csrf-token" content="' . $token . '">';
    }
    
    /**
     * الحصول على Header CSRF
     * @return string
     */
    public function getHeader(): string {
        $token = $this->getToken();
        return 'X-CSRF-Token: ' . $token;
    }
    
    /**
     * إضافة JavaScript للـ AJAX
     * @return string
     */
    public function getAjaxScript(): string {
        $token = $this->getToken();
        return <<<JS
        <script>
        (function() {
            var token = '{$token}';
            var xhr = XMLHttpRequest;
            XMLHttpRequest.prototype.originalOpen = XMLHttpRequest.prototype.open;
            XMLHttpRequest.prototype.open = function() {
                var result = this.originalOpen.apply(this, arguments);
                this.setRequestHeader('X-CSRF-Token', token);
                return result;
            };
            
            // دعم fetch
            if (window.fetch) {
                var originalFetch = window.fetch;
                window.fetch = function() {
                    var options = arguments[1] || {};
                    options.headers = options.headers || {};
                    options.headers['X-CSRF-Token'] = token;
                    return originalFetch.apply(this, arguments);
                };
            }
        })();
        </script>
JS;
    }
    
    /**
     * إضافة التوكن إلى URL
     * @param string $url
     * @return string
     */
    public function addTokenToUrl(string $url): string {
        $token = $this->getToken();
        $separator = strpos($url, '?') === false ? '?' : '&';
        return $url . $separator . $this->tokenName . '=' . $token;
    }
    
    /**
     * تنظيف التوكنات المنتهية
     * @return int
     */
    public function cleanExpiredTokens(): int {
        $sql = "DELETE FROM csrf_tokens WHERE expires_at < NOW()";
        return (int) $this->db->query($sql);
    }
    
    /**
     * التحقق من استثناء المسار
     * @return bool
     */
    private function isRouteExcluded(): bool {
        $currentRoute = $_SERVER['REQUEST_URI'] ?? '';
        
        foreach ($this->excludedRoutes as $pattern) {
            if (strpos($currentRoute, $pattern) !== false) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * تحميل المسارات المستثناة
     */
    private function loadExcludedRoutes(): void {
        $this->excludedRoutes = [
            '/api/webhook',
            '/api/chat/webhook',
            '/api/review/webhook',
            '/health',
            '/ping'
        ];
    }
    
    /**
     * إضافة مسار مستثنى
     * @param string $route
     */
    public function addExcludedRoute(string $route): void {
        $this->excludedRoutes[] = $route;
    }
    
    /**
     * الحصول على إحصائيات التوكنات
     * @return array
     */
    public function getTokenStats(): array {
        $sql = "SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN expires_at > NOW() THEN 1 ELSE 0 END) as active,
                    SUM(CASE WHEN expires_at <= NOW() THEN 1 ELSE 0 END) as expired
                FROM csrf_tokens";
        
        $result = $this->db->query($sql);
        
        if (empty($result)) {
            return [
                'total' => 0,
                'active' => 0,
                'expired' => 0
            ];
        }
        
        return [
            'total' => (int) $result[0]['total'],
            'active' => (int) $result[0]['active'],
            'expired' => (int) $result[0]['expired']
        ];
    }
}

/**
 * Class Session - نظام الجلسات البسيط (داخل نفس الملف)
 */
class Session {
    /**
     * Constructor
     */
    public function __construct() {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }
    
    /**
     * تعيين قيمة في الجلسة
     * @param string $key
     * @param mixed $value
     */
    public function set(string $key, $value): void {
        $_SESSION[$key] = $value;
    }
    
    /**
     * الحصول على قيمة من الجلسة
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function get(string $key, $default = null) {
        return $_SESSION[$key] ?? $default;
    }
    
    /**
     * حذف قيمة من الجلسة
     * @param string $key
     */
    public function delete(string $key): void {
        unset($_SESSION[$key]);
    }
    
    /**
     * تدمير الجلسة
     */
    public function destroy(): void {
        session_destroy();
        $_SESSION = [];
    }
}