<?php

/**
 * GET /api/panel/company?website_id=N - name and icon of the customer's company for the account entry in the panel's
 * sidebar. The icon is the connected Facebook page's picture, else the website's logo, else its favicon; the browser falls
 * back to the first letter when an image does not load. Public image URLs only - no tokens leave the server.
 */
class PanelCompanyController extends Controller
{
    public function show(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Not logged in', 401);
        }
        $uid = (int) ($this->user['id'] ?? 0);
        $wid = (int) $this->get('website_id');
        try {
            $site = $wid > 0
                ? ($this->db->query('SELECT * FROM websites WHERE id = ? AND user_id = ? LIMIT 1', [$wid, $uid])[0] ?? null)
                : ($this->db->query('SELECT * FROM websites WHERE user_id = ? ORDER BY id LIMIT 1', [$uid])[0] ?? null);
            $out = ['name' => (string) ($this->user['name'] ?? ''), 'icons' => []];
            if ($site) {
                $out['name'] = trim((string) ($site['company_name'] ?? '')) !== '' ? (string) $site['company_name'] : (string) (parse_url((string) ($site['main_url'] ?? ''), PHP_URL_HOST) ?: $out['name']);
                $page = $this->db->query(
                    "SELECT external_location_id FROM platform_connections WHERE website_id = ? AND user_id = ? AND status = 'connected' AND platform IN ('facebook', 'messenger', 'messenger_page') AND external_location_id REGEXP '^[0-9]{5,25}$' ORDER BY id DESC LIMIT 1",
                    [(int) $site['id'], $uid]
                )[0]['external_location_id'] ?? '';
                $out['icons'] = self::icons((string) $page, (string) ($site['logo_url'] ?? ''), (string) ($site['main_url'] ?? ''));
            }
            return $this->success($out);
        } catch (Throwable $e) {
            Logger::warning('Panel company icon failed', ['error' => mb_substr($e->getMessage(), 0, 160)]);
            return $this->success(['name' => (string) ($this->user['name'] ?? ''), 'icons' => []]);
        }
    }

    /**
     * Candidate icons in priority order (https only).
     * @return string[]
     */
    public static function icons(string $facebookPageId, string $logoUrl, string $mainUrl): array
    {
        $icons = [];
        if (preg_match('/^\d{5,25}$/', $facebookPageId)) {
            $icons[] = 'https://graph.facebook.com/' . $facebookPageId . '/picture?type=square&width=96&height=96';
        }
        if (preg_match('#^https://[^\s"\'<>]+$#i', $logoUrl) && strlen($logoUrl) <= 500) {
            $icons[] = $logoUrl;
        }
        $host = strtolower((string) (parse_url(preg_match('#^https?://#i', $mainUrl) ? $mainUrl : 'https://' . $mainUrl, PHP_URL_HOST) ?: ''));
        if ($host !== '' && preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $host)) {
            $icons[] = 'https://' . $host . '/favicon.ico';
        }
        return $icons;
    }
}
