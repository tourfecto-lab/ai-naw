<?php

/**
 * Tourfecto - Validation Exception
 * استثناء مخصص لأخطاء التحقق من البيانات
 * @version 1.0.0
 * @author Tourfecto Team
 * @copyright 2026 Tourfecto
 */

class ValidationException extends Exception
{
    /**
     * @var array $errors - قائمة الأخطاء التفصيلية
     */
    private $errors = [];

    /**
     * @var array $data - البيانات التي تم التحقق منها
     */
    private $data = [];

    /**
     * @var array $rules - قواعد التحقق
     */
    private $rules = [];

    /**
     * @var int $errorCount - عدد الأخطاء
     */
    private $errorCount = 0;

    /**
     * Constructor
     * @param string $message
     * @param int $code
     * @param array $errors
     * @param Throwable|null $previous
     */
    public function __construct(
        string $message,
        int $code = 422,
        array $errors = [],
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
        $this->errors = $errors;
        $this->errorCount = count($errors);

        // تسجيل الخطأ
        Logger::info('Validation Exception', [
            'message' => $message,
            'code' => $code,
            'errors' => $errors,
            'count' => $this->errorCount,
            'trace' => $this->getTraceAsString()
        ]);
    }

    /**
     * إضافة خطأ
     * @param string $field
     * @param string $message
     * @return self
     */
    public function addError(string $field, string $message): self
    {
        if (!isset($this->errors[$field])) {
            $this->errors[$field] = [];
        }
        $this->errors[$field][] = $message;
        $this->errorCount++;
        return $this;
    }

    /**
     * إضافة أخطاء متعددة
     * @param array $errors
     * @return self
     */
    public function addErrors(array $errors): self
    {
        foreach ($errors as $field => $messages) {
            if (is_array($messages)) {
                foreach ($messages as $message) {
                    $this->addError($field, $message);
                }
            } else {
                $this->addError($field, $messages);
            }
        }
        return $this;
    }

    /**
     * تعيين البيانات التي تم التحقق منها
     * @param array $data
     * @return self
     */
    public function setData(array $data): self
    {
        $this->data = $data;
        return $this;
    }

    /**
     * تعيين قواعد التحقق
     * @param array $rules
     * @return self
     */
    public function setRules(array $rules): self
    {
        $this->rules = $rules;
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
     * الحصول على الأخطاء كسلسلة نصية
     * @param string $separator
     * @return string
     */
    public function getErrorsString(string $separator = '; '): string
    {
        $messages = [];
        foreach ($this->errors as $field => $errors) {
            $messages[] = $field . ': ' . implode(', ', $errors);
        }
        return implode($separator, $messages);
    }

    /**
     * الحصول على أول خطأ
     * @return string|null
     */
    public function getFirstError(): ?string
    {
        foreach ($this->errors as $errors) {
            if (!empty($errors)) {
                return $errors[0];
            }
        }
        return null;
    }

    /**
     * الحصول على عدد الأخطاء
     * @return int
     */
    public function getErrorCount(): int
    {
        return $this->errorCount;
    }

    /**
     * الحصول على البيانات التي تم التحقق منها
     * @return array
     */
    public function getData(): array
    {
        return $this->data;
    }

    /**
     * الحصول على قواعد التحقق
     * @return array
     */
    public function getRules(): array
    {
        return $this->rules;
    }

    /**
     * التحقق من وجود أخطاء في حقل معين
     * @param string $field
     * @return bool
     */
    public function hasError(string $field): bool
    {
        return isset($this->errors[$field]);
    }

    /**
     * الحصول على أخطاء حقل معين
     * @param string $field
     * @return array
     */
    public function getFieldErrors(string $field): array
    {
        return $this->errors[$field] ?? [];
    }

    /**
     * التحقق من وجود أخطاء
     * @return bool
     */
    public function hasErrors(): bool
    {
        return !empty($this->errors);
    }

    /**
     * تحويل إلى مصفوفة
     * @return array
     */
    public function toArray(): array
    {
        return [
            'success' => false,
            'error' => $this->getMessage(),
            'code' => $this->getCode(),
            'errors' => $this->errors,
            'error_count' => $this->errorCount
        ];
    }

    /**
     * تحويل إلى JSON
     * @return string
     */
    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    /**
     * إرجاع استجابة JSON
     */
    public function sendJsonResponse(): void
    {
        http_response_code($this->getCode());
        header('Content-Type: application/json; charset=utf-8');
        echo $this->toJson();
        exit;
    }

    /**
     * إنشاء استثناء مع أخطاء من Validator
     * @param array $errors
     * @param string $message
     * @return self
     */
    public static function fromValidator(array $errors, string $message = 'Validation failed'): self
    {
        $exception = new self($message, 422);
        $exception->addErrors($errors);
        return $exception;
    }

    /**
     * إنشاء استثناء لحقل مطلوب
     * @param string $field
     * @param string $message
     * @return self
     */
    public static function required(string $field, ?string $message = null): self
    {
        $message = $message ?? "The '{$field}' field is required.";
        $exception = new self('Validation failed', 422);
        $exception->addError($field, $message);
        return $exception;
    }

    /**
     * إنشاء استثناء لتنسيق غير صحيح
     * @param string $field
     * @param string $format
     * @param string $message
     * @return self
     */
    public static function invalidFormat(string $field, string $format, ?string $message = null): self
    {
        $message = $message ?? "The '{$field}' field must be a valid {$format}.";
        $exception = new self('Validation failed', 422);
        $exception->addError($field, $message);
        return $exception;
    }

    /**
     * إنشاء استثناء لقيمة غير مسموحة
     * @param string $field
     * @param array $allowed
     * @param string $message
     * @return self
     */
    public static function notAllowed(string $field, array $allowed, ?string $message = null): self
    {
        $allowedList = implode(', ', $allowed);
        $message = $message ?? "The '{$field}' field must be one of: {$allowedList}.";
        $exception = new self('Validation failed', 422);
        $exception->addError($field, $message);
        return $exception;
    }
}
