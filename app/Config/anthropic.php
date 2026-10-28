<?php

/**
 * Tourfecto - تكوين Anthropic Claude API
 * مزود Claude يطبّق AIProviderInterface ويتوافق مع صيغة /v1/messages
 * (x-api-key + anthropic-version بدل Authorization: Bearer).
 *
 * @version 1.0.0
 * @author Tourfecto Team
 * @copyright 2026 Tourfecto
 */

define('ANTHROPIC_API_KEY', env('ANTHROPIC_API_KEY') ?: '');
define('ANTHROPIC_API_URL', env('ANTHROPIC_API_URL') ?: 'https://api.anthropic.com/v1/messages');
define('ANTHROPIC_VERSION', '2023-06-01');
define('ANTHROPIC_MODEL', env('ANTHROPIC_MODEL') ?: 'claude-sonnet-4-5');

// ============================================
// إعدادات الموديل
// ============================================
define('ANTHROPIC_TEMPERATURE', 0.7);
define('ANTHROPIC_MAX_TOKENS', 8192);
define('ANTHROPIC_TOP_P', 0.95);

// ============================================
// إعدادات المهلة وإعادة المحاولة
// ============================================
define('ANTHROPIC_TIMEOUT', 60);
define('ANTHROPIC_CONNECT_TIMEOUT', 10);
define('ANTHROPIC_MAX_RETRIES', 3);

// ============================================
// إعدادات التكلفة (بالدولار لكل 1K توكن)
// Claude Sonnet 4.5: $3/1M input, $15/1M output تقريبًا
// ============================================
define('ANTHROPIC_COST_PER_1K_INPUT_TOKENS', 0.003);
define('ANTHROPIC_COST_PER_1K_OUTPUT_TOKENS', 0.015);
