<?php

/**
 * Tourfecto - Logger Class
 * نظام تسجيل متقدم مع مستويات مختلفة
 * @version 1.0.0
 * @author Tourfecto Team
 * @copyright 2026 Tourfecto
 */

class Logger
{
    /**
     * @var array $logLevels - مستويات التسجيل
     */
    private static $logLevels = [
        'emergency' => 0,
        'alert' => 1,
        'critical' => 2,
        'error' => 3,
        'warning' => 4,
        'notice' => 5,
        'info' => 6,
        'debug' => 7
    ];

    /**
     * @var string $logPath - مسار ملف السجل
     */
    private static $logPath = '';

    /**
     * @var int $minLevel - أقل مستوى للتسجيل
     */
    private static $minLevel = 3; // error

    /**
     * تهيئة المسجل
     */
    public static function init(): void
    {
        self::$logPath = TOURFECTO_STORAGE . '/logs/app.log';
        self::$minLevel = APP_DEBUG ? 7 : 3;

        // التأكد من وجود المجلد
        $logDir = dirname(self::$logPath);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
    }

    /**
     * تسجيل رسالة
     * @param string $level
     * @param string $message
     * @param array $context
     */
    public static function log(string $level, string $message, array $context = []): void
    {
        self::init();

        // التحقق من مستوى التسجيل
        if (!isset(self::$logLevels[$level]) || self::$logLevels[$level] > self::$minLevel) {
            return;
        }

        // شيل الباسوردات/التوكنات/المفاتيح قبل ما أي حاجة تتكتب على الديسك
        if (!class_exists('LogRedactor', false) && is_file(__DIR__ . '/LogRedactor.php')) {
            require_once __DIR__ . '/LogRedactor.php';
        }
        if (class_exists('LogRedactor', false)) {
            $message = LogRedactor::text($message);
            $context = LogRedactor::context($context);
        }

        $timestamp = date('Y-m-d H:i:s');
        $contextString = !empty($context) ? ' ' . json_encode($context, JSON_UNESCAPED_UNICODE) : '';

        $logMessage = "[{$timestamp}] [{$level}] {$message}{$contextString}\n";

        file_put_contents(self::$logPath, $logMessage, FILE_APPEND | LOCK_EX);
    }

    /**
     * تسجيل خطأ
     * @param string $message
     * @param array $context
     */
    public static function error(string $message, array $context = []): void
    {
        self::log('error', $message, $context);
    }

    /**
     * تسجيل تحذير
     * @param string $message
     * @param array $context
     */
    public static function warning(string $message, array $context = []): void
    {
        self::log('warning', $message, $context);
    }

    /**
     * تسجيل معلومات
     * @param string $message
     * @param array $context
     */
    public static function info(string $message, array $context = []): void
    {
        self::log('info', $message, $context);
    }

    /**
     * تسجيل للتصحيح
     * @param string $message
     * @param array $context
     */
    public static function debug(string $message, array $context = []): void
    {
        self::log('debug', $message, $context);
    }

    /**
     * تسجيل خطأ حرج
     * @param string $message
     * @param array $context
     */
    public static function critical(string $message, array $context = []): void
    {
        self::log('critical', $message, $context);
    }

    /**
     * تسجيل خطأ طارئ
     * @param string $message
     * @param array $context
     */
    public static function emergency(string $message, array $context = []): void
    {
        self::log('emergency', $message, $context);
    }

    /**
     * تسجيل استثناء
     * @param Exception $e
     * @param string $message
     */
    public static function exception(Exception $e, string $message = ''): void
    {
        $context = [
            'exception' => get_class($e),
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString()
        ];

        self::error($message ?: $e->getMessage(), $context);
    }

    /**
     * الحصول على محتوى السجل
     * @param int $lines
     * @return string
     */
    public static function getLog(int $lines = 100): string
    {
        self::init();

        if (!file_exists(self::$logPath)) {
            return '';
        }

        $logContent = file_get_contents(self::$logPath);
        $logLines = explode("\n", $logContent);
        $logLines = array_reverse(array_filter($logLines));
        $logLines = array_slice($logLines, 0, $lines);

        return implode("\n", array_reverse($logLines));
    }

    /**
     * تنظيف السجل
     * @param int $keepLines
     */
    public static function clean(int $keepLines = 1000): void
    {
        self::init();

        if (!file_exists(self::$logPath)) {
            return;
        }

        $logContent = file_get_contents(self::$logPath);
        $logLines = explode("\n", $logContent);

        if (count($logLines) > $keepLines) {
            $logLines = array_slice($logLines, -$keepLines);
            file_put_contents(self::$logPath, implode("\n", $logLines));
        }
    }
}
