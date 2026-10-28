<?php

/**
 * Tourfecto - Stripe Integration
 * @version 1.0.0
 *
 * ده الـ "template" اللي تنسخ منه لأي API جديد نوعه api_key (زي
 * SendGrid, Mailgun, HubSpot, OneSignal...). كل اللي محتاج تغيّره:
 * baseUrl()، buildHeaders()، وactions جوه request().
 */
class StripeIntegration extends BaseApiKeyIntegration
{
    public function key(): string
    {
        return 'stripe';
    }

    public function authType(): string
    {
        return 'api_key';
    }

    public function isConfigured(): bool
    {
        return IntegrationManager::isConfigured('stripe');
    }

    protected function baseUrl(): string
    {
        return 'https://api.stripe.com/v1';
    }

    protected function buildHeaders(): array
    {
        $apiKey = defined('STRIPE_API_KEY') ? STRIPE_API_KEY : (getenv('STRIPE_API_KEY') ?: (isset($_ENV['STRIPE_API_KEY']) ? (string) $_ENV['STRIPE_API_KEY'] : false));
        return [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/x-www-form-urlencoded',
        ];
    }

    public function request(string $action, array $params = [], array $context = []): array
    {
        switch ($action) {
            case 'create_charge':
                return $this->httpRequest('POST', '/charges', $params);
            case 'get_customer':
                return $this->httpRequest('GET', '/customers/' . ($params['id'] ?? ''));
            case 'create_subscription':
                return $this->httpRequest('POST', '/subscriptions', $params);
            default:
                return ['success' => false, 'data' => null, 'error' => "action '{$action}' غير مدعوم في StripeIntegration"];
        }
    }
}
