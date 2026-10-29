<?php

/**
 * Lists the video-generation models the platform's Google key can use right now, and which one the Studio would pick.
 * Read-only; the key is read from the server settings and never printed.
 *   sudo -u www /www/server/php/83/bin/php tools/list_veo_models.php
 */
if (PHP_SAPI !== 'cli') {
    exit("CLI only\n");
}
require_once __DIR__ . '/../cron/bootstrap.php';

$client = new VeoClient();
$models = $client->listVideoModels();
echo 'Configured model : ' . (defined('VEO_MODEL') ? VEO_MODEL : 'veo-3.1-fast-generate-preview') . "\n";
echo 'Available (predictLongRunning): ' . ($models ? implode(', ', $models) : '(none - Google may have moved video to the Interactions API)') . "\n";
echo 'Gemini Omni models  : ' . implode(', ', (new GeminiOmniClient())->listOmniModels() ?: ['(none visible)']) . "\n";
echo 'Would switch to  : ' . (VeoClient::pickModel($models, defined('VEO_MODEL') ? VEO_MODEL : null) ?? '-') . "\n";
