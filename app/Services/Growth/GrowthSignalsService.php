<?php
/**
 * Tourfecto - قراءة إشارات النمو من بيانات العميل الحقيقية (حجوزات، رحلات، أسواق، إيرادات)
 * الحساب نفسه في GrowthSignals (نقي). هنا قراءة بس - مفيش أي كتابة.
 */
final class GrowthSignalsService
{
    private PDO $pdo;

    public function __construct(?Database $db = null)
    {
        $this->pdo = ($db ?? Database::getInstance())->getConnection();
    }

    private function q(string $sql, array $args = []): array
    {
        $s = $this->pdo->prepare($sql);
        $s->execute($args);
        return $s->fetchAll(PDO::FETCH_ASSOC);
    }

    /** إشارات شهر معيّن لموقع العميل. أي مصدر ناقص بيرجع فاضي بدل ما يوقّف الباقي. */
    public function forWebsite(int $uid, int $wid, string $month, int $postCount = 12): array
    {
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D', $month)) throw new InvalidArgumentException('Invalid month');
        $start = $month . '-01';
        $end = (new DateTimeImmutable($start))->modify('+1 month')->format('Y-m-d');
        $from = (new DateTimeImmutable($start))->modify('-24 months')->format('Y-m-d');
        $ok = "status IN ('confirmed','completed')";

        $monthly = $this->safe(fn () => $this->q("SELECT DATE_FORMAT(start_date,'%Y-%m') AS month,COUNT(*) AS bookings,SUM(COALESCE(adults_count,0)+COALESCE(children_count,0)) AS travelers FROM bookings WHERE user_id=? AND $ok AND start_date>=? AND start_date<? GROUP BY DATE_FORMAT(start_date,'%Y-%m') ORDER BY month", [$uid, $from, $end]));
        $lead = array_column($this->safe(fn () => $this->q("SELECT DATEDIFF(start_date,created_at) AS d FROM bookings WHERE user_id=? AND $ok AND created_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 365 DAY) AND start_date IS NOT NULL ORDER BY id DESC LIMIT 1000", [$uid])), 'd');

        $products = $this->safe(fn () => $this->q('SELECT id,name,price,currency,website_id,tour_slug FROM crm_products WHERE user_id=? AND is_active=1 ORDER BY id DESC LIMIT 60', [$uid]));
        $stats = [];
        foreach ($this->safe(fn () => $this->q("SELECT product_id,
                SUM(created_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 90 DAY)) AS recent,
                SUM(created_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 455 DAY) AND created_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 365 DAY)) AS previous,
                SUM(start_date>=? AND start_date<?) AS upcoming,
                SUM(CASE WHEN created_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 90 DAY) THEN total_amount ELSE 0 END) AS revenue
            FROM bookings WHERE user_id=? AND $ok AND product_id IS NOT NULL GROUP BY product_id", [$start, $end, $uid])) as $r) $stats[(int) $r['product_id']] = $r;

        $trips = [];
        foreach ($products as $p) {
            $s = $stats[(int) $p['id']] ?? [];
            $trips[] = ['id' => (int) $p['id'], 'name' => (string) $p['name'], 'price' => $p['price'], 'currency' => $p['currency'], 'url' => $this->tripUrl($uid, $p),
                'recent' => (int) ($s['recent'] ?? 0), 'previous' => (int) ($s['previous'] ?? 0), 'upcoming' => (int) ($s['upcoming'] ?? 0), 'revenue' => isset($s['revenue']) ? (float) $s['revenue'] : null];
        }

        $site = $this->safe(fn () => $this->q('SELECT target_country,target_countries,target_languages,target_customers FROM websites WHERE id=? AND user_id=?', [$wid, $uid]))[0] ?? [];
        $markets = array_filter([
            'countries' => self::list($site['target_countries'] ?? null) ?: self::list($site['target_country'] ?? null),
            'languages' => self::list($site['target_languages'] ?? null),
            'customers' => isset($site['target_customers']) ? mb_substr((string) $site['target_customers'], 0, 300) : null,
        ]);

        $revenue = null;
        if (class_exists('RevenueForecastService') && class_exists('RevenueDataGateway')) {
            try {
                // نفس منطق ذكاء الإيرادات: الفترة الحالية مقابل الفترة المماثلة السابقة
                $series = (new RevenueDataGateway())->getDailyRevenueSeries($uid, gmdate('Y-m-d H:i:s', strtotime('-180 days')), gmdate('Y-m-d H:i:s'));
                $f = RevenueForecastService::seasonalForecast($series, 'monthly', gmdate('Y-m-d'));
                if (empty($f['insufficient_data']) && isset($f['expected_revenue'])) $revenue = ['expected_revenue' => $f['expected_revenue'], 'seasonal_factor' => $f['seasonal_factor'] ?? null, 'note' => $f['seasonality_note'] ?? null];
            } catch (Throwable $e) { /* الإيرادات اختيارية */ }
        }

        return GrowthSignals::build($monthly, $trips, $lead, $month, $postCount, $revenue, $markets);
    }

    /** رابط صفحة الرحلة على موقع العميل لو موجود (دومين خاص أو /sites/{slug}) */
    private function tripUrl(int $uid, array $p): ?string
    {
        if (empty($p['tour_slug']) || empty($p['website_id'])) return null;
        $g = $this->safe(fn () => $this->q("SELECT slug,custom_domain FROM generated_websites WHERE id=? AND user_id=? AND status IN ('published','active','live') LIMIT 1", [(int) $p['website_id'], $uid]))[0] ?? null;
        if (!$g || empty($g['slug'])) return null;
        $domain = trim((string) ($g['custom_domain'] ?? ''));
        $base = $domain !== '' && preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/i', $domain) ? 'https://' . strtolower($domain) : rtrim(defined('APP_URL') ? (string) APP_URL : '', '/') . '/sites/' . rawurlencode((string) $g['slug']);
        return $base . '/tours/' . rawurlencode((string) $p['tour_slug']);
    }

    private static function list($v): array
    {
        if ($v === null || $v === '') return [];
        $d = is_string($v) ? json_decode($v, true) : $v;
        if (!is_array($d)) $d = preg_split('/[,;]+/', (string) $v);
        $out = [];
        foreach ($d as $x) foreach (preg_split('/[,;]+/', (string) $x) as $part) if (($part = trim($part)) !== '') $out[] = $part;
        return array_values(array_unique($out));
    }

    private function safe(callable $fn): array
    {
        try { return $fn(); } catch (Throwable $e) {
            if (class_exists('Logger')) Logger::warning('Growth signal source unavailable', ['error' => $e->getMessage()]);
            return [];
        }
    }
}
