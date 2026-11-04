<?php
/**
 * Tourfecto - تسويق المنصة بالإيميل (كل 5 دقايق من المنظّم)
 * بيبعت دفعة صغيرة من الرسايل جوه مواعيد العمل وبالحد اليومي، وبيدوّر على إيميلات
 * الشركات اللي مالهاش إيميل في مواقعها.
 */
require_once __DIR__ . '/bootstrap.php';
require_once dirname(__DIR__) . '/app/Services/PlatformGrowth/PlatformOutreachService.php';

try {
    $service = new PlatformOutreachService();
    // التشخيص الأول: الشركة مابيتبعتلهاش غير بعد ما احتياجها يتحدد
    // 20 شركة / 200 ثانية كحد أقصى في الدورة (الدورة كل 5 دقايق): إعادة الفحص بعد أي تحديث بتخلص لوحدها في ساعات
    $scanned = $service->scan(20, 200);
    $result = $service->run();
    $result['scanned'] = $scanned;
    $result['enriched'] = $service->enrich(6, 60);
    // تذكير يومي لكل موظف بالشركات اللي محتاجة متابعة (بعد الساعة 9 الصبح، مرة في اليوم)
    $result['digest'] = (int) date('G') >= 9 ? $service->sendFollowupDigest() : 0;
    (new SystemSettingsService())->set('outreach_last_run', date('Y-m-d H:i:s'));
    echo json_encode($result) . "\n";
} catch (Throwable $e) {
    Logger::error('Platform outreach cron failed', ['error' => $e->getMessage()]);
    echo 'error: ' . $e->getMessage() . "\n";
    exit(1);
}
