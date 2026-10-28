<?php

/**
 * Tourfecto - AI Chat Platform - Anthropic Claude Provider Adapter
 * يتوافق مع صيغة /v1/messages الخاصة بـ Anthropic (ليست OpenAI-compatible):
 *   - Headers: x-api-key + anthropic-version بدل Authorization: Bearer
 *   - system حقل مستقل أعلى الرسائل (ليس role داخل messages)
 *   - max_tokens إلزامي
 *   - الرد: content[] مصفوفة أجزاء نصية + usage{input_tokens, output_tokens}
 *
 * @version 1.0.0
 */

class AnthropicProvider implements AIProviderInterface
{
    public function getName(): string
    {
        return 'anthropic';
    }

    public function isConfigured(): bool
    {
        return !empty($this->getApiKey());
    }

    public function generateReply(string $systemPrompt, array $messages, array $options = []): array
    {
        $startTime = microtime(true);

        if (!$this->isConfigured()) {
            return $this->failure('Provider not configured: missing ANTHROPIC_API_KEY', 0);
        }

        $chatMessages = [];
        foreach ($messages as $message) {
            $role = ($message['role'] ?? 'user') === 'assistant' ? 'assistant' : 'user';
            $content = (string) ($message['content'] ?? '');
            if ($content === '') {
                continue;
            }
            $chatMessages[] = ['role' => $role, 'content' => $content];
        }

        if (count($chatMessages) === 0) {
            $chatMessages[] = ['role' => 'user', 'content' => $systemPrompt];
            $system = '';
        } else {
            $system = $systemPrompt;
        }

        $payload = [
            'model' => $options['model'] ?? $this->getModel(),
            'max_tokens' => (int) ($options['max_tokens'] ?? $this->getDefaultMaxTokens()),
            'temperature' => (float) ($options['temperature'] ?? $this->getDefaultTemperature()),
            'messages' => $chatMessages,
        ];
        if ($system !== '') {
            $payload['system'] = $system;
        }

        $result = $this->sendWithRetry($payload);
        $durationMs = (int) round((microtime(true) - $startTime) * 1000);

        if (!$result['success']) {
            return $this->failure($result['error'], $durationMs);
        }

        $data = $result['data'];

        $content = '';
        if (isset($data['content']) && is_array($data['content'])) {
            foreach ($data['content'] as $part) {
                if (isset($part['type']) && $part['type'] === 'text' && isset($part['text'])) {
                    $content .= $part['text'];
                }
            }
        }

        if ($content === '') {
            return $this->failure('Unexpected response format from ' . $this->getName(), $durationMs);
        }

        $tokensInput = (int) ($data['usage']['input_tokens'] ?? 0);
        $tokensOutput = (int) ($data['usage']['output_tokens'] ?? 0);
        $tokensTotal = $tokensInput + $tokensOutput;
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
     * إرسال الطلب إلى /v1/messages مع إعادة محاولة (نفس أسلوب بقية المزودين).
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
                $ch = curl_init($this->getApiUrl());
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => json_encode($payload),
                    CURLOPT_HTTPHEADER => [
                        'Content-Type: application/json',
                        'x-api-key: ' . $this->getApiKey(),
                        'anthropic-version: ' . $this->getVersion(),
                    ],
                    CURLOPT_TIMEOUT => $this->getTimeout(),
                    CURLOPT_CONNECTTIMEOUT => $this->getConnectTimeout(),
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
                    throw new Exception('anthropic API Error: ' . $errorMessage);
                }

                if (!is_array($data)) {
                    throw new Exception('Invalid JSON response from anthropic');
                }

                return ['success' => true, 'data' => $data, 'error' => null];

            } catch (Exception $e) {
                $lastError = $e->getMessage();
                Logger::warning('anthropic provider attempt failed', [
                    'attempt' => $attempt + 1,
                    'error' => $lastError,
                ]);
                $attempt++;
                if ($attempt < $maxRetries) {
                    sleep(pow(2, $attempt));
                }
            }
        }

        return ['success' => false, 'data' => null, 'error' => $lastError];
    }

    private function getApiKey(): string
    {
        if(class_exists('AiServiceRouting'))return AiServiceRouting::apiKey('anthropic');
        return defined('ANTHROPIC_API_KEY') ? ANTHROPIC_API_KEY : '';
    }

    private function getApiUrl(): string
    {
        return defined('ANTHROPIC_API_URL') ? ANTHROPIC_API_URL : 'https://api.anthropic.com/v1/messages';
    }

    private function getVersion(): string
    {
        return defined('ANTHROPIC_VERSION') ? ANTHROPIC_VERSION : '2023-06-01';
    }

    private function getModel(): string
    {
        return defined('ANTHROPIC_MODEL') ? ANTHROPIC_MODEL : 'claude-sonnet-4-5';
    }

    private function getDefaultTemperature(): float
    {
        return defined('ANTHROPIC_TEMPERATURE') ? ANTHROPIC_TEMPERATURE : 0.7;
    }

    private function getDefaultMaxTokens(): int
    {
        return defined('ANTHROPIC_MAX_TOKENS') ? ANTHROPIC_MAX_TOKENS : 8192;
    }

    private function getTimeout(): int
    {
        return defined('ANTHROPIC_TIMEOUT') ? ANTHROPIC_TIMEOUT : 60;
    }

    private function getConnectTimeout(): int
    {
        return defined('ANTHROPIC_CONNECT_TIMEOUT') ? ANTHROPIC_CONNECT_TIMEOUT : 10;
    }

    private function getMaxRetries(): int
    {
        return defined('ANTHROPIC_MAX_RETRIES') ? ANTHROPIC_MAX_RETRIES : 3;
    }

    private function getCostPer1kInput(): float
    {
        return defined('ANTHROPIC_COST_PER_1K_INPUT_TOKENS') ? ANTHROPIC_COST_PER_1K_INPUT_TOKENS : 0.003;
    }

    private function getCostPer1kOutput(): float
    {
        return defined('ANTHROPIC_COST_PER_1K_OUTPUT_TOKENS') ? ANTHROPIC_COST_PER_1K_OUTPUT_TOKENS : 0.015;
    }
}
