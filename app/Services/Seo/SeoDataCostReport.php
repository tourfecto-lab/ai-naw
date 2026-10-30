<?php

/**
 * تكلفة DataForSEO الحقيقية: كل عميل صرف كام الشهر ده وفي إيه، ومتوقع يقفل الشهر على كام.
 * الأرقام من dataforseo_calls (التكلفة اللي DataForSEO رجّعها فعلاً، والمحجوز للمكالمات اللي لسه ماخلصتش).
 * website_id = 0 ← مصروف المنصة نفسها (التحليل الذكي قبل التسجيل).
 */
final class SeoDataCostReport
{
    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }

    /** بند الصرف من اسم الـendpoint (نقية) */
    public static function category(string $endpoint): string
    {
        if (str_starts_with($endpoint, 'serp/google/maps')) return 'local';
        if (str_starts_with($endpoint, 'serp/')) return 'serp';
        if (str_starts_with($endpoint, 'backlinks/')) return 'backlinks';
        if (str_contains($endpoint, 'keyword_overview') || str_contains($endpoint, 'search_volume') || str_contains($endpoint, 'bulk_keyword_difficulty')) return 'volumes';
        if (str_starts_with($endpoint, 'dataforseo_labs/')) return 'research';
        return 'other';
    }

    public static function categoryLabel(string $cat): string
    {
        return [
            'serp' => 'بحث جوجل (فحص الترتيب + تحضير المقالات)',
            'local' => 'خرائط جوجل (شبكة الترتيب المحلي)',
            'research' => 'بحث الكلمات والمنافسين',
            'volumes' => 'أحجام البحث وصعوبة الكلمات',
            'backlinks' => 'الباك لينك',
            'other' => 'أخرى',
        ][$cat] ?? $cat;
    }

    /** المتوقع آخر الشهر بنفس معدل الصرف لحد النهارده (نقية) */
    public static function projection(float $spent, int $day, int $daysInMonth): float
    {
        return $day > 0 ? round($spent / $day * $daysInMonth, 2) : 0.0;
    }

    public function build(): array
    {
        $out = ['available' => false, 'month' => gmdate('Y-m'), 'totals' => null, 'last_month' => null, 'categories' => [], 'customers' => [], 'platform' => null, 'endpoints' => [], 'days' => [], 'statuses' => []];
        if (!$this->db->query("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'dataforseo_calls'")) return $out;
        $out['available'] = true;
        $start = gmdate('Y-m-01 00:00:00');
        $day = (int) gmdate('j');
        $dim = (int) gmdate('t');

        $t = $this->db->query("SELECT COUNT(*) AS calls, COALESCE(SUM(cost),0) AS cost, COALESCE(SUM(GREATEST(reserved_cost-cost,0)),0) AS held FROM dataforseo_calls WHERE created_at >= ?", [$start])[0] ?? [];
        $cost = (float) ($t['cost'] ?? 0);
        $out['totals'] = ['calls' => (int) ($t['calls'] ?? 0), 'cost' => round($cost, 4), 'held' => round((float) ($t['held'] ?? 0), 4), 'projected' => self::projection($cost, $day, $dim)];
        $l = $this->db->query("SELECT COUNT(*) AS calls, COALESCE(SUM(cost),0) AS cost FROM dataforseo_calls WHERE created_at >= DATE_SUB(?, INTERVAL 1 MONTH) AND created_at < ?", [$start, $start])[0] ?? [];
        $out['last_month'] = ['calls' => (int) ($l['calls'] ?? 0), 'cost' => round((float) ($l['cost'] ?? 0), 4)];

        // كل خدمة بتتكلف كام (المتوسط بيبيّن لو فيه طلب غالي)، وكل يوم، وحالات الطلبات (المحجوز والتقديري)
        foreach ($this->db->query("SELECT endpoint, COUNT(*) AS calls, COALESCE(SUM(cost),0) AS cost, COALESCE(SUM(GREATEST(reserved_cost-cost,0)),0) AS held FROM dataforseo_calls WHERE created_at >= ? GROUP BY endpoint ORDER BY cost DESC", [$start]) ?: [] as $r) {
            $out['endpoints'][] = ['endpoint' => (string) $r['endpoint'], 'category' => self::category((string) $r['endpoint']), 'calls' => (int) $r['calls'], 'cost' => round((float) $r['cost'], 4),
                'avg' => (int) $r['calls'] > 0 ? round((float) $r['cost'] / (int) $r['calls'], 4) : 0, 'held' => round((float) $r['held'], 4)];
        }
        foreach ($this->db->query("SELECT DATE(created_at) AS d, COUNT(*) AS calls, COALESCE(SUM(cost),0) AS cost, SUM(website_id = 0) AS platform_calls FROM dataforseo_calls WHERE created_at >= UTC_TIMESTAMP() - INTERVAL 30 DAY GROUP BY DATE(created_at) ORDER BY d DESC") ?: [] as $r) {
            $out['days'][] = ['day' => (string) $r['d'], 'calls' => (int) $r['calls'], 'cost' => round((float) $r['cost'], 4), 'platform_calls' => (int) $r['platform_calls']];
        }
        foreach ($this->db->query("SELECT status, COUNT(*) AS n, COALESCE(SUM(GREATEST(reserved_cost-cost,0)),0) AS held FROM dataforseo_calls WHERE created_at >= ? GROUP BY status", [$start]) ?: [] as $r) {
            $out['statuses'][(string) $r['status']] = ['calls' => (int) $r['n'], 'held' => round((float) $r['held'], 4)];
        }

        $rows = $this->db->query("SELECT website_id, endpoint, COUNT(*) AS calls, COALESCE(SUM(cost),0) AS cost FROM dataforseo_calls WHERE created_at >= ? GROUP BY website_id, endpoint", [$start]);
        $sites = [];
        foreach ($rows as $r) {
            $cat = self::category((string) $r['endpoint']);
            $c = (float) $r['cost'];
            $out['categories'][$cat] = ($out['categories'][$cat] ?? 0) + $c;
            $wid = (int) $r['website_id'];
            $sites[$wid]['calls'] = ($sites[$wid]['calls'] ?? 0) + (int) $r['calls'];
            $sites[$wid]['cost'] = ($sites[$wid]['cost'] ?? 0) + $c;
            $sites[$wid]['cats'][$cat] = ($sites[$wid]['cats'][$cat] ?? 0) + $c;
        }
        arsort($out['categories']);
        if (isset($sites[0])) {
            $out['platform'] = ['calls' => $sites[0]['calls'], 'cost' => round($sites[0]['cost'], 4)];
            unset($sites[0]);
        }

        $ids = array_keys($sites);
        $info = [];
        if ($ids) {
            $in = implode(',', array_fill(0, count($ids), '?'));
            foreach ($this->db->query('SELECT w.id, w.user_id, ' . WebsiteSchema::col(WebsiteSchema::url(), 'url', 'w') . ', u.email FROM websites w LEFT JOIN users u ON u.id = w.user_id WHERE w.id IN (' . $in . ')', $ids) as $w) $info[(int) $w['id']] = $w;
        }
        $tracked = [];
        if ($ids) {
            try {
                foreach ($this->db->query('SELECT website_id, COUNT(*) AS n FROM tracked_keywords WHERE website_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') GROUP BY website_id', $ids) as $k) $tracked[(int) $k['website_id']] = (int) $k['n'];
            } catch (Throwable $e) {
            error_log('[SeoDataCostReport] ignored error: ' . $e->getMessage());
            }
        }
        foreach ($sites as $wid => $s) {
            arsort($s['cats']);
            $uid = (int) ($info[$wid]['user_id'] ?? 0);
            $quota = null;
            if ($uid && class_exists('PlanUsage')) {
                try { $q = PlanUsage::quota($uid, 'seo_data'); $quota = ['used' => (int) $q['used'], 'limit' => (int) $q['limit']]; } catch (Throwable $e) {error_log('[SeoDataCostReport] ignored error: ' . $e->getMessage());}
            }
            $out['customers'][] = [
                'website_id' => $wid, 'user_id' => $uid, 'email' => (string) ($info[$wid]['email'] ?? ''), 'url' => (string) ($info[$wid]['url'] ?? ''),
                'calls' => $s['calls'], 'cost' => round($s['cost'], 4), 'projected' => self::projection($s['cost'], $day, $dim),
                'top' => array_key_first($s['cats']), 'tracked_keywords' => $tracked[$wid] ?? 0, 'quota' => $quota,
            ];
        }
        usort($out['customers'], fn ($a, $b) => $b['cost'] <=> $a['cost']);
        return $out;
    }
}
