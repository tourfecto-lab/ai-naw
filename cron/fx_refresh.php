<?php
/**
 * Tourfecto - سعر الصرف مربوط بالبنك (كل 5 دقايق من المنظّم)
 * بيحدّث بس لما ييجي الميعاد اللي الأدمن اختاره (كل 15 دقيقة افتراضيًا)،
 * ولو الربط التلقائي مقفول مابيعملش حاجة.
 */
require_once __DIR__ . '/bootstrap.php';
require_once dirname(__DIR__) . '/app/Services/Payment/CurrencyService.php';

try {
    echo json_encode(CurrencyService::autoRefresh(), JSON_UNESCAPED_UNICODE) . "\n";
    // مرة واحدة: الباقات الجديدة لو الأسعار لسه القديمة
    require_once dirname(__DIR__) . '/app/Services/Subscription/PlanExtras.php';
    if ((new PlanExtras())->autoApplyPresetIfLegacy()) echo "recommended plans applied\n";
} catch (Throwable $e) {
    Logger::error('FX refresh cron failed', ['error' => $e->getMessage()]);
    echo 'error: ' . $e->getMessage() . "\n";
    exit(1);
}
