<?php

/**
 * Tourfecto - Google Search Console API Integration
 * تكامل مع Search Console API (بيانات ظهور الموقع في نتائج بحث Google:
 * clicks / impressions / CTR / متوسط الترتيب لكل query أو صفحة)
 * @version 1.0.0
 *
 * نفس فلسفة app/Services/Reputation/GoogleBusinessAPI.php: التوكن ومعرف
 * الموقع بييجوا من صف platform_connections الخاص بكل عميل (مش من .env عام)،
 * عشان كل عميل يربط حساب Google بتاعه هو.
 */
class GoogleSearchConsoleAPI
{
    private const BASE_URL = 'https://www.googleapis.com/webmasters/v3';

    private string $accessToken;
    private int $timeout = 30;

    /**
     * @var callable|null حقنة اختيارية للاختبارات - بتستقبل وصف الطلب
     * ['method','url','headers','body'] وترجع رد محاكى
     * ['body'=>string,'http_code'=>int,'error'=>?string] بدل curl.
     */
    private $transport;

    /**
     * @param string $accessToken توكن OAuth حقيقي (scope: webmasters.readonly) خاص بحساب هذا العميل.
     */
    public function __construct(string $accessToken = '', ?callable $transport = null)
    {
        $this->accessToken = $accessToken;
        $this->transport = $transport;
    }

    /**
     * قائمة مواقع Search Console اللي التوكن ده عنده صلاحية عليها
     * (بيرجع بس اللي مؤكدة الملكية verified، عشان العميل يختار الموقع
     * الصح لو عنده أكتر من واحد).
     */
    public function listSites(): array
    {
        $response = $this->makeRequest('GET', '/sites');
        if (!$response['success']) {
            return $response;
        }

        $sites = array_values(array_filter(array_map(function ($site) {
            $permission = $site['permissionLevel'] ?? '';
            if ($permission === 'siteUnverifiedUser') {
                return null; // ملكش صلاحية فعلية عليه، منعرضهوش للاختيار
            }
            return [
                'site_url' => $site['siteUrl'] ?? '',
                'permission_level' => $permission,
            ];
        }, $response['data']['siteEntry'] ?? [])));

        return ['success' => true, 'sites' => $sites];
    }

    /**
     * بيانات الأداء (clicks/impressions/ctr/position) لموقع معيّن خلال فترة زمنية.
     * @param string $siteUrl لازم يتبعت بالظبط زي ما راجع من listSites()
     *   (ممكن يكون "sc-domain:example.com" أو "https://example.com/")
     * @param string $startDate YYYY-MM-DD
     * @param string $endDate YYYY-MM-DD
     * @param string[] $dimensions زي ['query'] أو ['page'] أو ['date']
     * @param int $rowLimit أقصى عدد صفوف (Google بيسمح لحد 25000)
     */
    public function getSearchAnalytics(string $siteUrl, string $startDate, string $endDate, array $dimensions = ['query'], int $rowLimit = 25): array
    {
        $endpoint = '/sites/' . rawurlencode($siteUrl) . '/searchAnalytics/query';

        $response = $this->makeRequest('POST', $endpoint, [], [
            'startDate' => $startDate,
            'endDate' => $endDate,
            'dimensions' => $dimensions,
            'rowLimit' => $rowLimit,
        ]);

        if (!$response['success']) {
            return $response;
        }

        $rows = array_map(function ($row) use ($dimensions) {
            $keys = $row['keys'] ?? [];
            $mapped = [];
            foreach ($dimensions as $i => $dim) {
                $mapped[$dim] = $keys[$i] ?? null;
            }
            return array_merge($mapped, [
                'clicks' => (int) ($row['clicks'] ?? 0),
                'impressions' => (int) ($row['impressions'] ?? 0),
                'ctr' => round((float) ($row['ctr'] ?? 0) * 100, 2), // نسبة مئوية أوضح للعرض
                'position' => round((float) ($row['position'] ?? 0), 1),
            ]);
        }, $response['data']['rows'] ?? []);

        return ['success' => true, 'rows' => $rows];
    }

    /**
     * ملخص سريع (إجمالي clicks/impressions/متوسط ctr وposition) لآخر N يوم،
     * مفيد لكارت "نظرة عامة" من غير ما تحمّل تفاصيل كل query.
     */
    public function getSummary(string $siteUrl, int $days = 28): array
    {
        $endDate = date('Y-m-d', strtotime('-2 days')); // بيانات Google بتتأخر يوم-يومين عادة
        $startDate = date('Y-m-d', strtotime("-" . (max(1, $days) - 1) . " days", strtotime($endDate)));

        $result = $this->getSearchAnalytics($siteUrl, $startDate, $endDate, ['date'], 1000);
        if (!$result['success']) {
            return $result;
        }

        $totalClicks = 0;
        $totalImpressions = 0;
        $weightedPosition = 0.0;

        foreach ($result['rows'] as $row) {
            $totalClicks += $row['clicks'];
            $totalImpressions += $row['impressions'];
            $weightedPosition += $row['position'] * $row['impressions'];
        }

        return [
            'success' => true,
            'summary' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
                'clicks' => $totalClicks,
                'impressions' => $totalImpressions,
                'ctr' => $totalImpressions > 0 ? round(($totalClicks / $totalImpressions) * 100, 2) : 0,
                'avg_position' => $totalImpressions > 0 ? round($weightedPosition / $totalImpressions, 1) : 0,
            ],
        ];
    }

    /** Exact-page, final-data report. Empty reported rows are not proof of zero real-world searches. */
    public function getPagePeriod(string $property,string $page,string $start,string $end): array {
        $r=$this->makeRequest('POST','/sites/'.rawurlencode($property).'/searchAnalytics/query',[],[
            'startDate'=>$start,'endDate'=>$end,'dimensions'=>['page'],'type'=>'web','dataState'=>'final','rowLimit'=>1,
            'dimensionFilterGroups'=>[['groupType'=>'and','filters'=>[['dimension'=>'page','operator'=>'equals','expression'=>$page]]]]
        ]);
        if(empty($r['success']))return ['state'=>'unavailable','note'=>'Search Console report unavailable. Review property access.'];
        $row=$r['data']['rows'][0]??null;$clicks=(int)($row['clicks']??0);$impressions=(int)($row['impressions']??0);
        return ['state'=>$row?'reported':'reported_empty','source'=>'google_search_console','timezone'=>'America/Los_Angeles','clicks'=>$clicks,'impressions'=>$impressions,'ctr'=>$impressions>0?$clicks/$impressions:null,'position'=>$impressions>0?(float)($row['position']??0):null,'note'=>'Exact URL report; provider limits/privacy can omit data.'];
    }

    /**
     * فحص فهرسة صفحة (URL Inspection API): هل جوجل فهرسها؟ ولو لأ ليه؟
     * @return array{ok:bool, url:string, indexed?:bool, verdict?:string, coverage?:string, reason?:string, last_crawl?:?string, google_canonical?:?string, user_canonical?:?string, robots?:string, fetch?:string, error?:string}
     */
    public function inspectUrl(string $property, string $url): array
    {
        $r = $this->makeRequest('POST', 'https://searchconsole.googleapis.com/v1/urlInspection/index:inspect', [], ['inspectionUrl' => $url, 'siteUrl' => $property]);
        if (empty($r['success'])) {
            return ['ok' => false, 'url' => $url, 'error' => (string) ($r['http_code'] ?? 'unavailable')];
        }
        $x = $r['data']['inspectionResult']['indexStatusResult'] ?? [];
        $verdict = (string) ($x['verdict'] ?? '');
        $coverage = (string) ($x['coverageState'] ?? '');
        $robots = (string) ($x['robotsTxtState'] ?? '');
        $indexing = (string) ($x['indexingState'] ?? '');
        $fetch = (string) ($x['pageFetchState'] ?? '');
        $gc = $x['googleCanonical'] ?? null;
        $uc = $x['userCanonical'] ?? null;
        $reason = '';
        if ($robots === 'DISALLOWED') {
            $reason = 'robots_blocked';
        } elseif (in_array($indexing, ['BLOCKED_BY_META_TAG', 'BLOCKED_BY_HTTP_HEADER'], true)) {
            $reason = 'noindex';
        } elseif ($fetch !== '' && !in_array($fetch, ['SUCCESSFUL', 'PAGE_FETCH_STATE_UNSPECIFIED'], true)) {
            $reason = 'fetch_' . strtolower($fetch);
        } elseif ($gc && $uc && rtrim((string) $gc, '/') !== rtrim((string) $uc, '/')) {
            $reason = 'google_chose_other_canonical';
        } elseif (stripos($coverage, 'Crawled') !== false && stripos($coverage, 'not indexed') !== false) {
            $reason = 'crawled_not_indexed';
        } elseif (stripos($coverage, 'Discovered') !== false) {
            $reason = 'discovered_not_crawled';
        } elseif (stripos($coverage, 'unknown') !== false) {
            $reason = 'unknown_to_google';
        }
        return ['ok' => true, 'url' => $url, 'indexed' => $verdict === 'PASS', 'verdict' => $verdict, 'coverage' => $coverage, 'reason' => $reason, 'last_crawl' => $x['lastCrawlTime'] ?? null, 'google_canonical' => $gc, 'user_canonical' => $uc, 'robots' => $robots, 'fetch' => $fetch];
    }

    /** الـ sitemaps المتسجلة في Search Console للخاصية دي */
    /** Requires the customer's explicitly granted webmasters scope. */
    public function submitSitemap(string $property,string $sitemapUrl): array
    {
        return $this->makeRequest('PUT','/sites/'.rawurlencode($property).'/sitemaps/'.rawurlencode($sitemapUrl));
    }

    public function listSitemaps(string $property): array
    {
        $r = $this->makeRequest('GET', '/sites/' . rawurlencode($property) . '/sitemaps');
        if (empty($r['success'])) {
            return ['ok' => false, 'sitemaps' => []];
        }
        $out = [];
        foreach ($r['data']['sitemap'] ?? [] as $sm) {
            $out[] = ['path' => (string) ($sm['path'] ?? ''), 'errors' => (int) ($sm['errors'] ?? 0), 'warnings' => (int) ($sm['warnings'] ?? 0), 'last_downloaded' => $sm['lastDownloaded'] ?? null, 'pending' => !empty($sm['isPending'])];
        }
        return ['ok' => true, 'sitemaps' => $out];
    }

    private function makeRequest(string $method, string $endpoint, array $query = [], array $data = []): array
    {
        $url = str_starts_with($endpoint, 'https://') ? $endpoint : self::BASE_URL . $endpoint;

        if (!empty($query)) {
            $url .= '?' . http_build_query($query);
        }

        $headers = ['Accept: application/json'];
        if ($this->accessToken) {
            $headers[] = 'Authorization: Bearer ' . $this->accessToken;
        }

        if (!empty($data)) {
            $headers[] = 'Content-Type: application/json';
        }

        $result = $this->httpRequest(
            $method,
            $url,
            $headers,
            !empty($data) ? json_encode($data, JSON_UNESCAPED_UNICODE) : null
        );

        if ($result['error']) {
            return ['success' => false, 'error' => 'cURL Error: ' . $result['error']];
        }

        $decoded = json_decode($result['body'], true);
        $httpCode = $result['http_code'];

        if ($httpCode < 200 || $httpCode >= 300) {
            $errorMessage = $decoded['error']['message'] ?? 'Unknown error';
            return [
                'success' => false,
                'error' => "Search Console API Error ({$httpCode}): {$errorMessage}",
                'http_code' => $httpCode,
            ];
        }

        return ['success' => true, 'data' => $decoded, 'http_code' => $httpCode];
    }

    /**
     * تنفيذ طلب HTTP عبر الـ transport الوهمي (لو محقون) أو curl العادي.
     * نفس خيارات curl السابقة بالظبط - لا تغيير في سلوك الإنتاج.
     * @return array ['body'=>string,'http_code'=>int,'error'=>?string]
     */
    private function httpRequest(string $method, string $url, array $headers = [], ?string $body = null): array
    {
        if ($this->transport !== null) {
            $fake = call_user_func($this->transport, [
                'method' => $method,
                'url' => $url,
                'headers' => $headers,
                'body' => $body,
            ]);
            return [
                'body' => (string) ($fake['body'] ?? ''),
                'http_code' => (int) ($fake['http_code'] ?? 0),
                'error' => isset($fake['error']) ? (string) $fake['error'] : null,
            ];
        }

        $ch = curl_init($url);
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT => 'Tourfecto/1.0',
        ];
        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = $body;
        }
        curl_setopt_array($ch, $options);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        return [
            'body' => (string) $response,
            'http_code' => (int) $httpCode,
            'error' => $curlError ?: null,
        ];
    }
}
