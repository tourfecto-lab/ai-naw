<?php

/**
 * Tourfecto - Database Exception
 * استثناء مخصص لأخطاء قاعدة البيانات
 * @version 1.0.0
 * @author Tourfecto Team
 * @copyright 2026 Tourfecto
 */

class DatabaseException extends Exception
{
    /**
     * @var string $sql - استعلام SQL الذي تسبب في الخطأ
     */
    private $sql;

    /**
     * @var array $params - معاملات الاستعلام
     */
    private $params = [];

    /**
     * @var string $errorCode - رمز خطأ قاعدة البيانات
     */
    private $errorCode;

    /**
     * Constructor
     * @param string $message
     * @param int $code
     * @param string|null $sql
     * @param array $params
     * @param Throwable|null $previous
     */
    public function __construct(
        string $message,
        int $code = 0,
        ?string $sql = null,
        array $params = [],
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
        $this->sql = $sql;
        $this->params = $params;
        $this->errorCode = $code;

        // تسجيل الخطأ
        Logger::error('Database Exception', [
            'message' => $message,
            'code' => $code,
            'sql' => $sql,
            'params' => $params,
            'trace' => $this->getTraceAsString()
        ]);
    }

    /**
     * الحصول على استعلام SQL
     * @return string|null
     */
    public function getSql(): ?string
    {
        return $this->sql;
    }

    /**
     * الحصول على معاملات الاستعلام
     * @return array
     */
    public function getParams(): array
    {
        return $this->params;
    }

    /**
     * الحصول على رمز خطأ قاعدة البيانات
     * @return string
     */
    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    /**
     * الحصول على رسالة مفصلة للتصحيح
     * @return string
     */
    public function getDebugMessage(): string
    {
        $message = "Database Error: {$this->getMessage()}";

        if ($this->sql) {
            $message .= "\nSQL: {$this->sql}";
        }

        if (!empty($this->params)) {
            $message .= "\nParams: " . json_encode($this->params, JSON_PRETTY_PRINT);
        }

        return $message;
    }

    /**
     * التحقق من نوع الخطأ (Duplicate Entry)
     * @return bool
     */
    public function isDuplicateEntry(): bool
    {
        return strpos($this->getMessage(), 'Duplicate entry') !== false;
    }

    /**
     * التحقق من نوع الخطأ (Foreign Key Constraint)
     * @return bool
     */
    public function isForeignKeyConstraint(): bool
    {
        return strpos($this->getMessage(), 'foreign key constraint') !== false;
    }

    /**
     * التحقق من نوع الخطأ (Data Too Long)
     * @return bool
     */
    public function isDataTooLong(): bool
    {
        return strpos($this->getMessage(), 'Data too long') !== false;
    }

    /**
     * التحقق من نوع الخطأ (Deadlock)
     * @return bool
     */
    public function isDeadlock(): bool
    {
        return strpos($this->getMessage(), 'Deadlock') !== false;
    }
}
