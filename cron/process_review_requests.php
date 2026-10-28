<?php

/**
 * Tourfecto - معالج طلبات المراجعات التلقائية (يُستدعى من Cron Job حقيقي)
 * @version 1.0.0
 *
 * إعداد Cron Job في cPanel (Hostinger):
 * ------------------------------------
 * 1) ادخل cPanel -> Cron Jobs
 * 2) Common Settings: Every 15 Minutes (أو أي فترة تناسبك - مش محتاجة
 *    دقة "كل دقيقة" زي طابور المهام العادي، لأن التوقيت المطلوب هنا
 *    بالساعات مش بالثواني)
 * 3) Command (غيّر المسار حسب اسم الدومين الحقيقي عندك):
 *    php /home/USERNAME/domains/YOURSITE.com/cron/process_review_requests.php >> /home/USERNAME/domains/YOURSITE.com/storage/logs/review_requests.log 2>&1
 */

require_once __DIR__ . '/bootstrap.php';

$startedAt = microtime(true);

try {
    if (!class_exists('ReviewRequestService')) {
        fwrite(STDOUT, '[' . date('Y-m-d H:i:s') . "] ReviewRequestService غير موجودة - تأكد من رفع الملفات وتشغيل الـ migration:\n");
        fwrite(STDOUT, "  database/migrations/2026_07_25_000026_create_review_requests_system.sql\n");
        exit(0);
    }

    $service = new ReviewRequestService();
    // طلبات تلقائية من الحجوزات اللي رحلتها خلصت (لو العميل مفعّلها من الإعدادات)
    $fromBookings = $service->createFromRecentBookings();
    if ($fromBookings > 0) {
        fwrite(STDOUT, '[' . date('Y-m-d H:i:s') . "] اتعمل {$fromBookings} طلب تقييم من الحجوزات\n");
    }
    $result = $service->processDueRequests();
    $durationMs = round((microtime(true) - $startedAt) * 1000);

    fwrite(STDOUT, sprintf(
        "[%s] Review Requests run: %d اتبعتت، %d اتبعتلها تذكير، %d فشلت (%dms)\n",
        date('Y-m-d H:i:s'),
        $result['sent'],
        $result['reminded'],
        $result['failed'],
        $durationMs
    ));
} catch (Throwable $e) {
    fwrite(STDERR, '[' . date('Y-m-d H:i:s') . '] خطأ: ' . $e->getMessage() . "\n");
    exit(1);
}
