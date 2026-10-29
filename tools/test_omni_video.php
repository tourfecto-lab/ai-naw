<?php

/**
 * One real Gemini Omni video generation, outside the queue, to check the Google call and the quality.
 * It uses the platform's Google key (a real, billed API call).
 *   sudo -u www /www/server/php/83/bin/php tools/test_omni_video.php ["prompt"] [9:16|16:9] [720p|1080p]
 * Saves public_html/uploads/_omni_test_<random>.mp4 and prints its URL; delete the file after watching it.
 */
if (PHP_SAPI !== 'cli') {
    exit("CLI only\n");
}
require_once __DIR__ . '/../cron/bootstrap.php';

$prompt = $argv[1] ?? 'A felucca glides slowly along the Nile at sunset, the sail gently moving in the breeze, soft ripples on the water, warm golden light, authentic travel mood';
$ratio = $argv[2] ?? '9:16';
$resolution = $argv[3] ?? '720p';
$client = new GeminiOmniClient(GeminiOmniClient::DEFAULT_MODEL);
$t0 = microtime(true);
$r = $client->startGeneration($prompt, $ratio, 8, ['resolution' => $resolution]);
printf("Generation call: %s in %.0fs\n", !empty($r['success']) ? 'OK' : 'FAILED', microtime(true) - $t0);
if (empty($r['success'])) {
    echo 'Error: ' . substr((string) ($r['error'] ?? ''), 0, 400) . "\n";
    exit(1);
}
$bytes = $r['video_data'] ?? null;
if ($bytes === null) {
    $d = $client->downloadVideo((string) $r['video_uri']);
    if (empty($d['success'])) {
        echo 'Download failed: ' . ($d['error'] ?? '') . "\n";
        exit(1);
    }
    $bytes = $d['data'];
}
$name = '_omni_test_' . bin2hex(random_bytes(6)) . '.mp4';
$dir = ROOT_PATH . '/public_html/uploads';
if (!is_dir($dir)) {
    mkdir($dir, 0755, true);
}
file_put_contents($dir . '/' . $name, $bytes);
printf("Saved %s (%.1f MB), valid MP4 header: %s\n", $dir . '/' . $name, strlen($bytes) / 1048576, substr($bytes, 4, 4) === 'ftyp' ? 'yes' : 'NO');
echo "Watch it at: /uploads/{$name}   (delete the file afterwards)\n";
