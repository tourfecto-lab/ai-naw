<?php

/**
 * Tourfecto - Search Terms Autopilot (يوميًا)
 * @version 1.0.0
 *
 * بيقرا تقرير مصطلحات البحث لكل حملة Google منشورة ويقترح كلمات سلبية
 * للبحث اللي بيصرف من غير حجوزات (وبيضيفها تلقائيًا في وضع Autopilot).
 * محمي بـ File Lock لمنع التداخل.
 */

require_once __DIR__ . '/bootstrap.php';

$lockFile = sys_get_temp_dir() . '/tourfecto_ads_search_terms.lock';
$lockHandle = @fopen($lockFile, 'c');

if ($lockHandle && !flock($lockHandle, LOCK_EX | LOCK_NB)) {
    fwrite(STDOUT, sprintf("[%s] Ads Search Terms skipped: previous instance is still running.\n", date('Y-m-d H:i:s')));
    exit(0);
}

$startedAt = microtime(true);

try {
    $summary = (new AdSearchTermsService())->runForAllUsers();
    fwrite(STDOUT, sprintf(
        "[%s] Ads Search Terms: campaigns=%d suggested=%d auto_applied=%d errors=%d (%dms)\n",
        date('Y-m-d H:i:s'),
        $summary['campaigns'] ?? 0,
        $summary['suggested'] ?? 0,
        $summary['auto_applied'] ?? 0,
        $summary['errors'] ?? 0,
        round((microtime(true) - $startedAt) * 1000)
    ));
} catch (Throwable $e) {
    fwrite(STDERR, '[' . date('Y-m-d H:i:s') . '] Ads Search Terms error: ' . $e->getMessage() . "\n");
    if (class_exists('Logger')) {
        Logger::error('Ads Search Terms scheduler failed', ['error' => $e->getMessage()]);
    }
    exit(1);
} finally {
    if ($lockHandle) {
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
    }
}
