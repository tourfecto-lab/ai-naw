<?php

/**
 * Tourfecto - scheduled ads sync (Meta + Google + TikTok): campaigns, status, budgets and daily results.
 * Registered in cron/schedule.php as "ads_sync" (every 2 hours). Guarded by a file lock against overlapping runs.
 */

require_once __DIR__ . '/bootstrap.php';

$lockHandle = @fopen(sys_get_temp_dir() . '/tourfecto_ads_sync.lock', 'c');
if ($lockHandle && !flock($lockHandle, LOCK_EX | LOCK_NB)) {
    fwrite(STDOUT, sprintf("[%s] Ads sync skipped: previous instance is still running.\n", date('Y-m-d H:i:s')));
    exit(0);
}

$startedAt = microtime(true);
try {
    $s = (new AdsSyncService())->syncAll();
    fwrite(STDOUT, sprintf(
        "[%s] Ads sync: accounts=%d synced=%d failed=%d skipped=%d (%dms)\n",
        date('Y-m-d H:i:s'), $s['accounts'], $s['synced'], $s['failed'], $s['skipped'], round((microtime(true) - $startedAt) * 1000)
    ));
} catch (Throwable $e) {
    fwrite(STDERR, '[' . date('Y-m-d H:i:s') . '] Ads sync error: ' . $e->getMessage() . "\n");
    if (class_exists('Logger')) {
        Logger::error('Ads sync scheduler failed', ['error' => $e->getMessage()]);
    }
    exit(1);
} finally {
    if ($lockHandle) {
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
    }
}
