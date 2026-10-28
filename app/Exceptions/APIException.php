<?php

/**
 * Tourfecto - API Exception
 * استثناء مخصص لأخطاء API
 * @version 1.0.0
 * @author Tourfecto Team
 * @copyright 2026 Tourfecto
 */

class APIException extends Exception
{
    /**
     * @var array $errors - قائمة الأخطاء التفصيلية
     */
    private $errors = [];

    /**
     * @var string $endpoint - نقطة النهاية API
     */
    private $endpoint;

    /**
     * @var string $method - طريقة الطلب
     */
    private $method;

    /**
     * @var array $requestData - بيانات الطلب
     */
    private $requestData = [];

    /**
     * @var array $responseData - بيانات الاستجابة
     */
    private $responseData = [];

    /**
     * Constructor
     * @param string $message
     * @param int $code
     * @param array $errors
     * @param Throwable|null $previous
     */
    public function __construct(
        string $message,
        int $code = 500,
        array $errors = [],
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
        $this->errors = $errors;

        // تسجيل الخطأ
        Logger::error('API Exception', [
            'message' => $message,
            'code' => $code,
            'errors' => $errors,
            'endpoint' => $this->endpoint,
            'method' => $this->method,
            'trace' => $this->getTraceAsString()
        ]);
    }

    /**
     * تعيين نقطة النهاية
     * @param string $endpoint
     * @return self
     */
    public function setEndpoint(string $endpoint): self
    {
        $this->endpoint = $endpoint;
        return $this;
    }

    /**
     * تعيين طريقة الطلب
     * @param string $method
     * @return self
     */
    public function setMethod(string $method): self
    {
        $this->method = $method;
        return $this;
    }

    /**
     * تعيين بيانات الطلب
     * @param array $data
     * @return self
     */
    public function setRequestData(array $data): self
    {
        $this->requestData = $data;
        return $this;
    }

    /**
     * تعيين بيانات الاستجابة
     * @param array $data
     * @return self
     */
    public function setResponseData(array $data): self
    {
        $this->responseData = $data;
        return $this;
    }

    /**
     * إضافة خطأ تفصيلي
     * @param string $field
     * @param string $message
     * @return self
     */
    public function addError(string $field, string $message): self
    {
        $this->errors[$field] = $message;
        return $this;
    }

    /**
     * الحصول على الأخطاء التفصيلية
     * @return array
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * الحصول على نقطة النهاية
     * @return string|null
     */
    public function getEndpoint(): ?string
    {
        return $this->endpoint;
    }

    /**
     * الحصول على طريقة الطلب
     * @return string|null
     */
    public function getMethod(): ?string
    {
        return $this->method;
    }

    /**
     * الحصول على بيانات الطلب
     * @return array
     */
    public function getRequestData(): array
    {
        return $this->requestData;
    }

    /**
     * الحصول على بيانات الاستجابة
     * @return array
     */
    public function getResponseData(): array
    {
        return $this->responseData;
    }

    /**
     * تحويل إلى مصفوفة JSON
     * @return array
     */
    public function toArray(): array
    {
        $data = [
            'success' => false,
            'error' => $this->getMessage(),
            'code' => $this->getCode()
        ];

        if (!empty($this->errors)) {
            $data['errors'] = $this->errors;
        }

        if ($this->endpoint) {
            $data['endpoint'] = $this->endpoint;
        }

        return $data;
    }

    /**
     * تحويل إلى JSON
     * @return string
     */
    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_UNESCAPED_UNICODE);
    }

    /**
     * معالجة استجابة API كـ JSON
     */
    public function sendJsonResponse(): void
    {
        http_response_code($this->getCode());
        header('Content-Type: application/json; charset=utf-8');
        echo $this->toJson();
        exit;
    }
}
