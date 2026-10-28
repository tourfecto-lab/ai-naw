<?php

/**
 * Tourfecto - Outreach Follow-Up Drafts Cron (Item 2b)
 * @version 1.0.0
 *
 * إعداد Cron Job في cPanel (Hostinger) - يفضّل يوميًا:
 *   php /home/USERNAME/domains/YOURSITE.com/cron/generate_outreach_followups.php >> /home/USERNAME/domains/YOURSITE.com/storage/logs/outreach_followups.log 2>&1
 *
 * بيولّد مسودات متابعة (draft) للمرشّحين اللي مرّ 7 أيام على آخر رسالة
 * مُرسلة ليهم. المسودات بس - ممنوع الإرسال التلقائي (أي إرسال بيظل
 * محتاج موافقة صريحة من العميل). بيبلّغ المستخدم إن المسودات جاهزة
 * للمراجعة عبر Notification.
 */

require_once __DIR__ . '/bootstrap.php';

$startedAt = microtime(true);

try {
    if (!class_exists('OutreachFollowUpDraftService')) {
        fwrite(STDOUT, '[' . date('Y-m-d H:i:s') . "] OutreachFollowUpDraftService غير متاحة - تأكد من رفع ملفات الموديول:\n");
        fwrite(STDOUT, "  app/Services/Outreach/OutreachFollowUpDraftService.php\n");
        exit(0);
    }

    $service = new OutreachFollowUpDraftService();
    $stats = $service->generateDueFollowUps(50);
    $durationMs = round((microtime(true) - $startedAt) * 1000);

    fwrite(STDOUT, sprintf(
        "[%s] Outreach Follow-Ups: %d مسودة اتولدت، %d اتخطت، %d فشلت (%dms)\n",
        date('Y-m-d H:i:s'),
        $stats['generated'],
        $stats['skipped'],
        $stats['failed'],
        $durationMs
    ));
} catch (Throwable $e) {
    fwrite(STDERR, '[' . date('Y-m-d H:i:s') . '] Outreach follow-ups error: ' . $e->getMessage() . "\n");
    if (class_exists('Logger')) {
        Logger::error('Outreach follow-ups cron failed', ['error' => $e->getMessage()]);
    }
    exit(1);
}
