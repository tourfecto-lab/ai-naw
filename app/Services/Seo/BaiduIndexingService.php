<?php

/**
 * Baidu Webmaster Platform "Active Push" API Integration.
 * Activates only when admin adds "China"/"zh" to target_languages.
 *
 * @version 1.0.0
 */
class BaiduIndexingService
{
    /** @var string */
    private $apiUrl = 'https://data.zz.baidu.com/urls';

    /**
     * Submit URLs to Baidu for immediate indexing.
     *
     * @param string $site Site URL (e.g. https://example.com)
     * @param string $token Baidu token from admin
     * @param array $urls Full URLs list
     * @return array ['success'=>bool, 'status'=>int, 'message'=>string]
     */
    public function submitUrls(string $site, string $token, array $urls): array
    {
        if (empty($urls) || $token === '') {
            return ['success' => false, 'status' => 0, 'message' => 'Token or URLs missing'];
        }

        $endpoint = $this->apiUrl . '?site=' . urlencode($site) . '&token=' . urlencode($token);
        $payload = implode("\n", array_map('trim', $urls));

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['Content-Type: text/plain'],
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT => 'TourfectoBaiduBot/1.0',
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false || $httpCode !== 200) {
            return [
                'success' => false,
                'status' => $httpCode,
                'message' => $error ?: 'Baidu API returned HTTP ' . $httpCode,
            ];
        }

        $data = json_decode((string) $response, true);
        if (!is_array($data)) {
            return ['success' => true, 'status' => $httpCode, 'message' => 'Submitted', 'raw' => $response];
        }

        return [
            'success' => isset($data['success']) ? (bool) $data['success'] : true,
            'status' => $httpCode,
            'message' => $data['message'] ?? 'Submitted successfully',
            'remain' => $data['remain'] ?? null,
            'success_count' => $data['success'] ?? null,
        ];
    }

    /**
     * Does the site target China (Baidu)?
     */
    public static function isChinaTarget(?string $targetLanguagesJson): bool
    {
        if (empty($targetLanguagesJson)) {
            return false;
        }
        $langs = json_decode($targetLanguagesJson, true);
        if (!is_array($langs)) {
            return false;
        }
        foreach ($langs as $lang) {
            $code = is_array($lang) ? ($lang['code'] ?? '') : $lang;
            if (strtolower((string) $code) === 'zh' || strtolower((string) $code) === 'zh-cn') {
                return true;
            }
        }
        return false;
    }
}
