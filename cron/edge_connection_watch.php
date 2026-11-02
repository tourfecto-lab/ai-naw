<?php
/**
 * Tourfecto - تنبيه لو موصّل موقع العميل وقف عن سحب قواعد المنصة (كل ساعتين).
 */
require_once __DIR__ . '/bootstrap.php';
require_once dirname(__DIR__) . '/app/Services/AutoFix/EdgeConnectionWatch.php';

try {
    echo json_encode((new EdgeConnectionWatch())->run()) . "\n";
} catch (Throwable $e) {
    Logger::error('Edge connection watch cron failed', ['error' => $e->getMessage()]);
    echo 'error: ' . $e->getMessage() . "\n";
    exit(1);
}
