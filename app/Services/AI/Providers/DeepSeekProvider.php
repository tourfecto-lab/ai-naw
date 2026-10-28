<?php

/**
 * Tourfecto - AI Chat Platform - DeepSeek Provider Adapter
 * @version 1.0.0
 */

class DeepSeekProvider extends OpenAICompatibleProvider
{
    public function getName(): string
    {
        return 'deepseek';
    }
    protected function getApiKey(): string
    {
        if(class_exists('AiServiceRouting'))return AiServiceRouting::apiKey('deepseek');
        return defined('DEEPSEEK_API_KEY') ? DEEPSEEK_API_KEY : '';
    }
    protected function getBaseUrl(): string
    {
        return defined('DEEPSEEK_API_BASE_URL') ? DEEPSEEK_API_BASE_URL : 'https://api.deepseek.com/v1';
    }
    protected function getModel(): string
    {
        return defined('DEEPSEEK_MODEL') ? DEEPSEEK_MODEL : 'deepseek-chat';
    }
    protected function getDefaultTemperature(): float
    {
        return defined('DEEPSEEK_TEMPERATURE') ? DEEPSEEK_TEMPERATURE : 0.7;
    }
    protected function getDefaultMaxTokens(): int
    {
        return defined('DEEPSEEK_MAX_TOKENS') ? DEEPSEEK_MAX_TOKENS : 2000;
    }
    protected function getTimeout(): int
    {
        return defined('DEEPSEEK_TIMEOUT') ? DEEPSEEK_TIMEOUT : 60;
    }
    protected function getMaxRetries(): int
    {
        return defined('DEEPSEEK_MAX_RETRIES') ? DEEPSEEK_MAX_RETRIES : 3;
    }
    protected function getCostPer1kInput(): float
    {
        return defined('DEEPSEEK_COST_PER_1K_INPUT_TOKENS') ? DEEPSEEK_COST_PER_1K_INPUT_TOKENS : 0;
    }
    protected function getCostPer1kOutput(): float
    {
        return defined('DEEPSEEK_COST_PER_1K_OUTPUT_TOKENS') ? DEEPSEEK_COST_PER_1K_OUTPUT_TOKENS : 0;
    }
}
