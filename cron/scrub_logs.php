<?php
/**
 * Tourfecto - تنظيف ملفات اللوج من الباسوردات والتوكنات (يومي من المنظّم)
 *
 * اللوجات الجديدة بتتنضف أول بأول (LogRedactor جوه Logger)، السكربت ده
 * بينضف السطور القديمة اللي اتكتبت قبل الإصلاح، وأي سطر وصل من مكان تاني
 * (php_errors.log / لوجات الكرون).
 *
 * تشغيل يدوي مرة واحدة بعد الرفع:
 *   php cron/scrub_logs.php
 */

require_once __DIR__ . '/bootstrap.php';
if (!class_exists('LogRedactor')) {
    require_once APP_PATH . '/Core/LogRedactor.php';
}

$base = defined('TOURFECTO_STORAGE') ? TOURFECTO_STORAGE : dirname(__DIR__) . '/storage';
$files = array_merge(
    glob($base . '/logs/*.log') ?: [],
    glob($base . '/logs/cron/*.log') ?: []
);

$total = 0;
foreach ($files as $f) {
    // ملفات ضخمة جدًا (أكبر من 200MB) نسيبها عشان مانعلّقش السيرفر
    if (filesize($f) > 200 * 1024 * 1024) {
        echo basename($f) . ": skipped (too large)\n";
        continue;
    }
    $n = LogRedactor::scrubFile($f);
    $total += $n;
    echo basename($f) . ": {$n} lines cleaned\n";
}
echo "done: {$total} lines cleaned in " . count($files) . " files\n";
