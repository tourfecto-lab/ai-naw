<?php

/**
 * Tourfecto - تكوين OpenAI API (جزء من AI Provider Abstraction لـ AI Chat)
 * @version 1.0.0
 * @author Tourfecto Team
 * @copyright 2026 Tourfecto
 */

define('OPENAI_API_KEY', env('OPENAI_API_KEY') ?: '');
define('OPENAI_API_BASE_URL', env('OPENAI_API_BASE_URL') ?: 'https://api.openai.com/v1');
define('OPENAI_MODEL', env('OPENAI_MODEL') ?: 'gpt-4o-mini');

define('OPENAI_TEMPERATURE', 0.7);
define('OPENAI_MAX_TOKENS', 2000);

// تكلفة تقريبية للدولار لكل 1000 توكن (تُستخدم فقط لتقدير التكلفة في
// ai_usage_logs، وليست مصدرًا رسميًا للفوترة - يُفضّل تحديثها دوريًا).
define('OPENAI_COST_PER_1K_INPUT_TOKENS', 0.00015);
define('OPENAI_COST_PER_1K_OUTPUT_TOKENS', 0.0006);

define('OPENAI_TIMEOUT', 60);
define('OPENAI_CONNECT_TIMEOUT', 10);
define('OPENAI_MAX_RETRIES', 3);
define('OPENAI_RETRY_DELAY', 1);
