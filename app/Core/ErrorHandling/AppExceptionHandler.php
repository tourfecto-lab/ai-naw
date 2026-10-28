<?php

/**
 * Tourfecto - Structured Exception Handler
 * @version 1.0.0
 *
 * مهم: الكلاس ده "متاح" بس *مش مفعّل تلقائيًا*. index.php الحالي عنده
 * منطق معالجة أخطاء شغال فعلاً (send_response, default_error_message).
 * تفعيل exception handler عام جديد ده تغيير في سلوك موقع شغال حاليًا،
 * وده بالظبط اللي المرحلة دي (بناء الأساس بس) اتفقنا نتجنبه.
 *
 * لما تيجي مرحلة الدمج الفعلي، التفعيل بيبقى سطر واحد إضافي في أول
 * index.php (من غير حذف أي حاجة موجودة):
 *
 *   AppExceptionHandler::register();
 *
 * وهيشتغل جنب المعالجة الحالية، مش بدالها.
 */
class AppExceptionHandler
{
    /** @var bool */
    private static $registered = false;

    public static function register(): void
    {
        if (self::$registered) {
            return;
        }
        self::$registered = true;

        set_exception_handler([self::class, 'handleException']);
        set_error_handler([self::class, 'handleError']);
        register_shutdown_function([self::class, 'handleShutdown']);
    }

    public static function handleException(Throwable $e): void
    {
        self::logThrowable($e);
        self::respond($e);
    }

    public static function handleError(int $severity, string $message, string $file = '', int $line = 0): bool
    {
        if (!(error_reporting() & $severity)) {
            return false; // مقموع بواسطة @ أو error_reporting الحالي
        }

        if (class_exists('Logger')) {
            Logger::warning('PHP Error', [
                'severity' => $severity,
                'message' => $message,
                'file' => $file,
                'line' => $line,
            ]);
        }

        // إرجاع false يسيب معالج PHP الافتراضي يكمل شغله كمان (توافقية)
        return false;
    }

    public static function handleShutdown(): void
    {
        $error = error_get_last();
        if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            if (class_exists('Logger')) {
                Logger::critical('Fatal Error (shutdown)', $error);
            }
        }
    }

    private static function logThrowable(Throwable $e): void
    {
        if (class_exists('Logger')) {
            Logger::error(get_class($e) . ': ' . $e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => (defined('APP_DEBUG') && APP_DEBUG) ? $e->getTraceAsString() : null,
            ]);
        }
    }

    private static function respond(Throwable $e): void
    {
        if (headers_sent()) {
            return;
        }

        http_response_code(500);

        $isApi = strpos($_SERVER['REQUEST_URI'] ?? '', '/api/') === 0;
        $debug = defined('APP_DEBUG') && APP_DEBUG;

        if ($isApi) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => false,
                'error' => $debug ? $e->getMessage() : 'حدث خطأ غير متوقع، حاول مرة أخرى',
                'code' => 500,
            ], JSON_UNESCAPED_UNICODE);
        } else {
            echo '<h1>خطأ غير متوقع</h1>';
            if ($debug) {
                echo '<pre>' . htmlspecialchars($e->getMessage() . "\n" . $e->getTraceAsString(), ENT_QUOTES, 'UTF-8') . '</pre>';
            } else {
                echo '<p>حصل خطأ، وريقنا بيشتغل عليه. جرّب تاني بعد شوية.</p>';
            }
        }
    }
}
