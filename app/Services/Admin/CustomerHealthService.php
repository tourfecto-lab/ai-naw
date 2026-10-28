<?php

/**
 * Tourfecto - صحة العميل وربحيته
 *
 * درجة من 100 لكل عميل، مبنية على حاجات بتقول هل هيكمّل ولا هيمشي:
 *   - النشاط (آخر دخول)                         30
 *   - الربط (موقع/جوجل/واتساب مربوطين)          20
 *   - الاستخدام آخر 30 يوم (AI / أتمتة / شات)   20
 *   - الدفع (اشتراك شغّال ومفيش تأخير)          20
 *   - الإعداد (عنده موقع)                        10
 * ومعاها أسباب مكتوبة بالعربي ("مادخلش من 45 يوم") عشان الأدمن يعرف يكلّمه في إيه.
 *
 * الربحية (آخر 30 يوم): الإيراد (اللي اتخصم من محفظته) - تكلفة الذكاء الاصطناعي اللي استهلكها.
 *
 * @version 1.0.0
 */
class CustomerHealthService
{
    /** @var Database */
    private $db;

    private const MONTHLY = "(CASE WHEN COALESCE(sp.billing_cycle, s.plan_type) IN ('yearly','annual') THEN COALESCE(sp.price, s.price) / 12 ELSE COALESCE(sp.price, s.price) END)";

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?: Database::getInstance();
    }

    /**
     * @param string $segment all | at_risk | watch | healthy | paying | unprofitable
     */
    public function list(string $segment = 'at_risk', string $q = '', int $limit = 200): array
    {
        $rows = $this->baseRows($q, $limit * 3);
        $out = [];
        foreach ($rows as $r) {
            $h = $this->score($r);
            $item = array_merge($this->publicFields($r), $h, $this->profit($r));
            $out[] = $item;
        }
        $counts = ['healthy' => 0, 'watch' => 0, 'at_risk' => 0];
        foreach ($out as $o) {
            $counts[$o['band']]++;
        }
        $mrrAtRisk = array_sum(array_map(fn ($o) => $o['band'] === 'at_risk' && $o['paying'] ? $o['mrr'] : 0, $out));

        $out = array_values(array_filter($out, function ($o) use ($segment) {
            switch ($segment) {
                case 'at_risk':
                    return $o['band'] === 'at_risk' && ($o['paying'] || $o['status'] === 'past_due');
                case 'watch':
                    return $o['band'] === 'watch';
                case 'healthy':
                    return $o['band'] === 'healthy';
                case 'paying':
                    return $o['paying'];
                case 'unprofitable':
                    return $o['ai_cost_30d'] > 0 && $o['margin_30d'] < 0;
                default:
                    return true;
            }
        }));
        usort($out, function ($a, $b) use ($segment) {
            if ($segment === 'unprofitable') {
                return $a['margin_30d'] <=> $b['margin_30d'];
            }
            if ($segment === 'healthy') {
                return $b['score'] <=> $a['score'];
            }
            // الأخطر والأغلى الأول
            return [$a['score'], -$a['mrr']] <=> [$b['score'], -$b['mrr']];
        });

        return [
            'items' => array_slice($out, 0, $limit),
            'counts' => $counts,
            'mrr_at_risk' => round($mrrAtRisk, 2),
            'profit' => $this->profitSummary(),
        ];
    }

    /** صحة عميل واحد (لملف العميل 360) */
    public function forUser(int $userId): ?array
    {
        $rows = $this->baseRows('', 1, $userId);
        if (!$rows) {
            return null;
        }
        return array_merge($this->publicFields($rows[0]), $this->score($rows[0]), $this->profit($rows[0]));
    }

    // ------------------------------------------------------------------

    private function baseRows(string $q, int $limit, ?int $userId = null): array
    {
        $where = "u.deleted_at IS NULL AND u.role NOT IN ('admin','super_admin') AND (u.owner_user_id IS NULL OR u.owner_user_id = 0)";
        $p = [];
        if ($userId) {
            $where .= ' AND u.id = :uid';
            $p[':uid'] = $userId;
        }
        if ($q !== '') {
            $where .= ' AND (u.email LIKE :q1 OR u.company_name LIKE :q2)';
            $p[':q1'] = '%' . $q . '%';
            $p[':q2'] = '%' . $q . '%';
        }
        // users مافيهوش last_activity على السيرفر (كان بيوقع الاستعلام فـ«صحة العملاء» كانت فاضية لكل العملاء)
        $sql = "SELECT u.id, u.email, u.company_name, u.created_at, u.status AS account_status,
                       COALESCE(u.last_login, '1970-01-01') AS last_seen,
                       s.status sub_status, s.cancel_at_period_end, COALESCE(sp.name, s.plan_name) plan,
                       COALESCE(" . self::MONTHLY . ", 0) mrr,
                       (SELECT COUNT(*) FROM websites w WHERE w.user_id = u.id) sites,
                       (SELECT COUNT(*) FROM websites w WHERE w.user_id = u.id AND w.is_connected = 1) sites_connected,
                       (SELECT COUNT(*) FROM platform_connections pc WHERE pc.user_id = u.id AND pc.status = 'connected') connections,
                       (SELECT COUNT(*) FROM api_usage_logs a WHERE a.user_id = u.id AND a.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)) ai_calls_30d,
                       (SELECT COALESCE(SUM(a.cost_in_usd), 0) FROM api_usage_logs a WHERE a.user_id = u.id AND a.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)) ai_cost_30d,
                       (SELECT COALESCE(-SUM(t.amount), 0) FROM wallet_transactions t WHERE t.user_id = u.id AND t.amount < 0 AND t.status = 'completed'
                           AND t.type <> 'refund' AND t.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)) revenue_30d
                  FROM users u
                  LEFT JOIN subscriptions s ON s.id = (SELECT s2.id FROM subscriptions s2 WHERE s2.user_id = u.id ORDER BY FIELD(s2.status,'active','past_due','trialing') DESC, s2.id DESC LIMIT 1)
                  LEFT JOIN subscription_plans sp ON sp.id = s.plan_id
                 WHERE $where
                 ORDER BY u.id DESC LIMIT " . (int) $limit;
        $rows = $this->query($sql, $p);
        if (!$rows) {
            return [];
        }
        // استخدام الأتمتة (لو الجدول موجود)
        $ids = implode(',', array_map(fn ($r) => (int) $r['id'], $rows));
        $auto = [];
        foreach ($this->query("SELECT user_id, COUNT(*) n FROM automation_decisions WHERE user_id IN ($ids) AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) GROUP BY user_id") as $a) {
            $auto[(int) $a['user_id']] = (int) $a['n'];
        }
        foreach ($rows as &$r) {
            $r['automation_30d'] = $auto[(int) $r['id']] ?? 0;
        }
        return $rows;
    }

    private function score(array $r): array
    {
        $score = 0;
        $reasons = [];
        $days = $r['last_seen'] && $r['last_seen'] > '1971' ? (int) floor((time() - strtotime($r['last_seen'])) / 86400) : null;

        // النشاط
        if ($days === null) {
            $reasons[] = 'مادخلش ولا مرة';
        } elseif ($days <= 7) {
            $score += 30;
        } elseif ($days <= 14) {
            $score += 20;
        } elseif ($days <= 30) {
            $score += 10;
            $reasons[] = "مادخلش من {$days} يوم";
        } else {
            $reasons[] = "مادخلش من {$days} يوم";
        }

        // الربط
        $links = (int) $r['connections'] + (int) $r['sites_connected'];
        if ($links >= 2) {
            $score += 20;
        } elseif ($links === 1) {
            $score += 12;
        } else {
            $reasons[] = 'مش رابط أي حاجة (موقع/جوجل/واتساب)';
        }

        // الاستخدام
        $use = (int) $r['ai_calls_30d'] + (int) $r['automation_30d'];
        if ($use >= 20) {
            $score += 20;
        } elseif ($use >= 5) {
            $score += 12;
        } elseif ($use >= 1) {
            $score += 6;
            $reasons[] = 'استخدامه قليل آخر 30 يوم';
        } else {
            $reasons[] = 'مااستخدمش المنصة آخر 30 يوم';
        }

        // الدفع
        $st = (string) ($r['sub_status'] ?? '');
        if ($st === 'active' && empty($r['cancel_at_period_end'])) {
            $score += 20;
        } elseif ($st === 'active') {
            $score += 5;
            $reasons[] = 'طالب يلغي آخر الفترة';
        } elseif ($st === 'trialing') {
            $score += 12;
        } elseif ($st === 'past_due') {
            $reasons[] = 'الدفع متأخر';
        } elseif (in_array($st, ['cancelled', 'canceled', 'expired'], true)) {
            $reasons[] = 'لغى الاشتراك';
        } else {
            $score += 5;
        }

        // الإعداد
        if ((int) $r['sites'] > 0) {
            $score += 10;
        } else {
            $reasons[] = 'لسه ماضافش موقعه';
        }

        $score = max(0, min(100, $score));
        $band = $score >= 70 ? 'healthy' : ($score >= 40 ? 'watch' : 'at_risk');
        if ($st === 'past_due' && $band === 'healthy') {
            $band = 'watch';
        }
        return ['score' => $score, 'band' => $band, 'reasons' => $reasons, 'days_since_seen' => $days];
    }

    private function profit(array $r): array
    {
        $rev = round((float) $r['revenue_30d'], 2);
        $cost = round((float) $r['ai_cost_30d'], 2);
        $margin = round($rev - $cost, 2);
        return [
            'revenue_30d' => $rev,
            'ai_cost_30d' => $cost,
            'margin_30d' => $margin,
            'margin_pct' => $rev > 0 ? round(100 * $margin / $rev, 1) : null,
        ];
    }

    private function publicFields(array $r): array
    {
        $st = (string) ($r['sub_status'] ?? '');
        return [
            'id' => (int) $r['id'],
            'email' => $r['email'],
            'name' => $r['company_name'] ?: $r['email'],
            'plan' => $r['plan'],
            'status' => $st ?: 'free',
            'paying' => in_array($st, ['active', 'past_due'], true),
            'mrr' => round((float) $r['mrr'] * (in_array($st, ['active', 'past_due'], true) ? 1 : 0), 2),
            'last_seen' => $r['last_seen'] > '1971' ? $r['last_seen'] : null,
            'created_at' => $r['created_at'],
            'ai_calls_30d' => (int) $r['ai_calls_30d'],
            'automation_30d' => (int) $r['automation_30d'],
            'connections' => (int) $r['connections'] + (int) $r['sites_connected'],
        ];
    }

    /** ربحية المنصة كلها آخر 30 يوم + حسب الباقة */
    private function profitSummary(): array
    {
        $rev = (float) ($this->query("SELECT COALESCE(-SUM(amount), 0) v FROM wallet_transactions WHERE amount < 0 AND status = 'completed' AND type <> 'refund' AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)")[0]['v'] ?? 0);
        $cost = (float) ($this->query('SELECT COALESCE(SUM(cost_in_usd), 0) v FROM api_usage_logs WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)')[0]['v'] ?? 0);
        $byPlan = $this->query(
            "SELECT COALESCE(sp.name, s.plan_name, 'بدون باقة') plan, COUNT(DISTINCT s.user_id) customers,
                    ROUND(AVG(" . self::MONTHLY . "), 2) price,
                    ROUND(COALESCE(SUM(c.cost), 0) / NULLIF(COUNT(DISTINCT s.user_id), 0), 2) avg_ai_cost
               FROM subscriptions s LEFT JOIN subscription_plans sp ON sp.id = s.plan_id
               LEFT JOIN (SELECT user_id, SUM(cost_in_usd) cost FROM api_usage_logs WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) GROUP BY user_id) c ON c.user_id = s.user_id
              WHERE s.status IN ('active','past_due') GROUP BY plan ORDER BY price DESC"
        );
        foreach ($byPlan as &$p) {
            $p['margin_pct'] = (float) $p['price'] > 0 ? round(100 * ((float) $p['price'] - (float) $p['avg_ai_cost']) / (float) $p['price'], 1) : null;
        }
        return [
            'revenue_30d' => round($rev, 2),
            'ai_cost_30d' => round($cost, 2),
            'margin_30d' => round($rev - $cost, 2),
            'margin_pct' => $rev > 0 ? round(100 * ($rev - $cost) / $rev, 1) : null,
            'by_plan' => $byPlan,
        ];
    }

    private function query(string $sql, array $p = []): array
    {
        try {
            return $this->db->query($sql, $p) ?: [];
        } catch (Throwable $e) {
            if (class_exists('Logger')) {
                Logger::warning('CustomerHealth query failed', ['error' => $e->getMessage()]);
            }
            return [];
        }
    }
}
