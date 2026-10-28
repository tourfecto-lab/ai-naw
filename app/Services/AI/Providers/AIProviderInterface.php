<?php

/**
 * Tourfecto - AI Chat Platform
 * عقد موحّد (Interface) لأي مزود ذكاء اصطناعي يمكن استخدامه في AI Chat.
 *
 * الهدف: عدم ربط النظام بمزود واحد (بند 20 في المتطلبات). أي مزود جديد
 * (OpenAI, Gemini, DeepSeek, Kimi, أو أي مزود مستقبلي) يجب أن يطبّق هذا
 * العقد فقط، ويصبح قابلاً للاستخدام والتبديل عبر AIProviderManager
 * بدون أي تعديل في باقي كود AI Chat.
 *
 * @version 1.0.0
 * @author Tourfecto Team
 * @copyright 2026 Tourfecto
 */

interface AIProviderInterface
{
    /**
     * توليد رد نصي.
     *
     * @param string $systemPrompt تعليمات النظام (شخصية الـAI + قاعدة المعرفة + القواعد)
     * @param array $messages تاريخ المحادثة: [['role' => 'user'|'assistant', 'content' => '...'], ...]
     * @param array $options خيارات اختيارية: temperature, max_tokens, model...
     * @return array شكل موحّد:
     *   [
     *     'success' => bool,
     *     'content' => string|null,          // نص الرد
     *     'provider' => string,               // اسم المزود
     *     'model' => string|null,
     *     'tokens_input' => int,
     *     'tokens_output' => int,
     *     'tokens_total' => int,
     *     'estimated_cost_usd' => float,
     *     'duration_ms' => int,
     *     'error' => string|null,
     *   ]
     */
    public function generateReply(string $systemPrompt, array $messages, array $options = []): array;

    /**
     * اسم المزود الفريد (openai, gemini, deepseek, kimi...).
     * @return string
     */
    public function getName(): string;

    /**
     * هل المزود مهيّأ بشكل صحيح (مفتاح API موجود...) وجاهز للاستخدام؟
     * @return bool
     */
    public function isConfigured(): bool;
}
