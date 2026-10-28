<?php

/**
 * Tourfecto - AI Chat Platform - معالج المتابعة التلقائية (Follow-up
 * Automation) - بند 7 (يُستدعى من Cron Job حقيقي).
 * @version 1.0.0
 *
 * إعداد Cron Job في cPanel (Hostinger):
 * ------------------------------------
 * 1) ادخل cPanel -> Cron Jobs
 * 2) Common Settings: Every 30 Minutes (كافٍ لأن التوقيت المطلوب هنا
 *    بالساعات مش بالدقائق - راجع ai_followup_rules.steps لكل شركة)
 * 3) Command (غيّر المسار حسب اسم الدومين الحقيقي عندك):
 *    php /home/USERNAME/domains/YOURSITE.com/cron/process_ai_followups.php >> /home/USERNAME/domains/YOURSITE.com/storage/logs/ai_followups.log 2>&1
 */

require_once __DIR__ . '/bootstrap.php';

$startedAt = microtime(true);

try {
    if (!class_exists('FollowUpAutomationService')) {
        fwrite(STDOUT, '[' . date('Y-m-d H:i:s') . "] FollowUpAutomationService غير موجودة - تأكد من رفع ملفات AI Chat Platform المرحلة 1 و3 وتشغيل الـmigration:\n");
        fwrite(STDOUT, "  database/migrations/2026_08_08_000001_create_ai_chat_platform_tables.sql\n");
        exit(0);
    }

    $service = new FollowUpAutomationService();
    $result = $service->processDueFollowUps();
    $durationMs = round((microtime(true) - $startedAt) * 1000);

    fwrite(STDOUT, sprintf(
        "[%s] AI Follow-ups run: %d اتجدولت، %d اتبعتت، %d اتلغت، %d فشلت (%dms)\n",
        date('Y-m-d H:i:s'),
        $result['scheduled'],
        $result['sent'],
        $result['cancelled'],
        $result['failed'],
        $durationMs
    ));
} catch (Throwable $e) {
    fwrite(STDERR, '[' . date('Y-m-d H:i:s') . '] خطأ: ' . $e->getMessage() . "\n");
    exit(1);
}
