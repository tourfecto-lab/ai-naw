<?php

/**
 * Tourfecto - تكوين Gemini API
 * @version 1.0.0
 * @author Tourfecto Team
 * @copyright 2026 Tourfecto
 */

// ============================================
// إعدادات Gemini API الأساسية
// ============================================
define('GEMINI_API_KEY', env('GEMINI_API_KEY') ?: '');
define('GEMINI_API_VERSION', 'v1beta');
define('GEMINI_API_URL', 'https://generativelanguage.googleapis.com/' . GEMINI_API_VERSION);
define('GEMINI_MODEL', env('GEMINI_MODEL') ?: 'gemini-flash-latest');
define('GEMINI_MODEL_ALT', env('GEMINI_MODEL_ALT') ?: 'gemini-2.5-pro');

// موديل توليد الفيديو (Veo) - بنفس GEMINI_API_KEY، عبر Gemini API
// (predictLongRunning) مش Vertex AI - مفيش حاجة تضاف في .env غير
// الاختيار الاختياري ده لو عايز موديل مختلف (مثلاً Veo كامل بدل Fast).
define('VEO_MODEL', env('VEO_MODEL') ?: 'veo-3.1-fast-generate-preview');

// ============================================
// إعدادات الموديل
// ============================================
define('GEMINI_TEMPERATURE', 0.7);
define('GEMINI_MAX_TOKENS', 8192);
define('GEMINI_TOP_P', 0.95);
define('GEMINI_TOP_K', 40);
define('GEMINI_STOP_SEQUENCES', []);

// ============================================
// إعدادات السلامة (Safety Settings)
// ============================================
define('GEMINI_SAFETY_SETTINGS', [
    [
        'category' => 'HARM_CATEGORY_HARASSMENT',
        'threshold' => 'BLOCK_MEDIUM_AND_ABOVE'
    ],
    [
        'category' => 'HARM_CATEGORY_HATE_SPEECH',
        'threshold' => 'BLOCK_MEDIUM_AND_ABOVE'
    ],
    [
        'category' => 'HARM_CATEGORY_SEXUALLY_EXPLICIT',
        'threshold' => 'BLOCK_MEDIUM_AND_ABOVE'
    ],
    [
        'category' => 'HARM_CATEGORY_DANGEROUS_CONTENT',
        'threshold' => 'BLOCK_MEDIUM_AND_ABOVE'
    ]
]);

// ============================================
// إعدادات التكلفة والميزانية
// ============================================
define('GEMINI_COST_PER_1K_INPUT_TOKENS', 0.000125); // دولار
define('GEMINI_COST_PER_1K_OUTPUT_TOKENS', 0.000375); // دولار
define('GEMINI_DAILY_BUDGET', 10.00); // دولار
define('GEMINI_MONTHLY_BUDGET', 100.00); // دولار

// ============================================
// إعدادات التخزين المؤقت للـ API
// ============================================
define('GEMINI_CACHE_ENABLED', true);
define('GEMINI_CACHE_DURATION', 86400); // 24 ساعة
define('GEMINI_CACHE_PREFIX', 'gemini_response_');

// ============================================
// إعدادات الموديلات المتاحة
// ============================================
define('GEMINI_AVAILABLE_MODELS', [
    'gemini-flash-latest' => [
        'name' => 'Gemini Flash (Latest)',
        'max_tokens' => 8192,
        'description' => 'Fast and efficient model for real-time applications - auto-updated alias'
    ],
    'gemini-2.5-flash' => [
        'name' => 'Gemini 2.5 Flash',
        'max_tokens' => 8192,
        'description' => 'Balanced speed/quality model'
    ],
    'gemini-2.5-pro' => [
        'name' => 'Gemini 2.5 Pro',
        'max_tokens' => 8192,
        'description' => 'High-performance model for complex tasks'
    ]
]);

// ============================================
// إعدادات المهلة وإعادة المحاولة
// ============================================
define('GEMINI_TIMEOUT', 60);
define('GEMINI_CONNECT_TIMEOUT', 10);
define('GEMINI_MAX_RETRIES', 3);
define('GEMINI_RETRY_DELAY', 1); // ثانية
define('GEMINI_RETRY_BACKOFF', 2); // عامل التضاعف

// ============================================
// إعدادات الـ Prompts
// ============================================
define('GEMINI_SYSTEM_PROMPTS', [
    'seo_analysis' => 'أنت استشاري SEO وAEO وGEO أول، عندك خبرة 15 سنة مع شركات السياحة والسفر. شغلك دقيق ومبني على الدليل: مابتخترعش أرقام ولا ادعاءات، وكل توصية بتكتبها محددة وقابلة للتنفيذ فورًا.',
    'sentiment_analysis' => 'أنت خبير تحليل مشاعر محترف.',
    'reply_generation' => 'أنت ممثل خدمة عملاء محترف في شركة سياحة.',
    'chat_assistant' => 'أنت مساعد سياحي ذكي ومحترف.',
    'translation' => 'أنت مترجم محترف متخصص في السياحة والسفر.'
]);

// ============================================
// إعدادات تنسيق المخرجات
// ============================================
define('GEMINI_OUTPUT_FORMAT', 'json'); // json, text, markdown
define('GEMINI_JSON_FIX_ENABLED', true);
define('GEMINI_STRIP_MARKDOWN', true);
