<?php

/**
 * Tourfecto - AI Chat Platform - OpenAI Provider Adapter
 * @version 1.0.0
 */

class OpenAIProvider extends OpenAICompatibleProvider
{
    public function getName(): string
    {
        return 'openai';
    }
    protected function getApiKey(): string
    {
        if(class_exists('AiServiceRouting'))return AiServiceRouting::apiKey('openai');
        return defined('OPENAI_API_KEY') ? OPENAI_API_KEY : '';
    }
    protected function getBaseUrl(): string
    {
        return defined('OPENAI_API_BASE_URL') ? OPENAI_API_BASE_URL : 'https://api.openai.com/v1';
    }
    protected function getModel(): string
    {
        return defined('OPENAI_MODEL') ? OPENAI_MODEL : 'gpt-4o-mini';
    }
    protected function getDefaultTemperature(): float
    {
        return defined('OPENAI_TEMPERATURE') ? OPENAI_TEMPERATURE : 0.7;
    }
    protected function getDefaultMaxTokens(): int
    {
        return defined('OPENAI_MAX_TOKENS') ? OPENAI_MAX_TOKENS : 2000;
    }
    protected function getTimeout(): int
    {
        return defined('OPENAI_TIMEOUT') ? OPENAI_TIMEOUT : 60;
    }
    protected function getMaxRetries(): int
    {
        return defined('OPENAI_MAX_RETRIES') ? OPENAI_MAX_RETRIES : 3;
    }
    protected function getCostPer1kInput(): float
    {
        return defined('OPENAI_COST_PER_1K_INPUT_TOKENS') ? OPENAI_COST_PER_1K_INPUT_TOKENS : 0;
    }
    protected function getCostPer1kOutput(): float
    {
        return defined('OPENAI_COST_PER_1K_OUTPUT_TOKENS') ? OPENAI_COST_PER_1K_OUTPUT_TOKENS : 0;
    }
}
