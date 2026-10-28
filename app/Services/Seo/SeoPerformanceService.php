<?php

/**
 * Tourfecto - SEO Performance Service (قياس CTR + تقارير قبل/بعد)
 * @version 1.0.0
 *
 * بيعالج الفجوتين اللي كانوا موجودين في قياس نتائج SEO A/B:
 * 1) كل نداء /results كان بيجيب بيانات GSC كاملة (بطيء + بيتعرض لـ rate
 *    limit من Google). الخدمة دي بتخزّن مقاييس الصفحات في جدول كاش
 *    (seo_gsc_page_metrics) وبتجددها بس لما تكون قديمة.
 * 2) مفيش "قبل/بعد" واضح للعميل. بنسجّل لقطة (seo_reports) بعد كل تدقيق/
 *    تطبيق، عشان نعرض تحسن الدرجة والإصلاحات ومقاييس GSC عبر الوقت.
 */
class SeoPerformanceService
{
    /** @var Database */
    private $db;

    /** عدد ساعات صلاحية كاش GSC قبل ما نعيد الجلب من Google */
    private const METRICS_TTL_HOURS = 6;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /**
     * سحب مقاييس GSC لكل صفحة وتخزينها في الكاش.
     * @param string $siteUrl بصيغة GSC (sc-domain:example.com أو https://example.com/)
     * @param string $accessToken توكن OAuth صالح (webmasters.readonly)
     * @return array ['success'=>bool, 'rows'=>int, 'error'=>?string]
     */
    public function syncPageMetrics(int $websiteId, string $siteUrl, string $accessToken): array
    {
        try {
            $api = new GoogleSearchConsoleAPI($accessToken);
            $endDate = date('Y-m-d', strtotime('-2 days')); // تأخر بيانات Google
            $startDate = date('Y-m-d', strtotime('-27 days', strtotime($endDate)));

            $result = $api->getSearchAnalytics($siteUrl, $startDate, $endDate, ['page'], 25000);
            if (!$result['success']) {
                return ['success' => false, 'error' => $result['error'] ?? 'GSC fetch failed'];
            }

            $rows = $result['rows'] ?? [];
            $groups = [];
            foreach ($rows as $row) {
                $path = SeoAbTestService::normalizePagePath((string) ($row['page'] ?? '/'));
                if (!isset($groups[$path])) $groups[$path] = ['clicks'=>0, 'impressions'=>0, 'weighted'=>0.0, 'position_impressions'=>0];
                $n = max(0, (int) ($row['impressions'] ?? 0));
                $groups[$path]['clicks'] += max(0, (int) ($row['clicks'] ?? 0));
                $groups[$path]['impressions'] += $n;
                $position = $row['position'] ?? null;
                if ($n > 0 && is_numeric($position) && (float) $position > 0) {
                    $groups[$path]['weighted'] += $n * (float) $position;
                    $groups[$path]['position_impressions'] += $n;
                }
            }
            // Atomic replacement prevents stale periods and pages surviving an empty successful response.
            $this->db->transaction(function () use ($websiteId, $groups, $startDate, $endDate) {
                $this->db->exec('DELETE FROM seo_gsc_page_metrics WHERE website_id = ?', [$websiteId]);
                foreach ($groups as $path => $m) {
                    $this->db->exec(
                        "INSERT INTO seo_gsc_page_metrics
                         (website_id, page_path, clicks, impressions, ctr, position, date_start, date_end, fetched_at)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())",
                        [$websiteId, $path, $m['clicks'], $m['impressions'],
                         $m['impressions'] > 0 ? $m['clicks'] / $m['impressions'] : 0,
                         $m['position_impressions'] > 0 ? $m['weighted'] / $m['position_impressions'] : 0,
                         $startDate, $endDate]
                    );
                }
            });

            return ['success' => true, 'rows' => count($rows), 'date_range' => ['start' => $startDate, 'end' => $endDate]];
        } catch (Exception $e) {
            Logger::error('SeoPerformanceService sync error', ['website_id' => $websiteId, 'message' => $e->getMessage()]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * قراءة مقاييس GSC المخزّنة لموقع، كخريطة page_path => metrics.
     * @return array<string,array>
     */
    public function getCachedPageMetrics(int $websiteId): array
    {
        $rows = $this->db->query(
            "SELECT page_path, clicks, impressions, ctr, position FROM seo_gsc_page_metrics WHERE website_id = ? AND date_end = (SELECT MAX(date_end) FROM seo_gsc_page_metrics WHERE website_id = ?)",
            [$websiteId, $websiteId]
        );
        $map = [];
        foreach ($rows as $r) {
            $map[(string) $r['page_path']] = [
                'clicks' => (int) $r['clicks'],
                'impressions' => (int) $r['impressions'],
                'ctr' => (float) $r['ctr'],
                'position' => (float) $r['position'],
            ];
        }
        return $map;
    }

    /** عمر بيانات الكاش بالساعات (null لو مفيش بيانات خالص) */
    public function metricsAgeHours(int $websiteId): ?float
    {
        $rows = $this->db->query(
            "SELECT MAX(fetched_at) AS latest FROM seo_gsc_page_metrics WHERE website_id = ?",
            [$websiteId]
        );
        if (empty($rows) || empty($rows[0]['latest'])) {
            return null;
        }
        return (time() - strtotime((string) $rows[0]['latest'])) / 3600.0;
    }

    /** إجمالي مقاييس GSC المخزّنة لموقع (ملخص سريع للتقارير) */
    public function cachedSummary(int $websiteId): array
    {
        $rows = $this->db->query(
            "SELECT COALESCE(SUM(clicks),0) AS clicks, COALESCE(SUM(impressions),0) AS impressions,
                    CASE WHEN SUM(impressions)>0 THEN 1.0*SUM(clicks)/SUM(impressions) ELSE 0 END AS ctr,
                    SUM(CASE WHEN position>0 THEN position*impressions ELSE 0 END)/NULLIF(SUM(CASE WHEN position>0 THEN impressions ELSE 0 END),0) AS position
             FROM seo_gsc_page_metrics WHERE website_id = ? AND date_end = (SELECT MAX(date_end) FROM seo_gsc_page_metrics WHERE website_id = ?)",
            [$websiteId, $websiteId]
        );
        $s = $rows[0] ?? ['clicks' => 0, 'impressions' => 0, 'ctr' => 0, 'position' => null];
        return [
            'clicks' => (int) $s['clicks'],
            'impressions' => (int) $s['impressions'],
            'ctr' => (float) $s['ctr'],
            'avg_position' => $s['position'] !== null ? (float) $s['position'] : null,
        ];
    }

    /**
     * تسجيل لقطة قبل/بعد (بعد تدقيق أو تطبيق إصلاحات).
     * @return int|null id اللقطة
     */
    public function snapshot(
        int $websiteId,
        ?int $userId,
        ?int $auditId,
        ?float $overallScore,
        int $findingsTotal,
        int $fixesApplied,
        array $gscSummary = [],
        string $source = 'manual'
    ): ?int {
        try {
            $id = $this->db->query(
                "INSERT INTO seo_reports
                    (website_id, user_id, audit_id, overall_score, findings_total, fixes_applied,
                     clicks, impressions, ctr, avg_position, source, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())",
                [
                    $websiteId,
                    $userId,
                    $auditId,
                    $overallScore,
                    $findingsTotal,
                    $fixesApplied,
                    $gscSummary['clicks'] ?? null,
                    $gscSummary['impressions'] ?? null,
                    $gscSummary['ctr'] ?? null,
                    $gscSummary['avg_position'] ?? null,
                    $source,
                ]
            );
            return (int) $id;
        } catch (Exception $e) {
            Logger::error('SeoPerformanceService snapshot error', ['website_id' => $websiteId, 'message' => $e->getMessage()]);
            return null;
        }
    }

    /** سجل اللقطات (قبل/بعد) لموقع، الأحدث أولًا */
    public function history(int $websiteId, int $limit = 30): array
    {
        return $this->db->query(
            "SELECT * FROM seo_reports WHERE website_id = ? ORDER BY id DESC LIMIT ?",
            [$websiteId, $limit]
        );
    }
}
