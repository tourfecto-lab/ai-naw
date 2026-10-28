<?php
/**
 * Tourfecto - حارس طلبات الاستوديو الإبداعي (كل 10 دقايق من المنظّم)
 * يكمّل الطلبات اللي اتولّدت ومستنية الخصم، ويقفل الطلبات العالقة بدون خصم ويرجّع الرصيد المحجوز.
 * التفاصيل: app/Services/CreativeStudio/CreativeGenerationWatchdog.php
 */
require_once __DIR__ . '/bootstrap.php';

try {
    $out = CreativeGenerationWatchdog::run();
    echo json_encode($out) . PHP_EOL;
} catch (Throwable $e) {
    Logger::error('Creative watchdog failed', ['error' => $e->getMessage()]);
    echo json_encode(['error' => 'creative watchdog failed']) . PHP_EOL;
    exit(1);
}
