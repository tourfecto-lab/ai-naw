<?php

/**
 * Tourfecto - Authentication Exception
 * استثناء مخصص لأخطاء المصادقة والصلاحيات
 * @version 1.0.0
 * @author Tourfecto Team
 * @copyright 2026 Tourfecto
 */

class AuthException extends Exception
{
    /**
     * @var string $authType - نوع المصادقة (token, session, oauth)
     */
    private $authType;

    /**
     * @var string $userId - معرف المستخدم
     */
    private $userId;

    /**
     * @var string $token - التوكن المستخدم
     */
    private $token;

    /**
     * @var array $requiredPermissions - الصلاحيات المطلوبة
     */
    private $requiredPermissions = [];

    /**
     * @var array $userPermissions - صلاحيات المستخدم
     */
    private $userPermissions = [];

    /**
     * Constructor
     * @param string $message
     * @param int $code
     * @param Throwable|null $previous
     */
    public function __construct(
        string $message,
        int $code = 401,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);

        // تسجيل الخطأ
        Logger::warning('Auth Exception', [
            'message' => $message,
            'code' => $code,
            'auth_type' => $this->authType,
            'user_id' => $this->userId,
            'trace' => $this->getTraceAsString()
        ]);
    }

    /**
     * تعيين نوع المصادقة
     * @param string $type
     * @return self
     */
    public function setAuthType(string $type): self
    {
        $this->authType = $type;
        return $this;
    }

    /**
     * تعيين معرف المستخدم
     * @param string|int $userId
     * @return self
     */
    public function setUserId($userId): self
    {
        $this->userId = $userId;
        return $this;
    }

    /**
     * تعيين التوكن المستخدم
     * @param string $token
     * @return self
     */
    public function setToken(string $token): self
    {
        $this->token = substr($token, 0, 10) . '...';
        return $this;
    }

    /**
     * تعيين الصلاحيات المطلوبة
     * @param array $permissions
     * @return self
     */
    public function setRequiredPermissions(array $permissions): self
    {
        $this->requiredPermissions = $permissions;
        return $this;
    }

    /**
     * تعيين صلاحيات المستخدم
     * @param array $permissions
     * @return self
     */
    public function setUserPermissions(array $permissions): self
    {
        $this->userPermissions = $permissions;
        return $this;
    }

    /**
     * الحصول على نوع المصادقة
     * @return string|null
     */
    public function getAuthType(): ?string
    {
        return $this->authType;
    }

    /**
     * الحصول على معرف المستخدم
     * @return string|null
     */
    public function getUserId(): ?string
    {
        return $this->userId;
    }

    /**
     * الحصول على الصلاحيات المطلوبة
     * @return array
     */
    public function getRequiredPermissions(): array
    {
        return $this->requiredPermissions;
    }

    /**
     * الحصول على صلاحيات المستخدم
     * @return array
     */
    public function getUserPermissions(): array
    {
        return $this->userPermissions;
    }

    /**
     * التحقق من أن الخطأ بسبب صلاحية منتهية
     * @return bool
     */
    public function isTokenExpired(): bool
    {
        return $this->getCode() === 401 &&
               strpos($this->getMessage(), 'expired') !== false;
    }

    /**
     * التحقق من أن الخطأ بسبب صلاحية غير صالحة
     * @return bool
     */
    public function isInvalidToken(): bool
    {
        return $this->getCode() === 401 &&
               strpos($this->getMessage(), 'invalid') !== false;
    }

    /**
     * التحقق من أن الخطأ بسبب عدم كفاية الصلاحيات
     * @return bool
     */
    public function isInsufficientPermissions(): bool
    {
        return $this->getCode() === 403;
    }

    /**
     * التحقق من أن الخطأ بسبب جلسة منتهية
     * @return bool
     */
    public function isSessionExpired(): bool
    {
        return $this->getCode() === 401 &&
               $this->authType === 'session';
    }

    /**
     * إرجاع استجابة JSON
     */
    public function sendJsonResponse(): void
    {
        $response = [
            'success' => false,
            'error' => $this->getMessage(),
            'code' => $this->getCode(),
            'auth_type' => $this->authType
        ];

        if (!empty($this->requiredPermissions)) {
            $response['required_permissions'] = $this->requiredPermissions;
        }

        http_response_code($this->getCode());
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($response, JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * إنشاء استثناء للمستخدم غير مصرح به
     * @return self
     */
    public static function unauthorized(string $message = 'Unauthorized'): self
    {
        return new self($message, 401);
    }

    /**
     * إنشاء استثناء للمستخدم ممنوع
     * @param string $message
     * @return self
     */
    public static function forbidden(string $message = 'Forbidden'): self
    {
        return new self($message, 403);
    }

    /**
     * إنشاء استثناء لصلاحية منتهية
     * @param string $message
     * @return self
     */
    public static function tokenExpired(string $message = 'Token expired'): self
    {
        $exception = new self($message, 401);
        $exception->setAuthType('token');
        return $exception;
    }

    /**
     * إنشاء استثناء لصلاحية غير صالحة
     * @param string $message
     * @return self
     */
    public static function invalidToken(string $message = 'Invalid token'): self
    {
        $exception = new self($message, 401);
        $exception->setAuthType('token');
        return $exception;
    }
}
