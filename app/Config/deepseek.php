<?php

/**
 * Tourfecto - تكوين DeepSeek API (جزء من AI Provider Abstraction لـ AI Chat)
 * @version 1.0.0
 * @author Tourfecto Team
 * @copyright 2026 Tourfecto
 */

define('DEEPSEEK_API_KEY', env('DEEPSEEK_API_KEY') ?: '');
define('DEEPSEEK_API_BASE_URL', env('DEEPSEEK_API_BASE_URL') ?: 'https://api.deepseek.com/v1');
define('DEEPSEEK_MODEL', env('DEEPSEEK_MODEL') ?: 'deepseek-chat');

define('DEEPSEEK_TEMPERATURE', 0.7);
define('DEEPSEEK_MAX_TOKENS', 2000);

define('DEEPSEEK_COST_PER_1K_INPUT_TOKENS', 0.00014);
define('DEEPSEEK_COST_PER_1K_OUTPUT_TOKENS', 0.00028);

define('DEEPSEEK_TIMEOUT', 60);
define('DEEPSEEK_CONNECT_TIMEOUT', 10);
define('DEEPSEEK_MAX_RETRIES', 3);
define('DEEPSEEK_RETRY_DELAY', 1);
