<?php

/**
 * Tourfecto - معالج أتمتة التسويق البريدية (Email Automation) - يُستدعى
 * من Cron Job حقيقي.
 * @version 1.0.0
 *
 * يشغّل EmailAutomationService::processDue() بانتظام عشان خطوات WAIT (التأخير
 * الزمني) والـ enroll بعد تاريخ معين (date_after) تستحق وتتبعت فعليًا، مش بس
 * عند حدوث حدث مباشر (subscribe/tag/campaign open/click).
 *
 * إعداد Cron Job في cPanel (Hostinger):
 * ------------------------------------
 * 1) ادخل cPanel -> Cron Jobs
 * 2) Common Settings: Every Minute - أو كل 5 دقائق حسب الحجم (الخدمة بتحدد
 *    next_run_at لكل مشاركة، فالـ cron المنتظم بس بيجيب اللي استحق).
 * 3) Command (غيّر المسار حسب اسم الدومين الحقيقي عندك):
 *    php /home/USERNAME/domains/YOURSITE.com/cron/process_email_automations.php >> /home/USERNAME/domains/YOURSITE.com/storage/logs/email_automations.log 2>&1
 */

require_once __DIR__ . '/bootstrap.php';

$startedAt = microtime(true);

try {
    if (!class_exists('EmailAutomationService')) {
        fwrite(STDOUT, '[' . date('Y-m-d H:i:s') . "] EmailAutomationService غير موجودة - تأكد من رفع ملفات Email Marketing (المرحلة 3) وتشغيل الـmigration:\n");
        fwrite(STDOUT, "  database/migrations/2026_08_22_000013_email_marketing_automations.sql\n");
        exit(0);
    }

    $service = new EmailAutomationService();
    $result = $service->processDue();
    // الحملات المتكررة (يومي/أسبوعي/شهري) — نفس الـ cron، مفيش cron جديد
    $recurring = class_exists('EmailRecurringCampaignService') ? (new EmailRecurringCampaignService())->processDue() : 0;
    $durationMs = round((microtime(true) - $startedAt) * 1000);

    fwrite(STDOUT, sprintf(
        "[%s] Email Automations run: %d اتجليت، %d اكتملت، %d حملة متكررة (%dms)\n",
        date('Y-m-d H:i:s'),
        $result['processed'],
        $result['completed'],
        $recurring,
        $durationMs
    ));
} catch (Throwable $e) {
    fwrite(STDERR, '[' . date('Y-m-d H:i:s') . '] خطأ: ' . $e->getMessage() . "\n");
    if (class_exists('Logger')) {
        Logger::error('Email automations cron failed', ['error' => $e->getMessage()]);
    }
    exit(1);
}
