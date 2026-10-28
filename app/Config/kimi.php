<?php

/**
 * Tourfecto - تكوين Kimi / Moonshot AI API (جزء من AI Provider Abstraction لـ AI Chat)
 * @version 1.0.0
 * @author Tourfecto Team
 * @copyright 2026 Tourfecto
 */

define('KIMI_API_KEY', env('KIMI_API_KEY') ?: '');
define('KIMI_API_BASE_URL', env('KIMI_API_BASE_URL') ?: 'https://api.moonshot.ai/v1');
define('KIMI_MODEL', env('KIMI_MODEL') ?: 'moonshot-v1-8k');

define('KIMI_TEMPERATURE', 0.7);
define('KIMI_MAX_TOKENS', 2000);

define('KIMI_COST_PER_1K_INPUT_TOKENS', 0.0002);
define('KIMI_COST_PER_1K_OUTPUT_TOKENS', 0.0002);

define('KIMI_TIMEOUT', 60);
define('KIMI_CONNECT_TIMEOUT', 10);
define('KIMI_MAX_RETRIES', 3);
define('KIMI_RETRY_DELAY', 1);
