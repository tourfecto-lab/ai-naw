<?php

/**
 * Tourfecto - AI Chat Platform - Kimi (Moonshot AI) Provider Adapter
 * @version 1.0.0
 */

class KimiProvider extends OpenAICompatibleProvider
{
    public function getName(): string
    {
        return 'kimi';
    }
    protected function getApiKey(): string
    {
        if(class_exists('AiServiceRouting'))return AiServiceRouting::apiKey('kimi');
        return defined('KIMI_API_KEY') ? KIMI_API_KEY : '';
    }
    protected function getBaseUrl(): string
    {
        return defined('KIMI_API_BASE_URL') ? KIMI_API_BASE_URL : 'https://api.moonshot.ai/v1';
    }
    protected function getModel(): string
    {
        return defined('KIMI_MODEL') ? KIMI_MODEL : 'moonshot-v1-8k';
    }
    protected function getDefaultTemperature(): float
    {
        return defined('KIMI_TEMPERATURE') ? KIMI_TEMPERATURE : 0.7;
    }
    protected function getDefaultMaxTokens(): int
    {
        return defined('KIMI_MAX_TOKENS') ? KIMI_MAX_TOKENS : 2000;
    }
    protected function getTimeout(): int
    {
        return defined('KIMI_TIMEOUT') ? KIMI_TIMEOUT : 60;
    }
    protected function getMaxRetries(): int
    {
        return defined('KIMI_MAX_RETRIES') ? KIMI_MAX_RETRIES : 3;
    }
    protected function getCostPer1kInput(): float
    {
        return defined('KIMI_COST_PER_1K_INPUT_TOKENS') ? KIMI_COST_PER_1K_INPUT_TOKENS : 0;
    }
    protected function getCostPer1kOutput(): float
    {
        return defined('KIMI_COST_PER_1K_OUTPUT_TOKENS') ? KIMI_COST_PER_1K_OUTPUT_TOKENS : 0;
    }
}
