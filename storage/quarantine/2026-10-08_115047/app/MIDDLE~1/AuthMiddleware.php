<?php

/**
 * Tourfecto - Auth Middleware
 * التحقق من مصادقة المستخدم والصلاحيات
 * @version 1.0.0
 * @author Tourfecto Team
 * @copyright 2026 Tourfecto
 */

class AuthMiddleware
{
    /**
     * @var Database $db - اتصال قاعدة البيانات
     */
    private $db;

    /**
     * @var array $user - بيانات المستخدم الحالي
     */
    private $user = [];

    /**
     * @var bool $authenticated - حالة المصادقة
     */
    private $authenticated = false;

    /**
     * @var array $requiredRoles - الأدوار المطلوبة
     */
    private $requiredRoles = [];

    /**
     * @var array $requiredPermissions - الصلاحيات المطلوبة
     */
    private $requiredPermissions = [];

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * معالجة الطلب
     * @return array|null
     */
    public function handle(): ?array
    {
        // استثناء مسارات معينة
        if ($this->isPublicRoute()) {
            return null;
        }

        // الحصول على التوكن من الطلب
        $token = $this->getTokenFromRequest();
        $user = $token ? $this->validateToken($token) : false;

        // تصحيح: لو مفيش توكن صالح (كوكي auth_token ضاعت أو مش متطابقة)،
        // لكن فيه سيشن ويب فيها user_id، نحاول نصادق بالسيشن كـ fallback
        // بدل ما نرفض الطلب فورًا. ده بيمنع حلقة تحويل لا نهائية بين
        // /login و /dashboard في حالة أي عدم تزامن بين الكوكي والسيشن،
        // وكمان بيعيد ضبط الكوكي تلقائيًا (self-healing) عشان الطلبات
        // الجاية (زي API) تشتغل عادي بعد كده.
        if (!$user && !empty($_SESSION['user_id'])) {
            try {
                $userModel = new User();
                $sessionUser = $userModel->find((int) $_SESSION['user_id']);

                if ($sessionUser && $sessionUser->getAttribute('status') === 'active') {
                    $user = $sessionUser->toArray();

                    $dbToken = $sessionUser->getAttribute('api_token');
                    if ($dbToken && ($_COOKIE['auth_token'] ?? null) !== $dbToken) {
                        setcookie('auth_token', $dbToken, [
                            'expires' => time() + (int) (defined('SESSION_LIFETIME') ? SESSION_LIFETIME : 3600),
                            'path' => '/',
                            'httponly' => true,
                            'samesite' => 'Strict',
                        ]);
                    }
                }
            } catch (Throwable $e) {
                // تجاهل: لو فشل ده، هيكمل يترفض تحت عادي زي أي طلب غير مصادَق
            }
        }

        if (!$user) {
            return $this->unauthorized('Authentication token required');
        }

        // تخزين بيانات المستخدم
        $this->user = $user;
        $this->authenticated = true;

        // التحقق من الأدوار المطلوبة
        if (!empty($this->requiredRoles)) {
            if (!$this->hasRequiredRole()) {
                return $this->forbidden('Insufficient role permissions');
            }
        }

        // التحقق من الصلاحيات المطلوبة
        if (!empty($this->requiredPermissions)) {
            if (!$this->hasRequiredPermissions()) {
                return $this->forbidden('Insufficient permissions');
            }
        }

        // إضافة المستخدم إلى الطلب
        $this->addUserToRequest();

        return null;
    }

    /**
     * التحقق من وجود صلاحية معينة
     * @param string $permission
     * @return AuthMiddleware
     */
    public function requirePermission(string $permission): self
    {
        $this->requiredPermissions[] = $permission;
        return $this;
    }

    /**
     * التحقق من وجود دور معين
     * @param string $role
     * @return AuthMiddleware
     */
    public function requireRole(string $role): self
    {
        $this->requiredRoles[] = $role;
        return $this;
    }

    /**
     * التحقق من وجود أي من الأدوار المطلوبة
     * @param array $roles
     * @return AuthMiddleware
     */
    public function requireAnyRole(array $roles): self
    {
        $this->requiredRoles = array_merge($this->requiredRoles, $roles);
        return $this;
    }

    /**
     * الحصول على بيانات المستخدم الحالي
     * @return array
     */
    public function getUser(): array
    {
        return $this->user;
    }

    /**
     * التحقق من المصادقة
     * @return bool
     */
    public function isAuthenticated(): bool
    {
        return $this->authenticated;
    }

    /**
     * الحصول على التوكن من الطلب
     * @return string|null
     */
    private function getTokenFromRequest(): ?string
    {
        // من Header Authorization
        $headers = getallheaders();
        $authHeader = $headers['Authorization'] ?? '';

        if (strpos($authHeader, 'Bearer ') === 0) {
            return substr($authHeader, 7);
        }

        // من Cookie
        if (isset($_COOKIE['auth_token'])) {
            return $_COOKIE['auth_token'];
        }

        // Security hardening: authentication tokens are intentionally NOT accepted
        // from query strings or form fields. URLs/form payloads are more likely to
        // leak into logs, browser history, analytics and referrer headers.

        return null;
    }

    /**
     * التحقق من صحة التوكن
     * @param string $token
     * @return array|false
     */
    private function validateToken(string $token)
    {
        try {
            // دعم JWT (Bearer access token) - المرحلة 2 من خطة API Gateway.
            // بنميّزه عن التوكن القديم بشكل التوكن نفسه: JWT دايمًا 3 أجزاء
            // مفصولة بنقطتين (header.payload.signature)، والتوكن القديم
            // (api_token) سلسلة عشوائية واحدة من غير نقط. لو الشكل مطابق
            // لـ JWT، نتحقق منه بالتوقيع بدل ما ندوّر عليه في العمود
            // api_token (مش هيتطابق أبدًا لإنه مش مخزّن هناك أصلاً).
            if (substr_count($token, '.') === 2 && class_exists('JwtService')) {
                return $this->validateJwtToken($token);
            }

            // دعم مفاتيح API الشخصية الجديدة (Settings > API & Integrations،
            // Phase 3) - مميّزة بالبادئة tf_pk_ عشان نفرقها فورًا عن
            // api_token القديم (سلسلة عشوائية من غير بادئة) من غير ما
            // نحتاج نستعلم قاعدة البيانات لكل طلب لمجرد نتأكد من النوع.
            if (class_exists('UserApiKey') && UserApiKey::looksLikeUserApiKey($token)) {
                $keyRecord = UserApiKey::verify($token);
                if ($keyRecord === null) {
                    return false;
                }

                $userModel = new User();
                $owner = $userModel->find((int) $keyRecord->getAttribute('user_id'));
                if ($owner === null || $owner->getAttribute('status') !== 'active') {
                    return false;
                }

                $keyRecord->touchUsage();

                // نعرّف سياق المفتاح في الطلب عشان أي Middleware/Controller
                // يقدر يفرض الصلاحيات (Scopes) على الطلبات الجاية بمفتاح
                // معيّن. NULL/فاضي = وصول كامل (توافق خلفي).
                $_SERVER['auth_api_key_id'] = (int) $keyRecord->getAttribute('id');
                $_SERVER['auth_api_key_scopes'] = $keyRecord->getAttribute('scopes');
                $_SERVER['auth_method'] = 'api_key';

                return $owner->toArray();
            }

            // التحقق من التوكن في قاعدة البيانات
            // تصحيح: لا يوجد عمود is_active في جدول users الفعلي، العمود
            // الحقيقي هو status (enum: active/suspended/...)، فكان هذا
            // الاستعلام يفشل دايمًا ويرجّع false، فيُعتبر أي مستخدم غير
            // مسجل دخول ويُعاد توجيهه لـ /login بلا نهاية.
            $sql = "SELECT * FROM users 
                    WHERE api_token = :token 
                    AND status = 'active' 
                    LIMIT 1";

            $result = $this->db->query($sql, [':token' => $token]);

            if (empty($result)) {
                return false;
            }

            $user = $result[0];

            // التحقق من صلاحية التوكن
            if ($this->isTokenExpired($user)) {
                return false;
            }

            // تحديث آخر نشاط
            $this->updateLastActivity($user['id']);

            return $user;

        } catch (Exception $e) {
            Logger::error('Token Validation Error', [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * التحقق من access token على شكل JWT: التوقيع، الصلاحية، وإن
     * صاحب التوكن (claim 'sub') لسه حساب فعّال. الصلاحية والتوقيع
     * بيتفحصوا جوه JwtService::verify نفسها (بترجع null لو أي منهم
     * غلط)، فمفيش داعي نكررهم هنا.
     */
    private function validateJwtToken(string $token)
    {
        $payload = JwtService::verify($token);
        if (!$payload || ($payload['type'] ?? null) !== 'access' || empty($payload['sub'])) {
            return false;
        }

        $userId = (int) $payload['sub'];
        $result = $this->db->query(
            "SELECT * FROM users WHERE id = :id AND status = 'active' LIMIT 1",
            [':id' => $userId]
        );

        if (empty($result)) {
            return false;
        }

        $this->updateLastActivity($userId);
        return $result[0];
    }

    /**
     * التحقق من انتهاء صلاحية التوكن
     * @param array $user
     * @return bool
     */
    private function isTokenExpired(array $user): bool
    {
        $expiry = $user['token_expiry'] ?? null;

        if (!$expiry) {
            return false;
        }

        return strtotime($expiry) < time();
    }

    /** @var string|null $lastActivityColumnCache - كاش ثابت لاسم العمود الحقيقي */
    private static $lastActivityColumnCache = null;

    /**
     * تصحيح مؤكد من سجل الأخطاء الفعلي: عمود last_activity مش موجود في
     * جدول users الحقيقي المنشور، فكان الاستعلام ده بيفشل *في كل طلب على
     * الإطلاق* (مُتجاهَل بصمت بفضل try/catch، لكن بيعمل ضجيج ضخم في اللوج
     * وطلب DB إضافي ضايع كل مرة). بنكتشف اسم عمود بديل مناسب أو نتجاهل
     * التحديث تمامًا لو مفيش عمود مناسب أصلاً، بدل المحاولة والفشل المتكرر.
     */
    private function detectLastActivityColumn(): string
    {
        if (self::$lastActivityColumnCache !== null) {
            return self::$lastActivityColumnCache;
        }

        $candidates = ['last_activity', 'last_active_at', 'last_seen_at', 'last_login_at', 'updated_at'];

        try {
            $placeholders = implode(',', array_fill(0, count($candidates), '?'));
            $sql = "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
                    AND COLUMN_NAME IN ({$placeholders})";
            $result = $this->db->query($sql, $candidates);
            $found = array_map('strtolower', array_column($result, 'COLUMN_NAME'));

            foreach ($candidates as $c) {
                if (in_array(strtolower($c), $found, true)) {
                    self::$lastActivityColumnCache = $c;
                    return $c;
                }
            }
        } catch (Exception $e) {
            // تجاهل - هنرجع '' تحت
        }

        self::$lastActivityColumnCache = '';
        return '';
    }

    /**
     * تحديث آخر نشاط
     * @param int $userId
     */
    private function updateLastActivity(int $userId): void
    {
        $column = $this->detectLastActivityColumn();
        if (!$column) {
            return; // مفيش عمود مناسب أصلاً - متحاولش تاني
        }

        try {
            $sql = "UPDATE users 
                    SET `{$column}` = NOW() 
                    WHERE id = :user_id";

            $this->db->query($sql, [':user_id' => $userId]);

        } catch (Exception $e) {
            // تجاهل
        }
    }

    /**
     * التحقق من وجود الدور المطلوب
     * @return bool
     */
    private function hasRequiredRole(): bool
    {
        if (empty($this->requiredRoles)) {
            return true;
        }

        $userRole = $this->user['role'] ?? 'user';

        foreach ($this->requiredRoles as $role) {
            if ($userRole === $role) {
                return true;
            }
        }

        return false;
    }

    /**
     * التحقق من وجود الصلاحيات المطلوبة
     * @return bool
     */
    private function hasRequiredPermissions(): bool
    {
        if (empty($this->requiredPermissions)) {
            return true;
        }

        // جلب صلاحيات المستخدم
        $userPermissions = $this->getUserPermissions($this->user['id']);

        foreach ($this->requiredPermissions as $permission) {
            if (!in_array($permission, $userPermissions)) {
                return false;
            }
        }

        return true;
    }

    /**
     * الحصول على صلاحيات المستخدم
     * @param int $userId
     * @return array
     */
    private function getUserPermissions(int $userId): array
    {
        try {
            $sql = "SELECT permission FROM user_permissions WHERE user_id = :user_id";
            $result = $this->db->query($sql, [':user_id' => $userId]);

            return array_column($result, 'permission');

        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * إضافة المستخدم إلى الطلب
     */
    private function addUserToRequest(): void
    {
        // تخزين في متغيرات البيئة
        $_SERVER['auth_user_id'] = $this->user['id'];
        $_SERVER['auth_user'] = $this->user;

        // تخزين في الجلسة
        $_SESSION['user_id'] = $this->user['id'];
        $_SESSION['user'] = $this->user;
    }

    /**
     * التحقق من المسار العام
     * @return bool
     */
    private function isPublicRoute(): bool
    {
        $publicRoutes = [
            '/api/auth/login',
            '/api/auth/register',
            '/api/auth/forgot-password',
            '/api/auth/reset-password',
            '/api/auth/verify-email',
            '/api/webhook',
            '/api/chat/webhook',
            '/api/review/webhook',
            '/health',
            '/ping'
        ];

        $currentRoute = $_SERVER['REQUEST_URI'] ?? '';

        // تطبيع /api/v1/xxx إلى /api/xxx قبل المقارنة - نفس الـ alias
        // المطبّق في index.php لدعم API Versioning، عشان مسارات public
        // زي /api/auth/login تفضل شغالة برضه لو اتنادت بصيغة /api/v1/auth/login
        $currentRoute = preg_replace('#^/api/v(\d+)/#', '/api/', $currentRoute);

        foreach ($publicRoutes as $route) {
            if (strpos($currentRoute, $route) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * إرجاع استجابة غير مصرح بها
     * @param string $message
     * @return array
     */
    private function unauthorized(string $message = 'Unauthorized'): array
    {
        // ملاحظة: كانت بترجع JSON دايمًا حتى لصفحات الويب العادية (زي /dashboard)،
        // يعني زائر مش مسجل دخول كان بيشوف {"success":false,"error":...} بدل ما
        // يتحول لصفحة تسجيل الدخول. دلوقتي بنفرّق بين طلبات الـ API (تفضل JSON)
        // وطلبات صفحات الويب (GET عادي بدون Accept: application/json) واللي
        // بتتحول لـ /login مباشرة.
        if ($this->isWebPageRequest()) {
            header('Location: /login?redirect=' . urlencode($_SERVER['REQUEST_URI'] ?? '/'));
            exit;
        }

        http_response_code(401);
        return [
            'success' => false,
            'error' => $message,
            'code' => 401
        ];
    }

    /**
     * هل الطلب الحالي صفحة ويب عادية (مش نداء API/AJAX)؟
     * @return bool
     */
    private function isWebPageRequest(): bool
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
            return false;
        }

        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        if (strpos($path, '/api/') === 0) {
            return false;
        }

        $headers = getallheaders() ?: [];
        $acceptHeader = $headers['Accept'] ?? ($headers['accept'] ?? '');
        $requestedWith = $headers['X-Requested-With'] ?? ($headers['x-requested-with'] ?? '');

        if (stripos($acceptHeader, 'application/json') !== false) {
            return false;
        }
        if (strtolower($requestedWith) === 'xmlhttprequest') {
            return false;
        }

        return true;
    }

    /**
     * إرجاع استجابة ممنوعة
     * @param string $message
     * @return array
     */
    private function forbidden(string $message = 'Forbidden'): array
    {
        http_response_code(403);
        return [
            'success' => false,
            'error' => $message,
            'code' => 403
        ];
    }
}
