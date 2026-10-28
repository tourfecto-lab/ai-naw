<?php

/**
 * Tourfecto - GBP Insights & Analytics Service
 * مقاييس أداء حقيقية من Business Profile Performance API الرسمي
 * (locations.fetchMultiDailyMetricsTimeSeries) - البديل المُعتمد للـ
 * Insights API القديم المُهجَّر. بيدعم فقط الـ metrics المذكورة صراحة في
 * GoogleBusinessAPI::SUPPORTED_METRICS. لا بيانات وهمية أبدًا: لو مفيش
 * اتصال أو فشل الطلب، بيرجع رسالة صريحة بدل أرقام مصنوعة.
 * @version 1.0.0
 * @since 2026-08-09 (GBP Module Upgrade)
 */
class GbpInsightsService
{
    /** @var Database */
    private $db;
    /** @var GbpSyncService */
    private $sync;
    /** @var GoogleReviewSyncService */
    private $reviewSync;

    /** كاش لمدة ساعتين - Performance API بطيء نسبيًا ومحدود quota */
    private const CACHE_TTL_SECONDS = 7200;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->sync = new GbpSyncService();
        $this->reviewSync = new GoogleReviewSyncService();
    }

    /**
     * @param int $days 7|30|90 أو أي عدد أيام مخصص - يتجاهله لو $customStart/$customEnd محددين
     * @param bool $withComparison لو true بيجيب نفس المدة قبلها كمان للمقارنة
     * @param string|null $customStart Y-m-d - لدعم Custom Range الصريح من الواجهة
     * @param string|null $customEnd Y-m-d
     */
    public function getInsights(int $websiteId, int $userId, int $days = 30, bool $withComparison = true, ?string $customStart = null, ?string $customEnd = null): array
    {
        $connection = $this->sync->findConnection($websiteId, $userId);
        if (!$connection) {
            return ['success' => false, 'error' => 'اربط حساب Google Business الأول'];
        }
        if ($connection->getAttribute('status') !== 'connected') {
            return ['success' => false, 'error' => 'الاتصال غير نشط حاليًا (' . $connection->getAttribute('last_error') . ')'];
        }

        if ($customStart && $customEnd) {
            try {
                $startDate = new DateTime($customStart);
                $endDate = new DateTime($customEnd);
            } catch (Throwable $e) {
                return ['success' => false, 'error' => 'تواريخ Custom Range غير صحيحة'];
            }
            if ($startDate > $endDate) {
                return ['success' => false, 'error' => 'تاريخ البداية لازم يكون قبل تاريخ النهاية'];
            }
            $days = (int) $startDate->diff($endDate)->days + 1;
        } else {
            $endDate = new DateTime('yesterday'); // Google مبيرجعش بيانات نفس اليوم عادة
            $startDate = (clone $endDate)->modify('-' . max(1, $days - 1) . ' days');
        }

        $current = $this->fetchRange($connection, $startDate, $endDate);
        if (!$current['success']) {
            return $current;
        }

        $result = [
            'success' => true,
            'range' => ['start' => $startDate->format('Y-m-d'), 'end' => $endDate->format('Y-m-d'), 'days' => $days],
            'metrics' => $current['metrics'],
            'totals' => $this->totals($current['metrics']),
        ];

        if ($withComparison) {
            $prevEnd = (clone $startDate)->modify('-1 day');
            $prevStart = (clone $prevEnd)->modify('-' . max(1, $days - 1) . ' days');
            $previous = $this->fetchRange($connection, $prevStart, $prevEnd);

            if ($previous['success']) {
                $prevTotals = $this->totals($previous['metrics']);
                $result['previous_period'] = [
                    'range' => ['start' => $prevStart->format('Y-m-d'), 'end' => $prevEnd->format('Y-m-d')],
                    'totals' => $prevTotals,
                    'change_percent' => $this->percentChange($result['totals'], $prevTotals),
                ];
            } else {
                $result['previous_period'] = null; // Not enough data / Not available from Google API
            }
        }

        return $result;
    }

    private function fetchRange(PlatformConnection $connection, DateTime $start, DateTime $end): array
    {
        $cacheKey = sprintf(
            '%d:%s:%s',
            $connection->getAttribute('id'),
            $start->format('Y-m-d'),
            $end->format('Y-m-d')
        );

        $cached = $this->readCache($cacheKey);
        if ($cached !== null) {
            return ['success' => true, 'metrics' => $cached];
        }

        try {
            $accessToken = $this->reviewSync->getValidAccessToken($connection);
        } catch (Throwable $e) {
            return ['success' => false, 'error' => 'تعذر جلب المقاييس - يحتاج إعادة ربط (Reconnect): ' . $e->getMessage()];
        }

        $api = new GoogleBusinessAPI(
            $accessToken,
            $connection->getAttribute('external_account_id'),
            $connection->getAttribute('external_location_id')
        );

        $response = $api->fetchDailyMetrics(
            $connection->getAttribute('external_location_id'),
            GoogleBusinessAPI::SUPPORTED_METRICS,
            $start->format('Y-m-d'),
            $end->format('Y-m-d')
        );

        if (!$response['success']) {
            return $response;
        }

        $this->writeCache($cacheKey, $response['metrics']);

        return ['success' => true, 'metrics' => $response['metrics']];
    }

    private function totals(array $metrics): array
    {
        $totals = [];
        foreach ($metrics as $metric => $points) {
            $totals[$metric] = array_sum(array_column($points, 'value'));
        }

        // تجميع منطقي لعرض SaaS مفهوم (Views/Searches/Actions) بدل أسماء enum الخام - كله من نفس الأرقام الحقيقية
        return [
            'views' => ($totals['BUSINESS_IMPRESSIONS_DESKTOP_MAPS'] ?? 0) + ($totals['BUSINESS_IMPRESSIONS_MOBILE_MAPS'] ?? 0),
            'searches' => ($totals['BUSINESS_IMPRESSIONS_DESKTOP_SEARCH'] ?? 0) + ($totals['BUSINESS_IMPRESSIONS_MOBILE_SEARCH'] ?? 0),
            'website_clicks' => $totals['WEBSITE_CLICKS'] ?? 0,
            'phone_calls' => $totals['CALL_CLICKS'] ?? 0,
            'direction_requests' => $totals['BUSINESS_DIRECTION_REQUESTS'] ?? 0,
            'customer_actions' => ($totals['WEBSITE_CLICKS'] ?? 0) + ($totals['CALL_CLICKS'] ?? 0) + ($totals['BUSINESS_DIRECTION_REQUESTS'] ?? 0) + ($totals['BUSINESS_CONVERSATIONS'] ?? 0),
            'raw' => $totals,
        ];
    }

    private function percentChange(array $current, array $previous): array
    {
        $change = [];
        foreach (['views', 'searches', 'website_clicks', 'phone_calls', 'direction_requests', 'customer_actions'] as $key) {
            $cur = $current[$key] ?? 0;
            $prev = $previous[$key] ?? 0;
            if ($prev == 0) {
                $change[$key] = $cur > 0 ? 100.0 : 0.0;
            } else {
                $change[$key] = round((($cur - $prev) / $prev) * 100, 1);
            }
        }
        return $change;
    }

    private function readCache(string $key): ?array
    {
        try {
            $rows = $this->db->query(
                "SELECT payload, fetched_at FROM gbp_insights_cache WHERE cache_key = ? LIMIT 1",
                [$key]
            );
            if (empty($rows)) {
                return null;
            }
            if (strtotime($rows[0]['fetched_at']) < (time() - self::CACHE_TTL_SECONDS)) {
                return null;
            }
            $decoded = json_decode($rows[0]['payload'], true);
            return is_array($decoded) ? $decoded : null;
        } catch (Throwable $e) {
            return null;
        }
    }

    private function writeCache(string $key, array $metrics): void
    {
        try {
            $payload = json_encode($metrics, JSON_UNESCAPED_UNICODE);
            $this->db->query(
                "INSERT INTO gbp_insights_cache (cache_key, payload, fetched_at)
                 VALUES (?, ?, NOW())
                 ON DUPLICATE KEY UPDATE payload = VALUES(payload), fetched_at = NOW()",
                [$key, $payload]
            );
        } catch (Throwable $e) {
            Logger::error('GBP insights cache write failed', ['error' => $e->getMessage()]);
        }
    }
}
