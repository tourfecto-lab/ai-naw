<?php

/**
 * Tourfecto - Subscription Exception
 * استثناء مخصص لأخطاء الاشتراكات والفوترة
 * @version 1.0.0
 * @author Tourfecto Team
 * @copyright 2026 Tourfecto
 */

class SubscriptionException extends Exception
{
    /**
     * @var int $userId - معرف المستخدم
     */
    private $userId;

    /**
     * @var string $planName - اسم الباقة
     */
    private $planName;

    /**
     * @var string $subscriptionId - معرف الاشتراك
     */
    private $subscriptionId;

    /**
     * @var array $credits - تفاصيل الرصيد
     */
    private $credits = [];

    /**
     * @var string $feature - الميزة المطلوبة
     */
    private $feature;

    /**
     * @var int $required - المبلغ المطلوب
     */
    private $required;

    /**
     * @var int $remaining - المبلغ المتبقي
     */
    private $remaining;

    /**
     * Constructor
     * @param string $message
     * @param int $code
     * @param Throwable|null $previous
     */
    public function __construct(
        string $message,
        int $code = 403,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);

        // تسجيل الخطأ
        Logger::warning('Subscription Exception', [
            'message' => $message,
            'code' => $code,
            'user_id' => $this->userId,
            'plan' => $this->planName,
            'feature' => $this->feature,
            'trace' => $this->getTraceAsString()
        ]);
    }

    /**
     * تعيين معرف المستخدم
     * @param int $userId
     * @return self
     */
    public function setUserId(int $userId): self
    {
        $this->userId = $userId;
        return $this;
    }

    /**
     * تعيين اسم الباقة
     * @param string $planName
     * @return self
     */
    public function setPlanName(string $planName): self
    {
        $this->planName = $planName;
        return $this;
    }

    /**
     * تعيين معرف الاشتراك
     * @param string $subscriptionId
     * @return self
     */
    public function setSubscriptionId(string $subscriptionId): self
    {
        $this->subscriptionId = $subscriptionId;
        return $this;
    }

    /**
     * تعيين تفاصيل الرصيد
     * @param array $credits
     * @return self
     */
    public function setCredits(array $credits): self
    {
        $this->credits = $credits;
        return $this;
    }

    /**
     * تعيين الميزة المطلوبة
     * @param string $feature
     * @return self
     */
    public function setFeature(string $feature): self
    {
        $this->feature = $feature;
        return $this;
    }

    /**
     * تعيين المبلغ المطلوب والمتبقي
     * @param int $required
     * @param int $remaining
     * @return self
     */
    public function setCreditInfo(int $required, int $remaining): self
    {
        $this->required = $required;
        $this->remaining = $remaining;
        return $this;
    }

    /**
     * الحصول على معرف المستخدم
     * @return int|null
     */
    public function getUserId(): ?int
    {
        return $this->userId;
    }

    /**
     * الحصول على اسم الباقة
     * @return string|null
     */
    public function getPlanName(): ?string
    {
        return $this->planName;
    }

    /**
     * الحصول على معرف الاشتراك
     * @return string|null
     */
    public function getSubscriptionId(): ?string
    {
        return $this->subscriptionId;
    }

    /**
     * الحصول على تفاصيل الرصيد
     * @return array
     */
    public function getCredits(): array
    {
        return $this->credits;
    }

    /**
     * الحصول على الميزة المطلوبة
     * @return string|null
     */
    public function getFeature(): ?string
    {
        return $this->feature;
    }

    /**
     * التحقق من أن الخطأ بسبب عدم كفاية الرصيد
     * @return bool
     */
    public function isInsufficientCredits(): bool
    {
        return $this->getCode() === 403 &&
               strpos($this->getMessage(), 'Insufficient') !== false;
    }

    /**
     * التحقق من أن الخطأ بسبب انتهاء الاشتراك
     * @return bool
     */
    public function isExpired(): bool
    {
        return $this->getCode() === 403 &&
               strpos($this->getMessage(), 'expired') !== false;
    }

    /**
     * التحقق من أن الخطأ بسبب خطة غير مدعومة
     * @return bool
     */
    public function isUnsupportedPlan(): bool
    {
        return $this->getCode() === 400 &&
               strpos($this->getMessage(), 'plan') !== false;
    }

    /**
     * إرجاع استجابة JSON
     */
    public function sendJsonResponse(): void
    {
        $response = [
            'success' => false,
            'error' => $this->getMessage(),
            'code' => $this->getCode()
        ];

        if ($this->feature) {
            $response['feature'] = $this->feature;
        }

        if ($this->required !== null && $this->remaining !== null) {
            $response['required'] = $this->required;
            $response['remaining'] = $this->remaining;
        }

        if ($this->credits) {
            $response['credits'] = $this->credits;
        }

        http_response_code($this->getCode());
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($response, JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * إنشاء استثناء لعدم كفاية الرصيد
     * @param string $feature
     * @param int $required
     * @param int $remaining
     * @return self
     */
    public static function insufficientCredits(
        string $feature,
        int $required,
        int $remaining
    ): self {
        $exception = new self(
            "Insufficient credits for '{$feature}'. Required: {$required}, Remaining: {$remaining}",
            403
        );
        $exception->setFeature($feature);
        $exception->setCreditInfo($required, $remaining);
        return $exception;
    }

    /**
     * إنشاء استثناء لاشتراك منتهي
     * @param int $userId
     * @return self
     */
    public static function expired(int $userId): self
    {
        $exception = new self(
            'Your subscription has expired. Please renew to continue using our services.',
            403
        );
        $exception->setUserId($userId);
        return $exception;
    }

    /**
     * إنشاء استثناء لخطة غير مدعومة
     * @param string $planName
     * @return self
     */
    public static function unsupportedPlan(string $planName): self
    {
        $exception = new self(
            "The plan '{$planName}' is not supported or does not exist.",
            400
        );
        $exception->setPlanName($planName);
        return $exception;
    }
}
