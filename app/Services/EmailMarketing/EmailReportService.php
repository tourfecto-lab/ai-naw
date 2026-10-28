<?php

/**
 * Tourfecto - تقارير الحملات (سلسلة زمنية، أفضل ساعات، مقارنة، CSV، إيرادات)
 * @version 1.0.0
 *
 * ملاحظات:
 *  - كل الأوقات UTC (opened_at/clicked_at بتتسجل بتوقيت الخادم؛ اضبط $tzOffsetMinutes لعرض توقيت العميل).
 *  - الإيرادات: دالة اختيارية revenueResolver(userId, campaignId, recipientEmails[]): array{revenue:float,orders:int}
 *    تُحقن من مشروعك (مثلاً تربط الإيميلات بجدول الحجوزات). من غيرها الإيراد = null (مش صفر).
 *  - لازم تتحقق من ملكية الحملة قبل كل تقرير (owned()).
 */
class EmailReportService
{
    private $db;
    /** @var callable|null */
    private $revenueResolver;

    public function __construct($db = null, ?callable $revenueResolver = null)
    {
        $this->db = $db ?: Database::getInstance();
        $this->revenueResolver = $revenueResolver;
    }

    // ------------------------------------------------------------ pure

    public static function rate(int $part, int $whole): float
    {
        return $whole > 0 ? round($part * 100 / $whole, 2) : 0.0;
    }

    /** يحول صفوف [{ts,n}] لـ 24 خانة ساعة (0..23) بعد إزاحة التوقيت. */
    public static function hourBuckets(array $rows, int $tzOffsetMinutes = 0): array
    {
        $b = array_fill(0, 24, 0);
        foreach ($rows as $r) {
            $t = strtotime((string) $r['ts'] . ' UTC');
            if ($t === false) {
                continue;
            }
            $h = (int) gmdate('G', $t + $tzOffsetMinutes * 60);
            $b[$h] += (int) $r['n'];
        }
        return $b;
    }

    /** أفضل N ساعات (الأعلى تفاعلاً) — بدون ساعات صفرية. */
    public static function bestHours(array $buckets, int $top = 3): array
    {
        $out = [];
        foreach ($buckets as $h => $n) {
            if ($n > 0) {
                $out[] = ['hour' => (int) $h, 'count' => (int) $n];
            }
        }
        usort($out, fn ($a, $b) => $b['count'] <=> $a['count'] ?: $a['hour'] <=> $b['hour']);
        return array_slice($out, 0, max(1, $top));
    }

    /** سلسلة زمنية يومية مكتملة (الأيام الفاضية = 0) من صفوف [{d,n}]. */
    public static function fillDays(array $rows, string $fromDate, string $toDate): array
    {
        $map = [];
        foreach ($rows as $r) {
            $map[(string) $r['d']] = (int) $r['n'];
        }
        $out = [];
        $t = strtotime($fromDate . ' UTC');
        $end = strtotime($toDate . ' UTC');
        for ($i = 0; $t !== false && $end !== false && $t <= $end && $i < 400; $t += 86400, $i++) {
            $d = gmdate('Y-m-d', $t);
            $out[] = ['date' => $d, 'count' => $map[$d] ?? 0];
        }
        return $out;
    }

    /** CSV آمن: UTF-8 BOM لإكسل + حماية من CSV/Formula injection. */
    public static function toCsv(array $header, array $rows): string
    {
        $esc = static function ($v): string {
            $s = (string) $v;
            if ($s !== '' && strpbrk($s[0], "=+-@\t\r") !== false) {
                $s = "'" . $s;
            }
            return '"' . str_replace('"', '""', $s) . '"';
        };
        $lines = [implode(',', array_map($esc, $header))];
        foreach ($rows as $r) {
            $lines[] = implode(',', array_map($esc, $r));
        }
        return "\xEF\xBB\xBF" . implode("\r\n", $lines) . "\r\n";
    }

    // ------------------------------------------------------------ queries

    private function owned(int $userId, int $campaignId): ?array
    {
        $r = $this->db->query('SELECT * FROM email_campaigns WHERE id = ? AND user_id = ? LIMIT 1', [$campaignId, $userId]);
        return $r[0] ?? null;
    }

    /** ملخص حملة واحدة. */
    public function summary(int $userId, int $campaignId): ?array
    {
        $c = $this->owned($userId, $campaignId);
        if (!$c) {
            return null;
        }
        $r = $this->db->query(
            "SELECT COUNT(*) AS total,
                    SUM(status IN ('sent','opened','clicked','unsubscribed')) AS delivered,
                    SUM(open_count > 0 OR opened_at IS NOT NULL) AS opened,
                    SUM(click_count > 0 OR clicked_at IS NOT NULL) AS clicked,
                    SUM(status = 'unsubscribed') AS unsub,
                    SUM(status = 'bounced') AS bounced,
                    SUM(status IN ('failed','uncertain')) AS failed
             FROM email_campaign_recipients WHERE campaign_id = ?",
            [$campaignId]
        )[0];
        $delivered = (int) $r['delivered'];
        $sum = [
            'campaign_id' => $campaignId,
            'name' => $c['name'],
            'status' => $c['status'],
            'total' => (int) $r['total'],
            'delivered' => $delivered,
            'opened' => (int) $r['opened'],
            'clicked' => (int) $r['clicked'],
            'unsubscribed' => (int) $r['unsub'],
            'bounced' => (int) $r['bounced'],
            'failed' => (int) $r['failed'],
            'open_rate' => self::rate((int) $r['opened'], $delivered),
            'click_rate' => self::rate((int) $r['clicked'], $delivered),
            'click_to_open_rate' => self::rate((int) $r['clicked'], (int) $r['opened']),
            'unsubscribe_rate' => self::rate((int) $r['unsub'], $delivered),
            'bounce_rate' => self::rate((int) $r['bounced'], (int) $r['total']),
            'revenue' => null,
            'orders' => null,
        ];
        $rev = $this->revenue($userId, $campaignId);
        if ($rev !== null) {
            $sum['revenue'] = $rev['revenue'];
            $sum['orders'] = $rev['orders'];
        }
        return $sum;
    }

    /** سلسلة زمنية (أيام) للفتح والكليك + أفضل ساعات. */
    public function timeline(int $userId, int $campaignId, int $tzOffsetMinutes = 0, int $days = 14): ?array
    {
        $c = $this->owned($userId, $campaignId);
        if (!$c) {
            return null;
        }
        $days = max(1, min(90, $days));
        $from = gmdate('Y-m-d', time() - ($days - 1) * 86400);
        $to = gmdate('Y-m-d');
        $opens = $this->db->query("SELECT DATE(opened_at) AS d, COUNT(*) AS n FROM email_campaign_recipients WHERE campaign_id = ? AND opened_at >= ? GROUP BY DATE(opened_at)", [$campaignId, $from . ' 00:00:00']);
        $clicks = $this->db->query("SELECT DATE(clicked_at) AS d, COUNT(*) AS n FROM email_campaign_recipients WHERE campaign_id = ? AND clicked_at >= ? GROUP BY DATE(clicked_at)", [$campaignId, $from . ' 00:00:00']);
        $openTs = $this->db->query("SELECT opened_at AS ts, 1 AS n FROM email_campaign_recipients WHERE campaign_id = ? AND opened_at IS NOT NULL LIMIT 50000", [$campaignId]);
        $buckets = self::hourBuckets($openTs, $tzOffsetMinutes);
        return [
            'opens' => self::fillDays($opens, $from, $to),
            'clicks' => self::fillDays($clicks, $from, $to),
            'hours' => $buckets,
            'best_hours' => self::bestHours($buckets, 3),
        ];
    }

    /** مقارنة حتى 10 حملات. */
    public function compare(int $userId, array $campaignIds): array
    {
        $out = [];
        foreach (array_slice(array_values(array_unique(array_map('intval', $campaignIds))), 0, 10) as $id) {
            $s = $this->summary($userId, $id);
            if ($s) {
                $out[] = $s;
            }
        }
        return $out;
    }

    /** CSV لمستلمي الحملة (للتصدير). */
    public function recipientsCsv(int $userId, int $campaignId): ?string
    {
        if (!$this->owned($userId, $campaignId)) {
            return null;
        }
        $rows = $this->db->query(
            "SELECT email, name, status, open_count, click_count, opened_at, clicked_at FROM email_campaign_recipients WHERE campaign_id = ? ORDER BY id ASC LIMIT 100000",
            [$campaignId]
        );
        $data = array_map(fn ($r) => [$r['email'], $r['name'], $r['status'], $r['open_count'], $r['click_count'], $r['opened_at'], $r['clicked_at']], $rows);
        return self::toCsv(['email', 'name', 'status', 'opens', 'clicks', 'opened_at_utc', 'clicked_at_utc'], $data);
    }

    /** @return array{revenue:float,orders:int}|null */
    private function revenue(int $userId, int $campaignId): ?array
    {
        if (!$this->revenueResolver) {
            return null;
        }
        $emails = array_column($this->db->query(
            "SELECT email FROM email_campaign_recipients WHERE campaign_id = ? AND (click_count > 0 OR clicked_at IS NOT NULL) LIMIT 50000",
            [$campaignId]
        ), 'email');
        try {
            $r = ($this->revenueResolver)($userId, $campaignId, $emails);
        } catch (\Throwable $e) {
            return null;
        }
        return is_array($r) ? ['revenue' => (float) ($r['revenue'] ?? 0), 'orders' => (int) ($r['orders'] ?? 0)] : null;
    }
}
