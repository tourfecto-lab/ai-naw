<?php

/**
 * Tourfecto - AI Chat Platform
 * محوّل (Adapter) لمزود Gemini، يبني فوق GeminiClient الموجود والمُختبر
 * فعليًا في المشروع (app/Services/AI/GeminiClient.php) بدون أي تعديل
 * عليه، حتى لا يتأثر أي Feature آخر (SEO/AEO/GEO...) يستخدمه بالفعل.
 *
 * GeminiClient::generateContent() يقبل نص Prompt واحد فقط (وليس مصفوفة
 * رسائل متعددة الأدوار)، لذلك هذا المحوّل يجمّع system prompt + تاريخ
 * المحادثة + آخر رسالة في نص واحد منسّق قبل إرساله - وهذا كافٍ عمليًا
 * ومتوافق 100% مع الكود المختبر الموجود.
 *
 * @version 1.0.0
 */

class GeminiProvider implements AIProviderInterface
{
    /** @var GeminiClient */
    private $client;

    public function __construct()
    {
        $this->client = new GeminiClient();
    }

    public function getName(): string
    {
        return 'gemini';
    }

    public function isConfigured(): bool
    {
        if(class_exists('AiServiceRouting'))return AiServiceRouting::apiKey('gemini')!=='';
        $key = class_exists('SystemSettingsService')
            ? (new SystemSettingsService())->get('gemini_api_key', GEMINI_API_KEY)
            : GEMINI_API_KEY;
        return !empty($key);
    }

    public function generateReply(string $systemPrompt, array $messages, array $options = []): array
    {
        $startTime = microtime(true);
        $prompt = $this->buildFlatPrompt($systemPrompt, $messages);

        $geminiOptions = ['_routing_bypass'=>true,'task'=>$options['feature']??'chat_reply','user_id'=>$options['user_id']??null];
        if(!empty($options['_routing_exact'])){$geminiOptions['_routing_exact']=true;$geminiOptions['model']=$options['model'];}
        if (isset($options['temperature'])) {
            $geminiOptions['temperature'] = (float) $options['temperature'];
        }
        if (isset($options['max_tokens'])) {
            $geminiOptions['maxOutputTokens'] = (int) $options['max_tokens'];
        }

        $response = $this->client->generateContent($prompt, $geminiOptions);
        $durationMs = (int) round((microtime(true) - $startTime) * 1000);

        if (empty($response['success'])) {
            return [
                'success' => false,
                'content' => null,
                'provider' => $this->getName(),
                'model' => $response['model'] ?? ($options['model'] ?? GEMINI_MODEL),
                'tokens_input' => 0,
                'tokens_output' => 0,
                'tokens_total' => 0,
                'estimated_cost_usd' => 0,
                'duration_ms' => $durationMs,
                'error' => $response['error'] ?? 'Unknown Gemini error',
            ];
        }

        $tokensTotal = (int) ($response['tokens_used'] ?? 0);

        return [
            'success' => true,
            'content' => trim((string) $response['data']),
            'provider' => $this->getName(),
            'model' => $response['model'] ?? ($options['model'] ?? GEMINI_MODEL),
            'tokens_input' => 0, // GeminiClient الحالي لا يفصل input/output، فقط الإجمالي
            'tokens_output' => 0,
            'tokens_total' => $tokensTotal,
            'estimated_cost_usd' => (float) ($response['cost'] ?? 0),
            'duration_ms' => $durationMs,
            'error' => null,
        ];
    }

    /**
     * تحويل system prompt + تاريخ المحادثة إلى نص واحد مهيكل يفهمه Gemini.
     * @param string $systemPrompt
     * @param array $messages
     * @return string
     */
    private function buildFlatPrompt(string $systemPrompt, array $messages): string
    {
        $parts = [];
        $parts[] = "### SYSTEM INSTRUCTIONS ###\n" . $systemPrompt;

        if (!empty($messages)) {
            $parts[] = "### CONVERSATION HISTORY ###";
            foreach ($messages as $message) {
                $role = strtoupper($message['role'] ?? 'user');
                $content = (string) ($message['content'] ?? '');
                $parts[] = "{$role}: {$content}";
            }
        }

        $parts[] = "### YOUR TASK ###\nRespond as ASSISTANT to the last USER message, following the SYSTEM INSTRUCTIONS strictly.";

        return implode("\n\n", $parts);
    }
}
