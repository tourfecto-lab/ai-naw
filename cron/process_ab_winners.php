<?php

/**
 * Tourfecto - معالج إرسال فائز اختبار A/B تلقائيًا (Cron)
 * كل 10 دقائق:
 *   php /home/USERNAME/domains/YOURSITE.com/cron/process_ab_winners.php >> storage/logs/ab_winners.log 2>&1
 */

require_once __DIR__ . '/bootstrap.php';

try {
    if (!class_exists('AbTestAutoWinnerService')) {
        fwrite(STDOUT, '[' . date('Y-m-d H:i:s') . "] AbTestAutoWinnerService غير موجودة\n");
        exit(0);
    }
    $r = (new AbTestAutoWinnerService())->finalizeDue();
    fwrite(STDOUT, sprintf("[%s] AB auto-winner: checked=%d finalized=%d errors=%d\n", date('Y-m-d H:i:s'), $r['checked'], $r['finalized'], $r['errors']));
} catch (Throwable $e) {
    fwrite(STDERR, '[' . date('Y-m-d H:i:s') . '] خطأ: ' . $e->getMessage() . "\n");
    exit(1);
}
