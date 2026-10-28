<?php

/**
 * Tourfecto - أرقام الـ SaaS الأساسية
 *
 * التعريفات (نفس تعريف AdminProController عشان الأرقام ماتختلفش بين الصفحات):
 *  - MRR: مجموع السعر الشهري للاشتراكات active/past_due (السنوي ÷ 12). التجربة المجانية مش محسوبة.
 *  - ARR = MRR × 12.  ARPU = MRR ÷ عدد العملاء اللي بيدفعوا.
 *  - Churn (عملاء): اللي ألغوا في الفترة ÷ اللي كانوا مشتركين في أولها.
 *  - Churn (إيراد): الـ MRR اللي راح بالإلغاء ÷ الـ MRR في أول الفترة.
 *  - LTV = ARPU ÷ نسبة الإلغاء الشهرية (لو مفيش إلغاء كفاية بيرجع null بدل رقم وهمي).
 *  - تحويل التجربة: من اللي تجربتهم خلصت آخر 90 يوم، كام واحد بقى بيدفع.
 *  - Cohorts: العملاء حسب شهر أول اشتراك، وكام % منهم لسه بيدفع بعد شهر/شهرين/...
 *
 * @version 1.0.0
 */
class SaasMetricsService
{
    /** @var Database */
    private $db;

    private const MONTHLY = "(CASE WHEN COALESCE(sp.billing_cycle, s.plan_type) IN ('yearly','annual') THEN COALESCE(sp.price, s.price) / 12 ELSE COALESCE(sp.price, s.price) END)";
    private const ENDED_AT = "COALESCE(s.cancelled_at, s.updated_at)";

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?: Database::getInstance();
    }

    public function summary(int $months = 12): array
    {
        $months = max(3, min(24, $months));
        $now = date('Y-m-d H:i:s');
        $d30 = date('Y-m-d H:i:s', strtotime('-30 days'));
        $d60 = date('Y-m-d H:i:s', strtotime('-60 days'));

        $mrr = $this->mrrAt($now);
        $mrr30 = $this->mrrAt($d30);
        $paying = $this->payingAt($now);
        $paying30 = $this->payingAt($d30);

        $churn = $this->churn($d30, $now);
        $churnPrev = $this->churn($d60, $d30);

        $arpu = $paying > 0 ? $mrr / $paying : 0;
        $arpu30 = $paying30 > 0 ? $mrr30 / $paying30 : 0;
        $ltv = $churn['logo_rate'] > 0 ? $arpu / ($churn['logo_rate'] / 100) : null;

        $cash = $this->cash($d30, $now);
        $cashPrev = $this->cash($d60, $d30);
        $trial = $this->trialConversion();

        return [
            'kpis' => [
                'mrr' => ['value' => round($mrr, 2), 'prev' => round($mrr30, 2), 'format' => 'money'],
                'arr' => ['value' => round($mrr * 12, 2), 'prev' => round($mrr30 * 12, 2), 'format' => 'money'],
                'paying' => ['value' => $paying, 'prev' => $paying30, 'format' => 'int'],
                'arpu' => ['value' => round($arpu, 2), 'prev' => round($arpu30, 2), 'format' => 'money'],
                'logo_churn' => ['value' => $churn['logo_rate'], 'prev' => $churnPrev['logo_rate'], 'format' => 'pct', 'inverse' => true],
                'revenue_churn' => ['value' => $churn['revenue_rate'], 'prev' => $churnPrev['revenue_rate'], 'format' => 'pct', 'inverse' => true],
                'ltv' => ['value' => $ltv !== null ? round($ltv, 2) : null, 'prev' => null, 'format' => 'money'],
                'trial_conversion' => ['value' => $trial['rate'], 'prev' => null, 'format' => 'pct', 'hint' => $trial['ended'] . ' تجربة خلصت آخر 90 يوم'],
                'cash_30d' => ['value' => round($cash, 2), 'prev' => round($cashPrev, 2), 'format' => 'money'],
            ],
            'series' => $this->series($months),
            'plans' => $this->byPlan(),
            'cohorts' => $this->cohorts(6),
            'churned_recent' => $this->recentChurn(),
            'definitions' => [
                'mrr' => 'مجموع الاشتراك الشهري لكل اللي بيدفعوا دلوقتي (السنوي مقسوم على 12). التجربة المجانية مش محسوبة.',
                'arr' => 'الـ MRR × 12: الإيراد المتوقع في سنة لو مفيش حد زاد ولا ألغى.',
                'arpu' => 'متوسط اللي بيدفعه العميل الواحد في الشهر.',
                'logo_churn' => 'نسبة العملاء اللي ألغوا آخر 30 يوم من اللي كانوا مشتركين في أولها.',
                'revenue_churn' => 'نسبة الإيراد الشهري اللي راح بالإلغاء آخر 30 يوم.',
                'ltv' => 'متوسط اللي العميل هيدفعه طول فترة اشتراكه = ARPU ÷ نسبة الإلغاء الشهرية.',
                'trial_conversion' => 'من اللي تجربتهم المجانية خلصت آخر 90 يوم، كام % اشترك فعلاً.',
                'cash_30d' => 'الفلوس اللي دخلت فعلاً (إيداعات مكتملة) آخر 30 يوم.',
            ],
        ];
    }

    // ------------------------------------------------------------------ الحسابات

    /** MRR في لحظة معيّنة */
    public function mrrAt(string $at): float
    {
        $r = $this->one(
            'SELECT COALESCE(SUM(' . self::MONTHLY . '), 0) v FROM subscriptions s LEFT JOIN subscription_plans sp ON sp.id = s.plan_id
              WHERE ' . $this->activeAt(),
            [':at' => $at, ':at2' => $at]
        );
        return (float) ($r['v'] ?? 0);
    }

    public function payingAt(string $at): int
    {
        $r = $this->one('SELECT COUNT(DISTINCT s.user_id) v FROM subscriptions s WHERE ' . $this->activeAt(), [':at' => $at, ':at2' => $at]);
        return (int) ($r['v'] ?? 0);
    }

    /** شرط "كان بيدفع في اللحظة :at" (اتعمل قبلها، ومااتلغاش قبلها، ومش تجربة) */
    /** المنح المجانية من الأدمن وحسابات التجربة مش إيراد حقيقي */
    private static function real(string $a = 's'): string
    {
        return " AND COALESCE({$a}.payment_gateway, '') <> 'admin_grant'" . (class_exists('TestAccounts') ? TestAccounts::exclude($a . '.user_id') : '');
    }

    private function activeAt(): string
    {
        return "s.created_at <= :at AND (
                    s.status IN ('active','past_due')
                 OR (s.status IN ('cancelled','canceled','expired') AND " . self::ENDED_AT . " > :at2)
                )" . self::real();
    }

    private function churn(string $from, string $to): array
    {
        $startCount = $this->payingAt($from);
        $startMrr = $this->mrrAt($from);
        $r = $this->one(
            "SELECT COUNT(DISTINCT s.user_id) n, COALESCE(SUM(" . self::MONTHLY . "), 0) mrr
               FROM subscriptions s LEFT JOIN subscription_plans sp ON sp.id = s.plan_id
              WHERE s.status IN ('cancelled','canceled','expired') AND " . self::ENDED_AT . " > :f AND " . self::ENDED_AT . " <= :t
                AND s.created_at <= :f2
                AND NOT EXISTS (SELECT 1 FROM subscriptions s2 WHERE s2.user_id = s.user_id AND s2.id <> s.id AND s2.status IN ('active','past_due'))" . self::real(),
            [':f' => $from, ':t' => $to, ':f2' => $from]
        );
        $n = (int) ($r['n'] ?? 0);
        $m = (float) ($r['mrr'] ?? 0);
        return [
            'count' => $n,
            'mrr' => round($m, 2),
            'logo_rate' => $startCount > 0 ? round(100 * $n / $startCount, 1) : 0.0,
            'revenue_rate' => $startMrr > 0 ? round(100 * $m / $startMrr, 1) : 0.0,
        ];
    }

    private function cash(string $from, string $to): float
    {
        $r = $this->one("SELECT COALESCE(SUM(amount), 0) v FROM wallet_transactions WHERE type = 'deposit' AND status = 'completed' AND created_at > :f AND created_at <= :t" . (class_exists('TestAccounts') ? TestAccounts::exclude('user_id') : ''), [':f' => $from, ':t' => $to]);
        return (float) ($r['v'] ?? 0);
    }

    private function trialConversion(): array
    {
        $r = $this->one(
            "SELECT COUNT(*) ended, SUM(status IN ('active','past_due')) converted FROM subscriptions
              WHERE trial_ends_at IS NOT NULL AND trial_ends_at <= NOW() AND trial_ends_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)" . (class_exists('TestAccounts') ? TestAccounts::exclude('user_id') : '')
        );
        $ended = (int) ($r['ended'] ?? 0);
        return ['ended' => $ended, 'rate' => $ended > 0 ? round(100 * (int) $r['converted'] / $ended, 1) : null];
    }

    /** سلسلة شهرية: MRR آخر الشهر، الجديد، اللي راح، عدد العملاء */
    private function series(int $months): array
    {
        $out = [];
        for ($i = $months - 1; $i >= 0; $i--) {
            $start = date('Y-m-01 00:00:00', strtotime("first day of -$i month"));
            $end = date('Y-m-t 23:59:59', strtotime($start));
            $endCap = min(strtotime($end), time());
            $endStr = date('Y-m-d H:i:s', $endCap);
            $new = $this->one(
                'SELECT COUNT(*) n, COALESCE(SUM(' . self::MONTHLY . "), 0) mrr FROM subscriptions s LEFT JOIN subscription_plans sp ON sp.id = s.plan_id
                  WHERE s.created_at BETWEEN :s AND :e AND s.status <> 'trialing'" . self::real(),
                [':s' => $start, ':e' => $endStr]
            );
            $lost = $this->one(
                'SELECT COUNT(*) n, COALESCE(SUM(' . self::MONTHLY . "), 0) mrr FROM subscriptions s LEFT JOIN subscription_plans sp ON sp.id = s.plan_id
                  WHERE s.status IN ('cancelled','canceled','expired') AND " . self::ENDED_AT . ' BETWEEN :s AND :e' . self::real(),
                [':s' => $start, ':e' => $endStr]
            );
            $out[] = [
                'month' => substr($start, 0, 7),
                'mrr' => round($this->mrrAt($endStr), 2),
                'paying' => $this->payingAt($endStr),
                'new_mrr' => round((float) ($new['mrr'] ?? 0), 2),
                'new_subs' => (int) ($new['n'] ?? 0),
                'churned_mrr' => round((float) ($lost['mrr'] ?? 0), 2),
                'churned_subs' => (int) ($lost['n'] ?? 0),
                'cash' => round($this->cash($start, $endStr), 2),
            ];
        }
        return $out;
    }

    private function byPlan(): array
    {
        return $this->rows(
            'SELECT COALESCE(sp.name, s.plan_name, \'—\') plan, COUNT(*) subs, ROUND(SUM(' . self::MONTHLY . "), 2) mrr
               FROM subscriptions s LEFT JOIN subscription_plans sp ON sp.id = s.plan_id
              WHERE s.status IN ('active','past_due')" . self::real() . " GROUP BY plan ORDER BY mrr DESC"
        );
    }

    /** Cohorts حسب شهر أول اشتراك مدفوع: % لسه بيدفع بعد k شهر */
    private function cohorts(int $n): array
    {
        $rows = $this->rows(
            "SELECT s.user_id, MIN(s.created_at) first_at FROM subscriptions s
              WHERE s.status <> 'trialing' AND s.created_at >= :from" . self::real() . " GROUP BY s.user_id",
            [':from' => date('Y-m-01 00:00:00', strtotime('first day of -' . ($n - 1) . ' month'))]
        );
        $byMonth = [];
        foreach ($rows as $r) {
            $byMonth[substr($r['first_at'], 0, 7)][] = (int) $r['user_id'];
        }
        $out = [];
        for ($i = $n - 1; $i >= 0; $i--) {
            $m = date('Y-m', strtotime("first day of -$i month"));
            $users = $byMonth[$m] ?? [];
            $cells = [];
            for ($k = 0; $k <= $i; $k++) {
                if (!$users) {
                    $cells[] = null;
                    continue;
                }
                $at = date('Y-m-t 23:59:59', strtotime("$m-01 +$k month"));
                $at = date('Y-m-d H:i:s', min(strtotime($at), time()));
                $in = implode(',', array_map('intval', $users));
                $r = $this->one("SELECT COUNT(DISTINCT s.user_id) v FROM subscriptions s WHERE s.user_id IN ($in) AND " . $this->activeAt(), [':at' => $at, ':at2' => $at]);
                $cells[] = round(100 * (int) ($r['v'] ?? 0) / count($users));
            }
            $out[] = ['month' => $m, 'size' => count($users), 'retention' => $cells];
        }
        return $out;
    }

    private function recentChurn(): array
    {
        return $this->rows(
            "SELECT s.user_id, u.email, COALESCE(NULLIF(u.company_name,''), u.email) name, COALESCE(sp.name, s.plan_name) plan,
                    ROUND(" . self::MONTHLY . ', 2) mrr, ' . self::ENDED_AT . " ended_at, s.cancellation_reason reason
               FROM subscriptions s LEFT JOIN subscription_plans sp ON sp.id = s.plan_id JOIN users u ON u.id = s.user_id
              WHERE s.status IN ('cancelled','canceled','expired') AND " . self::ENDED_AT . " >= DATE_SUB(NOW(), INTERVAL 60 DAY)" . self::real() . "
              ORDER BY ended_at DESC LIMIT 15"
        );
    }

    // ------------------------------------------------------------------ DB

    private function one(string $sql, array $p = []): array
    {
        $r = $this->rows($sql, $p);
        return $r[0] ?? [];
    }

    private function rows(string $sql, array $p = []): array
    {
        try {
            return $this->db->query($sql, $p) ?: [];
        } catch (Throwable $e) {
            if (class_exists('Logger')) {
                Logger::warning('SaasMetrics query failed', ['error' => $e->getMessage()]);
            }
            return [];
        }
    }
}
