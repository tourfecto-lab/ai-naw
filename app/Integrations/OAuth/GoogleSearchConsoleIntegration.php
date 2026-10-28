<?php

/**
 * Tourfecto - Google Search Console Integration
 * @version 1.0.0
 *
 * template لأي API جديد نوعه oauth (زي Google Analytics, Google Ads,
 * LinkedIn Marketing...). كل اللي محتاج تغيّره: الـ URLs الأربعة تحت
 * وactions جوه request().
 */
class GoogleSearchConsoleIntegration extends BaseOAuthIntegration
{
    public function key(): string
    {
        return 'google_search_console';
    }

    public function isConfigured(): bool
    {
        return IntegrationManager::isConfigured('google_search_console');
    }

    protected function authUrl(): string
    {
        return 'https://accounts.google.com/o/oauth2/v2/auth';
    }

    protected function tokenUrl(): string
    {
        return 'https://oauth2.googleapis.com/token';
    }

    protected function scope(): string
    {
        return 'https://www.googleapis.com/auth/webmasters.readonly';
    }

    protected function apiBaseUrl(): string
    {
        return 'https://searchconsole.googleapis.com/webmasters/v3';
    }

    public function request(string $action, array $params = [], array $context = []): array
    {
        switch ($action) {
            case 'list_sites':
                return $this->authorizedRequest('GET', '/sites', $context);

            case 'search_analytics':
                // $params: site_url, start_date, end_date, dimensions (array)
                $siteUrl = urlencode($params['site_url'] ?? '');
                return $this->authorizedRequest('POST', "/sites/{$siteUrl}/searchAnalytics/query", $context, [
                    'startDate'  => $params['start_date'] ?? date('Y-m-d', strtotime('-28 days')),
                    'endDate'    => $params['end_date'] ?? date('Y-m-d'),
                    'dimensions' => $params['dimensions'] ?? ['query'],
                    'rowLimit'   => $params['row_limit'] ?? 100,
                ]);

            default:
                return ['success' => false, 'data' => null, 'error' => "action '{$action}' غير مدعوم"];
        }
    }
}
