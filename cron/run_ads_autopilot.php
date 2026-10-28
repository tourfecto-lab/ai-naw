<?php

/**
 * Tourfecto - AI Ads Autopilot: Enterprise Periodic Scheduler
 * @version 2.0.0
 *
 * محمي بـ File Lock لمنع التداخل الزمني (Overlapping Cron Runs)
 */

require_once __DIR__ . '/bootstrap.php';

$lockFile = sys_get_temp_dir() . '/tourfecto_ads_autopilot.lock';
$lockHandle = @fopen($lockFile, 'c');

if ($lockHandle && !flock($lockHandle, LOCK_EX | LOCK_NB)) {
    fwrite(STDOUT, sprintf("[%s] Ads Autopilot skipped: previous instance is still running.\n", date('Y-m-d H:i:s')));
    exit(0);
}

$startedAt = microtime(true);

try {
    $engine = new AdAutopilotEngine();
    $summary = $engine->runForAllUsers();

    $durationMs = round((microtime(true) - $startedAt) * 1000);
    fwrite(STDOUT, sprintf(
        "[%s] Ads Autopilot: processed=%d no_action=%d logged=%d pending=%d executed=%d insufficient_data=%d errors=%d (%dms)\n",
        date('Y-m-d H:i:s'),
        $summary['processed'] ?? 0,
        $summary['no_action'] ?? 0,
        $summary['logged'] ?? 0,
        $summary['pending'] ?? 0,
        $summary['executed'] ?? 0,
        $summary['insufficient_data'] ?? 0,
        $summary['errors'] ?? 0,
        $durationMs
    ));
} catch (Throwable $e) {
    fwrite(STDERR, '[' . date('Y-m-d H:i:s') . '] Ads Autopilot error: ' . $e->getMessage() . "\n");
    if (class_exists('Logger')) {
        Logger::error('Ads Autopilot scheduler failed', ['error' => $e->getMessage()]);
    }
    exit(1);
} finally {
    if ($lockHandle) {
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
    }
}