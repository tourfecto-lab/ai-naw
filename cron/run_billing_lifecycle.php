<?php

/**
 * Tourfecto - معالج دورة حياة الاشتراكات (يُستدعى من Cron Job حقيقي)
 * @version 1.0.0
 * @date 2026-08-15
 *
 * أهم فجوة تشغيلية في الموديول (Phase 18): من غير Cron حقيقي، التجديد
 * التلقائي والانتقالات (active→past_due→cancelled) بتحصل "كسول" بس لما
 * الأدمن يفتح صفحة الاشتراكات. السكريبت ده بيشغّل نفس الـ checks دي
 * دوريًا من غير أي تدخل بشري - وده مطلوب عشان التجديد التلقائي من
 * الرصيد يشتغل فعلاً في ميعاده، مش لما حد يفتح الصفحة بالصدفة.
 *
 * إعداد Cron Job في cPanel (Hostinger):
 * ------------------------------------
 * 1) ادخل cPanel -> Cron Jobs
 * 2) Common Settings: Once a day (التجديد بفترة سماح 7 أيام، فمرة
 *    واحدة يوميًا كافية تمامًا - مفيش داعي لدقة بالدقائق)
 * 3) Command (غيّر المسار حسب اسم الدومين الحقيقي عندك):
 *    php /home/USERNAME/domains/YOURSITE.com/cron/run_billing_lifecycle.php >> /home/USERNAME/domains/YOURSITE.com/storage/logs/billing_lifecycle.log 2>&1
 *
 * التصميم Idempotent بالكامل - آمن يتشغّل أي عدد مرات (كل صف بيتتحرك
 * مرة واحدة بس، والتجديد اللي نجح بيمدد الفترة في أي تشغيلة تانية
 * مش هيلاقيه مستحق). فحتى لو الـ Cron اتنفّذ مرتين بالغلط، مفيش
 * خصم مزدوج (قفل FOR UPDATE + idempotency_key في
 * WalletService::renewSubscriptionFromBalance).
 */

require_once __DIR__ . '/bootstrap.php';

$startedAt = microtime(true);

try {
    if (!class_exists('SubscriptionLifecycleService')) {
        fwrite(STDOUT, '[' . date('Y-m-d H:i:s') . "] SubscriptionLifecycleService غير موجودة - تأكد من رفع الملفات:\n");
        fwrite(STDOUT, "  app/Services/Subscription/SubscriptionLifecycleService.php\n");
        exit(0);
    }
    if (!class_exists('InvoiceLifecycleService')) {
        fwrite(STDOUT, '[' . date('Y-m-d H:i:s') . "] InvoiceLifecycleService غير موجودة - تأكد من رفع الملفات:\n");
        fwrite(STDOUT, "  app/Services/Payment/InvoiceLifecycleService.php\n");
        exit(0);
    }

    $subResult = (new SubscriptionLifecycleService())->runLifecycleChecks();
    $invResult = (new InvoiceLifecycleService())->runLifecycleChecks();
    // اشتراكات المواقع (منشئ المواقع): تجديد من المحفظة، وبعد فترة السماح الموقع بيقف
    $siteResult = class_exists('SiteSubscriptionService') ? (new SiteSubscriptionService())->runRenewals() : [];
    $durationMs = round((microtime(true) - $startedAt) * 1000);

    $renewals = $subResult['auto_renewals'] ?? [];
    fwrite(STDOUT, sprintf(
        "[%s] Billing lifecycle run (%dms)\n" .
        "  Subscriptions: %d renewed, %d insufficient_balance, %d skipped, %d failed (of %d attempted)\n" .
        "  Transitions: %d past_due, %d cancelled_at_period_end, %d trials_ended, %d grace_cancelled\n" .
        "  Reminders: %d early, %d normal, %d dunning_final\n" .
        "  Invoices: %d overdue, %d refunded\n" .
        "  Sites: %d renewed, %d past_due, %d stopped\n",
        date('Y-m-d H:i:s'),
        $durationMs,
        (int) ($renewals['renewed'] ?? 0),
        (int) ($renewals['insufficient_balance'] ?? 0),
        (int) ($renewals['skipped'] ?? 0),
        (int) ($renewals['failed'] ?? 0),
        (int) ($renewals['attempted'] ?? 0),
        (int) ($subResult['moved_to_past_due'] ?? 0),
        (int) ($subResult['cancelled_at_period_end'] ?? 0),
        (int) ($subResult['trials_ended'] ?? 0),
        (int) ($subResult['moved_to_cancelled'] ?? 0),
        (int) ($subResult['early_renewal_reminders_sent'] ?? 0),
        (int) ($subResult['renewal_reminders_sent'] ?? 0),
        (int) ($subResult['dunning_final_notices_sent'] ?? 0),
        (int) ($invResult['marked_overdue'] ?? 0),
        (int) ($invResult['marked_refunded'] ?? 0),
        (int) ($siteResult['renewed'] ?? 0),
        (int) ($siteResult['past_due'] ?? 0),
        (int) ($siteResult['stopped'] ?? 0)
    ));

    // لو فيه أخطاء تجديد - نتائجها مهمة لمالك المنصة فبيتكتب عنها سطر واضح
    if (!empty($renewals['errors'])) {
        foreach ($renewals['errors'] as $err) {
            fwrite(STDOUT, '  [renewal error] subscription #' . ($err['subscription_id'] ?? '?') . ': ' . ($err['message'] ?? $err['reason'] ?? 'unknown') . "\n");
        }
    }
} catch (Throwable $e) {
    fwrite(STDERR, '[' . date('Y-m-d H:i:s') . '] خطأ: ' . $e->getMessage() . "\n");
    exit(1);
}
