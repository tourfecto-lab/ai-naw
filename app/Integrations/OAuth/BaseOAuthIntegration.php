<?php

/**
 * Tourfecto - Base OAuth Integration
 * @version 1.0.0
 *
 * أي API بيتربط لكل عميل لوحده (OAuth) زي Search Console/Analytics/Ads
 * يورث من هنا. الفرق عن BaseApiKeyIntegration: الطلبات لازم access_token
 * خاص بكل عميل، بيجيلك في $context['access_token'] من platform_connections
 * (اللي هتجيبه في الـ Controller قبل ما تنادي request()).
 */
abstract class BaseOAuthIntegration implements IntegrationInterface
{
    abstract protected function authUrl(): string;
    abstract protected function tokenUrl(): string;
    abstract protected function scope(): string;
    abstract protected function apiBaseUrl(): string;

    public function authType(): string
    {
        return 'oauth';
    }

    protected function clientId(): string
    {
        return defined('GOOGLE_CLIENT_ID') ? GOOGLE_CLIENT_ID : ((getenv('GOOGLE_CLIENT_ID') ?: (isset($_ENV['GOOGLE_CLIENT_ID']) ? (string) $_ENV['GOOGLE_CLIENT_ID'] : false)) ?: '');
    }

    protected function clientSecret(): string
    {
        return defined('GOOGLE_CLIENT_SECRET') ? GOOGLE_CLIENT_SECRET : ((getenv('GOOGLE_CLIENT_SECRET') ?: (isset($_ENV['GOOGLE_CLIENT_SECRET']) ? (string) $_ENV['GOOGLE_CLIENT_SECRET'] : false)) ?: '');
    }

    protected function redirectUri(): string
    {
        return defined('GOOGLE_OAUTH_REDIRECT_URI') ? GOOGLE_OAUTH_REDIRECT_URI : ((getenv('GOOGLE_OAUTH_REDIRECT_URI') ?: (isset($_ENV['GOOGLE_OAUTH_REDIRECT_URI']) ? (string) $_ENV['GOOGLE_OAUTH_REDIRECT_URI'] : false)) ?: '');
    }

    /** رابط "موافقة" العميل اللي هنوجّهه ليه */
    public function buildAuthUrl(string $state): string
    {
        return $this->authUrl() . '?' . http_build_query([
            'client_id'     => $this->clientId(),
            'redirect_uri'  => $this->redirectUri(),
            'response_type' => 'code',
            'scope'         => $this->scope(),
            'access_type'   => 'offline',
            'prompt'        => 'consent',
            'state'         => $state,
        ]);
    }

    /** تبديل authorization code بتوكنات حقيقية - نفس منطق GoogleOAuthClient */
    public function exchangeCodeForTokens(string $code): array
    {
        return $this->postToken([
            'code'          => $code,
            'client_id'     => $this->clientId(),
            'client_secret' => $this->clientSecret(),
            'redirect_uri'  => $this->redirectUri(),
            'grant_type'    => 'authorization_code',
        ]);
    }

    public function refreshAccessToken(string $refreshToken): array
    {
        return $this->postToken([
            'refresh_token' => $refreshToken,
            'client_id'     => $this->clientId(),
            'client_secret' => $this->clientSecret(),
            'grant_type'    => 'refresh_token',
        ]);
    }

    private function postToken(array $fields): array
    {
        $ch = curl_init($this->tokenUrl());
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($fields),
            CURLOPT_TIMEOUT        => 15,
        ]);
        $response = curl_exec($ch);
        curl_close($ch);
        $decoded = json_decode($response, true);

        if (isset($decoded['error'])) {
            return ['success' => false, 'error' => $decoded['error_description'] ?? $decoded['error']];
        }
        return [
            'success'       => true,
            'access_token'  => $decoded['access_token'] ?? null,
            'refresh_token' => $decoded['refresh_token'] ?? null,
            'expires_in'    => $decoded['expires_in'] ?? null,
        ];
    }

    /** طلب فعلي على الـ API باستخدام access_token العميل من $context */
    protected function authorizedRequest(string $method, string $path, array $context, array $body = []): array
    {
        $token = $context['access_token'] ?? null;
        if (!$token) {
            return ['success' => false, 'data' => null, 'error' => 'access_token مفقود في $context'];
        }

        $ch = curl_init($this->apiBaseUrl() . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token, 'Content-Type: application/json'],
            CURLOPT_TIMEOUT        => 15,
        ]);
        if (!empty($body)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $decoded = json_decode($response, true);
        if ($httpCode >= 400) {
            return ['success' => false, 'data' => $decoded, 'error' => "HTTP {$httpCode}"];
        }
        return ['success' => true, 'data' => $decoded, 'error' => null];
    }
}
