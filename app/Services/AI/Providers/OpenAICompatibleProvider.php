<?php

/**
 * Tourfecto - AI Chat Platform
 * كلاس أساسي مجرّد لأي مزود يتوافق مع صيغة OpenAI Chat Completions API
 * القياسية (POST {base_url}/chat/completions مع Authorization: Bearer).
 * OpenAI وDeepSeek وKimi/Moonshot الثلاثة يستخدمون نفس الصيغة تمامًا،
 * فبدل تكرار كود الـcURL 3 مرات، كل مزود منهم مجرد إعدادات (API key,
 * base url, model, تكلفة) فوق هذا الكلاس الواحد.
 *
 * @version 1.0.0
 */

abstract class OpenAICompatibleProvider implements AIProviderInterface
{
    /** إعداد كل مزود فرعي - يجب تنفيذها في الكلاس الابن */
    abstract protected function getApiKey(): string;
    abstract protected function getBaseUrl(): string;
    abstract protected function getModel(): string;
    abstract protected function getDefaultTemperature(): float;
    abstract protected function getDefaultMaxTokens(): int;
    abstract protected function getTimeout(): int;
    abstract protected function getMaxRetries(): int;
    abstract protected function getCostPer1kInput(): float;
    abstract protected function getCostPer1kOutput(): float;

    public function isConfigured(): bool
    {
        return !empty($this->getApiKey());
    }

    public function generateReply(string $systemPrompt, array $messages, array $options = []): array
    {
        $startTime = microtime(true);

        if (!$this->isConfigured()) {
            return $this->failure('Provider not configured: missing API key', 0);
        }

        $chatMessages = [['role' => 'system', 'content' => $systemPrompt]];
        foreach ($messages as $message) {
            $role = ($message['role'] ?? 'user') === 'assistant' ? 'assistant' : 'user';
            $chatMessages[] = ['role' => $role, 'content' => (string) ($message['content'] ?? '')];
        }

        $payload = [
            'model' => $options['model'] ?? $this->getModel(),
            'messages' => $chatMessages,
            'temperature' => (float) ($options['temperature'] ?? $this->getDefaultTemperature()),
            'max_tokens' => (int) ($options['max_tokens'] ?? $this->getDefaultMaxTokens()),
        ];

        $result = $this->sendWithRetry($payload);
        $durationMs = (int) round((microtime(true) - $startTime) * 1000);

        if (!$result['success']) {
            return $this->failure($result['error'], $durationMs);
        }

        $data = $result['data'];
        $content = $data['choices'][0]['message']['content'] ?? null;

        if ($content === null) {
            return $this->failure('Unexpected response format from ' . $this->getName(), $durationMs);
        }

        $tokensInput = (int) ($data['usage']['prompt_tokens'] ?? 0);
        $tokensOutput = (int) ($data['usage']['completion_tokens'] ?? 0);
        $tokensTotal = (int) ($data['usage']['total_tokens'] ?? ($tokensInput + $tokensOutput));
        $cost = ($tokensInput / 1000 * $this->getCostPer1kInput()) + ($tokensOutput / 1000 * $this->getCostPer1kOutput());

        return [
            'success' => true,
            'content' => trim((string) $content),
            'provider' => $this->getName(),
            'model' => $payload['model'],
            'tokens_input' => $tokensInput,
            'tokens_output' => $tokensOutput,
            'tokens_total' => $tokensTotal,
            'estimated_cost_usd' => round($cost, 6),
            'duration_ms' => $durationMs,
            'error' => null,
        ];
    }

    private function failure(string $error, int $durationMs): array
    {
        return [
            'success' => false,
            'content' => null,
            'provider' => $this->getName(),
            'model' => $this->getModel(),
            'tokens_input' => 0,
            'tokens_output' => 0,
            'tokens_total' => 0,
            'estimated_cost_usd' => 0,
            'duration_ms' => $durationMs,
            'error' => $error,
        ];
    }

    /**
     * إرسال الطلب مع إعادة محاولة (نفس أسلوب GeminiClient::sendRequest الموجود).
     * @param array $payload
     * @return array ['success'=>bool, 'data'=>array|null, 'error'=>string|null]
     */
    private function sendWithRetry(array $payload): array
    {
        $attempt = 0;
        $lastError = null;
        $maxRetries = max(1, $this->getMaxRetries());

        while ($attempt < $maxRetries) {
            try {
                $ch = curl_init(rtrim($this->getBaseUrl(), '/') . '/chat/completions');
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => json_encode($payload),
                    CURLOPT_HTTPHEADER => [
                        'Content-Type: application/json',
                        'Authorization: Bearer ' . $this->getApiKey(),
                    ],
                    CURLOPT_TIMEOUT => $this->getTimeout(),
                    CURLOPT_CONNECTTIMEOUT => 10,
                    CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_USERAGENT => 'Tourfecto/1.0',
                ]);

                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curlError = curl_error($ch);
                curl_close($ch);

                if ($curlError) {
                    throw new Exception('cURL Error: ' . $curlError);
                }

                $data = json_decode((string) $response, true);

                if ($httpCode !== 200) {
                    $errorMessage = $data['error']['message'] ?? ('HTTP ' . $httpCode);
                    throw new Exception("{$this->getName()} API Error: {$errorMessage}");
                }

                if (!is_array($data)) {
                    throw new Exception('Invalid JSON response from ' . $this->getName());
                }

                return ['success' => true, 'data' => $data, 'error' => null];

            } catch (Exception $e) {
                $lastError = $e->getMessage();
                Logger::warning($this->getName() . ' provider attempt failed', [
                    'attempt' => $attempt + 1,
                    'error' => $lastError,
                ]);
                $attempt++;
                if ($attempt < $maxRetries) {
                    sleep(pow(2, $attempt));
                }
            }
        }

        return ['success' => false, 'data' => null, 'error' => $lastError ?? 'Max retries exceeded'];
    }
}
