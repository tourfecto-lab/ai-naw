<?php
/** Tourfecto - تنبيه فقد ذكر/اقتباس الشركة في إجابات AI (أسبوعي بعد القياس) */
require_once __DIR__ . '/bootstrap.php';

try {
    echo json_encode((new AiVisibilityAlertService())->run()) . "\n";
} catch (Throwable $e) {
    Logger::error('GEO loss alerts cron failed', ['error' => $e->getMessage()]);
    echo 'error: ' . $e->getMessage() . "\n";
    exit(1);
}
