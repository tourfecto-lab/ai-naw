<?php
require_once __DIR__ . '/../Brain/BrainSchema.php';
require_once __DIR__ . '/AiCostIntelligence.php';

/**
 * TOURFECTO AI CEO (المرحلة 8) — الإدارة المالية والاستراتيجية.
 *
 * - الأرباح والخسائر شهريًا: الإيراد (خصومات الاشتراك الفعلية من المحفظة) والفلوس اللي دخلت (الإيداعات) و MRR،
 *   ناقص تكلفة الذكاء الاصطناعي (api_usage_logs) وبيانات السيو (dataforseo_calls) والمصاريف الثابتة اللي صاحب المنصة بيدخلها.
 * - الأسواق: العملاء اللي بيدفعوا و MRR وتكلفة الذكاء الاصطناعي حسب الدولة.
 * - فرص خفض التكاليف: من مراجعة التوجيه والقفزات في تكلفة الذكاء الاصطناعي (المرحلة 2) وتوقع DataForSEO.
 * - السيناريوهات (متحفظ / متوازن / جريء) لأي قرار كبير: التكلفة والعائد المتوقع والمخاطر والافتراضات والموارد. منطق نقي.
 * - التوأم الرقمي (Digital Twin): محاكاة تقديرية "لو حصل كذا" من أرقام المنصة الحالية. النتايج تقديرات مش حقائق.
 * - أهداف كل ربع سنة مربوطة بمؤشر حقيقي، ومهام أسبوعية لكل هدف، والتقدم بيتحسب لوحده.
 * - AI Boardroom: سؤال استراتيجي ← رأي المالية والتسويق والتقنية والتشغيل + 3 سيناريوهات (ذكاء اصطناعي على ميزانية AI CEO، صاحب المنصة بس).
 */
final class FinanceStrategy
{
    public const METRICS = ['mrr' => 'MRR (USD)', 'paying' => 'Paying customers', 'signups_30d' => 'Sign-ups (30 days)', 'logo_churn' => 'Churn % (30 days, lower is better)',
        'trial_conversion' => 'Trial → paid %', 'brand_score' => 'Tourfecto SEO score', 'incidents_open' => 'Open incidents (lower is better)', 'support_unanswered' => 'Unanswered support (lower is better)'];
    public const LOWER_IS_BETTER = ['logo_churn', 'incidents_open', 'support_unanswered'];
    private const MONTHLY = "(CASE WHEN COALESCE(sp.billing_cycle, s.plan_type) IN ('yearly','annual') THEN COALESCE(sp.price, s.price) / 12 ELSE COALESCE(sp.price, s.price) END)";
    private const PAID = "s.status IN ('active','past_due') AND COALESCE(s.payment_gateway, '') <> 'admin_grant' AND COALESCE(s.price, 0) > 0";

    private Database $db;
    /** @var callable|null للاختبارات: fn(string $prompt): ?string */
    private $ai;

    public function __construct(?Database $db = null, ?callable $ai = null)
    {
        $this->db = $db ?? Database::getInstance();
        $this->ai = $ai;
        BrainSchema::ensure($this->db);
    }

    private function q(string $sql, array $p = []): array
    {
        try { return $this->db->query($sql, $p) ?: []; } catch (Throwable $e) { return []; }
    }

    private static function notTest(string $col): string
    {
        return class_exists('TestAccounts') ? TestAccounts::exclude($col) : '';
    }

    private function hasTable(string $t): bool
    {
        return (bool) $this->q('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$t]);
    }

    // ------------------------------------------------------------------ المصاريف الثابتة

    public function costItems(bool $activeOnly = false): array
    {
        return array_map(static fn ($r) => ['id' => (int) $r['id'], 'name' => $r['name'], 'category' => $r['category'], 'monthly_usd' => (float) $r['monthly_usd'], 'active' => (bool) $r['active'], 'notes' => $r['notes']],
            $this->db->query('SELECT * FROM aiceo_cost_items' . ($activeOnly ? ' WHERE active = 1' : '') . ' ORDER BY active DESC, monthly_usd DESC') ?: []);
    }

    public function saveCostItem(array $in): int
    {
        $name = trim((string) ($in['name'] ?? ''));
        $amount = $in['monthly_usd'] ?? null;
        if ($name === '' || !is_numeric($amount) || (float) $amount < 0 || (float) $amount > 1000000) throw new InvalidArgumentException('Enter a name and a monthly amount in USD.');
        $cat = in_array($in['category'] ?? '', ['infrastructure', 'tools', 'people', 'marketing', 'other'], true) ? $in['category'] : 'other';
        $now = gmdate('Y-m-d H:i:s');
        $id = (int) ($in['id'] ?? 0);
        $args = [mb_substr($name, 0, 120), $cat, round((float) $amount, 2), array_key_exists('active', $in) ? (int) (bool) $in['active'] : 1, mb_substr((string) ($in['notes'] ?? ''), 0, 255)];
        if ($id > 0) {
            $this->db->query('UPDATE aiceo_cost_items SET name = ?, category = ?, monthly_usd = ?, active = ?, notes = ?, updated_at = ? WHERE id = ?', [...$args, $now, $id]);
            return $id;
        }
        $this->db->query('INSERT INTO aiceo_cost_items (name, category, monthly_usd, active, notes, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)', [...$args, $now, $now]);
        return (int) ($this->db->query('SELECT MAX(id) AS id FROM aiceo_cost_items')[0]['id'] ?? 0);
    }

    public function deleteCostItem(int $id): void
    {
        $this->db->query('DELETE FROM aiceo_cost_items WHERE id = ?', [$id]);
    }

    // ------------------------------------------------------------------ الأرباح والخسائر

    public function pnl(int $months = 6): array
    {
        $months = max(1, min(24, $months));
        $fixed = round(array_sum(array_column($this->costItems(true), 'monthly_usd')), 2);
        $series = [];
        try { foreach ((new SaasMetricsService($this->db))->summary(max(3, $months))['series'] ?? [] as $s) $series[$s['month']] = $s; } catch (Throwable $e) {}
        $hasData = $this->hasTable('dataforseo_calls');
        $out = [];
        for ($i = $months - 1; $i >= 0; $i--) {
            $start = date('Y-m-01 00:00:00', strtotime("first day of -{$i} month"));
            $end = date('Y-m-01 00:00:00', strtotime($start . ' +1 month'));
            $m = substr($start, 0, 7);
            $rev = (float) ($this->q("SELECT COALESCE(-SUM(amount), 0) AS v FROM wallet_transactions WHERE type = 'subscription_charge' AND status = 'completed' AND amount < 0 AND created_at >= ? AND created_at < ?" . self::notTest('user_id'), [$start, $end])[0]['v'] ?? 0);
            $refunds = (float) ($this->q("SELECT COALESCE(SUM(amount), 0) AS v FROM wallet_transactions WHERE type = 'refund' AND status = 'completed' AND created_at >= ? AND created_at < ?" . self::notTest('user_id'), [$start, $end])[0]['v'] ?? 0);
            $cash = (float) ($this->q("SELECT COALESCE(SUM(amount), 0) AS v FROM wallet_transactions WHERE type = 'deposit' AND status = 'completed' AND created_at >= ? AND created_at < ?" . self::notTest('user_id'), [$start, $end])[0]['v'] ?? 0);
            $ai = (float) ($this->q('SELECT COALESCE(SUM(cost_in_usd), 0) AS v FROM api_usage_logs WHERE created_at >= ? AND created_at < ?', [$start, $end])[0]['v'] ?? 0);
            $data = $hasData ? (float) ($this->q('SELECT COALESCE(SUM(cost), 0) AS v FROM dataforseo_calls WHERE created_at >= ? AND created_at < ?', [$start, $end])[0]['v'] ?? 0) : 0.0;
            $revenue = round($rev - $refunds, 2);
            $costs = round($ai + $data + $fixed, 2);
            $out[] = ['month' => $m, 'revenue' => $revenue, 'cash_in' => round($cash, 2), 'mrr' => $series[$m]['mrr'] ?? null, 'ai_cost' => round($ai, 2), 'data_cost' => round($data, 2), 'fixed_cost' => $fixed,
                'costs' => $costs, 'profit' => round($revenue - $costs, 2), 'margin_pct' => $revenue > 0 ? round(($revenue - $costs) / $revenue * 100, 1) : null, 'partial' => $i === 0];
        }
        return ['months' => $out, 'fixed_monthly' => $fixed, 'fixed_entered' => $fixed > 0,
            'note' => 'Revenue = subscription charges taken from wallets minus refunds. Costs = AI + DataForSEO + the fixed costs you enter. ' . ($fixed > 0 ? '' : 'No fixed costs entered yet, so profit is overstated.')];
    }

    // ------------------------------------------------------------------ الأسواق

    public function markets(): array
    {
        $rows = $this->q("SELECT COALESCE(NULLIF(u.country, ''), '?') AS country, COUNT(DISTINCT s.user_id) AS paying, ROUND(SUM(" . self::MONTHLY . "), 2) AS mrr
            FROM subscriptions s LEFT JOIN subscription_plans sp ON sp.id = s.plan_id JOIN users u ON u.id = s.user_id WHERE " . self::PAID . self::notTest('s.user_id') . ' GROUP BY country');
        $signups = array_column($this->q("SELECT COALESCE(NULLIF(u.country, ''), '?') AS country, COUNT(*) AS n FROM users u WHERE u.deleted_at IS NULL AND u.role NOT IN ('admin','super_admin') AND u.created_at >= NOW() - INTERVAL 90 DAY" . self::notTest('u.id') . ' GROUP BY country'), 'n', 'country');
        $ai = array_column($this->q("SELECT COALESCE(NULLIF(u.country, ''), '?') AS country, ROUND(SUM(a.cost_in_usd), 2) AS cost FROM api_usage_logs a JOIN users u ON u.id = a.user_id WHERE a.created_at >= NOW() - INTERVAL 30 DAY AND u.role NOT IN ('admin','super_admin') GROUP BY country"), 'cost', 'country');
        $out = [];
        foreach (array_unique(array_merge(array_column($rows, 'country'), array_keys($signups))) as $c) {
            $r = array_values(array_filter($rows, static fn ($x) => $x['country'] === $c))[0] ?? ['paying' => 0, 'mrr' => 0];
            $mrr = (float) $r['mrr'];
            $cost = (float) ($ai[$c] ?? 0);
            $out[] = ['country' => (string) $c, 'paying' => (int) $r['paying'], 'mrr' => $mrr, 'signups_90d' => (int) ($signups[$c] ?? 0), 'ai_cost_30d' => $cost, 'margin' => round($mrr - $cost, 2),
                'arpu' => (int) $r['paying'] > 0 ? round($mrr / (int) $r['paying'], 2) : null, 'conversion_pct' => (int) ($signups[$c] ?? 0) > 0 ? round((int) $r['paying'] / (int) $signups[$c] * 100, 1) : null];
        }
        usort($out, static fn ($a, $b) => [$b['margin'], $b['signups_90d']] <=> [$a['margin'], $a['signups_90d']]);
        return array_slice($out, 0, 30);
    }

    /** فرص خفض التكاليف من البيانات الموجودة */
    public function savings(): array
    {
        $out = [];
        try {
            $r = (new AiCostIntelligence($this->db))->report();
            foreach ($r['routing_review'] ?? [] as $x) $out[] = ['area' => 'AI routing', 'text' => is_array($x) ? ($x['text'] ?? json_encode($x)) : (string) $x];
            foreach ($r['anomalies'] ?? [] as $x) $out[] = ['area' => 'AI spikes', 'text' => is_array($x) ? ($x['text'] ?? json_encode($x)) : (string) $x];
            $top = $r['features'] ?? [];
            usort($top, static fn ($a, $b) => $b['cost_8d'] <=> $a['cost_8d']);
            foreach (array_slice($top, 0, 3) as $f) if ($f['cost_8d'] > 1) $out[] = ['area' => 'Top AI cost', 'text' => $f['feature'] . ' cost $' . $f['cost_8d'] . ' in 8 days: check caching or a cheaper model for it.'];
        } catch (Throwable $e) {}
        try {
            if (!class_exists('SeoDataCostReport')) require_once __DIR__ . '/../Seo/SeoDataCostReport.php';
            $d = (new SeoDataCostReport($this->db))->build();
            if (!empty($d['totals']['projected'])) $out[] = ['area' => 'SEO data', 'text' => 'DataForSEO is projected at $' . $d['totals']['projected'] . ' this month. Reuse cached results and limit tracked keywords per plan.'];
        } catch (Throwable $e) {}
        foreach ($this->costItems(true) as $c) if ($c['monthly_usd'] >= 50 && $c['category'] === 'tools') $out[] = ['area' => 'Tools', 'text' => $c['name'] . ' costs $' . $c['monthly_usd'] . '/month: confirm it is still used.'];
        return $out;
    }

    // ------------------------------------------------------------------ السيناريوهات

    /**
     * ثلاث سيناريوهات لقرار (منطق نقي).
     * @param array $in one_time_cost, monthly_cost, customers_per_month, arpu, churn_pct, months
     */
    public static function scenarios(array $in): array
    {
        $num = static fn ($k, $d = 0.0) => is_numeric($in[$k] ?? null) ? max(0.0, (float) $in[$k]) : $d;
        $months = (int) max(1, min(36, $num('months', 12)));
        $base = ['one_time' => $num('one_time_cost'), 'monthly' => $num('monthly_cost'), 'customers' => $num('customers_per_month'), 'arpu' => $num('arpu'), 'churn' => min(100, $num('churn_pct', 5))];
        $cases = ['conservative' => ['adoption' => 0.5, 'cost' => 1.2, 'churn' => 1.5], 'balanced' => ['adoption' => 1.0, 'cost' => 1.0, 'churn' => 1.0], 'aggressive' => ['adoption' => 2.0, 'cost' => 1.6, 'churn' => 1.2]];
        $out = [];
        foreach ($cases as $name => $k) {
            $customers = 0.0;
            $rev = 0.0;
            $cost = $base['one_time'] * $k['cost'];
            $payback = null;
            for ($m = 1; $m <= $months; $m++) {
                $customers = $customers * (1 - min(1, $base['churn'] * $k['churn'] / 100)) + $base['customers'] * $k['adoption'];
                $rev += $customers * $base['arpu'];
                $cost += $base['monthly'] * $k['cost'];
                if ($payback === null && $rev >= $cost && $cost > 0) $payback = $m;
            }
            $out[$name] = ['customers_end' => round($customers, 1), 'mrr_end' => round($customers * $base['arpu'], 2), 'revenue' => round($rev, 2), 'cost' => round($cost, 2), 'net' => round($rev - $cost, 2),
                'roi_pct' => $cost > 0 ? round(($rev - $cost) / $cost * 100, 1) : null, 'payback_month' => $payback,
                'assumptions' => [sprintf('%s new customers/month (×%s)', round($base['customers'] * $k['adoption'], 1), $k['adoption']), sprintf('costs ×%s of plan', $k['cost']), sprintf('churn %s%%/month', round($base['churn'] * $k['churn'], 1)), '$' . $base['arpu'] . ' per customer per month'],
                'risks' => ['conservative' => ['Slow adoption', 'Costs run over'], 'balanced' => ['Results depend on the conversion seen so far'], 'aggressive' => ['Needs more budget and team time', 'Support and server load grow fast', 'Higher churn from rushed onboarding']][$name],
                'resources' => ['conservative' => 'Current team; minimal spend', 'balanced' => 'Planned budget and current team', 'aggressive' => 'Extra budget (×1.6), more support capacity and server headroom'][$name]];
        }
        return ['months' => $months, 'inputs' => $base, 'cases' => $out, 'note' => 'Estimates from your inputs, not forecasts. Change the inputs to see how sensitive the result is.'];
    }

    // ------------------------------------------------------------------ التوأم الرقمي

    /** الأساس من أرقام المنصة الحالية */
    public function baseline(): array
    {
        $paying = 0;
        $mrr = 0.0;
        try { $k = (new SaasMetricsService($this->db))->summary(3)['kpis']; $paying = (int) ($k['paying']['value'] ?? 0); $mrr = (float) ($k['mrr']['value'] ?? 0); $churn = (float) ($k['logo_churn']['value'] ?? 0); } catch (Throwable $e) { $churn = 0.0; }
        $active = (int) ($this->q("SELECT COUNT(DISTINCT a.user_id) AS n FROM api_usage_logs a JOIN users u ON u.id = a.user_id WHERE a.created_at >= NOW() - INTERVAL 30 DAY AND u.role NOT IN ('admin','super_admin')")[0]['n'] ?? 0);
        $ai = (float) ($this->q("SELECT COALESCE(SUM(a.cost_in_usd), 0) AS v FROM api_usage_logs a JOIN users u ON u.id = a.user_id WHERE a.created_at >= NOW() - INTERVAL 30 DAY AND u.role NOT IN ('admin','super_admin')")[0]['v'] ?? 0);
        $data = $this->hasTable('dataforseo_calls') ? (float) ($this->q('SELECT COALESCE(SUM(cost), 0) AS v FROM dataforseo_calls WHERE created_at >= NOW() - INTERVAL 30 DAY')[0]['v'] ?? 0) : 0.0;
        $signups = (int) ($this->q("SELECT COUNT(*) AS n FROM users u WHERE u.deleted_at IS NULL AND u.role NOT IN ('admin','super_admin') AND u.created_at >= NOW() - INTERVAL 30 DAY" . self::notTest('u.id'))[0]['n'] ?? 0);
        $set = $this->settings();
        $users = max(1, $active);
        // دقايق الدعم لكل عميل في الشهر = دقايق الرسالة × رسايل الشهر ÷ العملاء النشطين
        $msgs = (int) ($this->q('SELECT COUNT(*) AS n FROM contact_submissions WHERE created_at >= NOW() - INTERVAL 90 DAY')[0]['n'] ?? 0);
        $set['support_minutes_per_customer'] = $set['support_minutes_per_message'] !== null ? round($set['support_minutes_per_message'] * ($msgs / 3) / $users, 2) : null;
        return ['paying' => $paying, 'active_customers' => $active, 'mrr' => round($mrr, 2), 'arpu' => $paying > 0 ? round($mrr / $paying, 2) : 0.0, 'churn_pct' => round($churn, 1),
            'ai_cost_per_customer' => round($ai / $users, 4), 'data_cost_per_customer' => round($data / $users, 4), 'fixed_monthly' => round(array_sum(array_column($this->costItems(true), 'monthly_usd')), 2),
            'signups_month' => $signups, 'conversion_pct' => $signups > 0 ? min(100, round($paying / max(1, $signups) * 100, 1)) : null,
            'capacity_customers' => $set['capacity_customers'], 'support_minutes_per_customer' => $set['support_minutes_per_customer'], 'support_cost_hour' => $set['support_cost_hour']];
    }

    /**
     * محاكاة "لو حصل كذا" (منطق نقي). levers: customers_mult, api_cost_change_pct, lose_customers_pct, signups_mult,
     * new_country_monthly_cost, new_country_customers.
     */
    public static function simulate(array $b, array $levers): array
    {
        $lv = static fn ($k, $d) => is_numeric($levers[$k] ?? null) ? (float) $levers[$k] : $d;
        $customers = max(0, $b['active_customers']);
        $paying = max(0, $b['paying']);
        $mult = max(0, $lv('customers_mult', 1));
        $lose = min(100, max(0, $lv('lose_customers_pct', 0))) / 100;
        $paying2 = $paying * $mult * (1 - $lose);
        $customers2 = $customers * $mult * (1 - $lose);
        // التسجيلات الزيادة بتتحول بنفس نسبة التحويل الحالية (شهر واحد)
        $extraSignups = max(0, $b['signups_month'] * (max(0, $lv('signups_mult', 1)) - 1));
        $conv = ($b['conversion_pct'] ?? 0) / 100;
        $paying2 += $extraSignups * $conv;
        $customers2 += $extraSignups;
        $paying2 += max(0, $lv('new_country_customers', 0));
        $customers2 += max(0, $lv('new_country_customers', 0));
        $apiMult = 1 + $lv('api_cost_change_pct', 0) / 100;
        $mrr = $paying2 * $b['arpu'];
        $variable = $customers2 * ($b['ai_cost_per_customer'] * $apiMult + $b['data_cost_per_customer']);
        $support = $b['support_minutes_per_customer'] !== null && $b['support_cost_hour'] !== null ? $customers2 * $b['support_minutes_per_customer'] / 60 * $b['support_cost_hour'] : null;
        $fixed = $b['fixed_monthly'] + max(0, $lv('new_country_monthly_cost', 0));
        $costs = $variable + $fixed + ($support ?? 0);
        $capacity = $b['capacity_customers'] ? round($customers2 / $b['capacity_customers'] * 100, 1) : null;
        $baseVariable = $customers * ($b['ai_cost_per_customer'] + $b['data_cost_per_customer']);
        $baseSupport = $support !== null ? $customers * $b['support_minutes_per_customer'] / 60 * $b['support_cost_hour'] : 0;
        $baseProfit = $paying * $b['arpu'] - ($baseVariable + $b['fixed_monthly'] + $baseSupport);
        $resources = [];
        if ($capacity !== null && $capacity > 80) $resources[] = 'Server: ' . $capacity . '% of the capacity you entered. Plan more server resources (about ' . (int) ceil($customers2 / max(1, $b['capacity_customers'])) . '× today).';
        if ($capacity === null) $resources[] = 'Server capacity unknown: enter how many active customers the server handles to check this.';
        if ($support !== null) $resources[] = 'Support: about ' . round($customers2 * $b['support_minutes_per_customer'] / 60, 1) . ' hours a month.';
        if ($variable > 0) $resources[] = 'AI and data budget: about $' . round($variable, 2) . ' a month.';
        return ['active_customers' => round($customers2, 1), 'paying' => round($paying2, 1), 'mrr' => round($mrr, 2), 'variable_cost' => round($variable, 2), 'support_cost' => $support !== null ? round($support, 2) : null,
            'fixed_cost' => round($fixed, 2), 'costs' => round($costs, 2), 'profit' => round($mrr - $costs, 2), 'profit_change' => round(($mrr - $costs) - $baseProfit, 2),
            'margin_pct' => $mrr > 0 ? round(($mrr - $costs) / $mrr * 100, 1) : null, 'capacity_pct' => $capacity, 'resources' => $resources,
            'assumptions' => ['Same ARPU ($' . $b['arpu'] . ') and same cost per customer as the last 30 days', 'Extra sign-ups convert at today\'s rate (' . ($b['conversion_pct'] ?? 0) . '%)', 'One month view; no seasonality'],
            'estimate' => true];
    }

    /** أسئلة التوأم الجاهزة من التوجيه */
    public static function presets(): array
    {
        return ['double_customers' => ['label' => 'If customers double, does the server cope?', 'levers' => ['customers_mult' => 2]],
            'api_cost_up' => ['label' => 'If API costs rise 50%, what happens to profit?', 'levers' => ['api_cost_change_pct' => 50]],
            'lose_10' => ['label' => 'If we lose 10% of customers?', 'levers' => ['lose_customers_pct' => 10]],
            'new_country' => ['label' => 'If we target a new country ($500/month, 5 customers)?', 'levers' => ['new_country_monthly_cost' => 500, 'new_country_customers' => 5]],
            'signups_x5' => ['label' => 'If sign-ups grow five times, what resources are needed?', 'levers' => ['signups_mult' => 5]]];
    }

    // ------------------------------------------------------------------ الأهداف ربع السنوية

    public static function quarter(?int $ts = null): string
    {
        $ts = $ts ?? time();
        return date('Y', $ts) . '-Q' . (int) ceil((int) date('n', $ts) / 3);
    }

    /** نسبة التقدم (منطق نقي) */
    public static function progress(?float $baseline, float $target, ?float $current, string $metric): ?float
    {
        if ($current === null) return null;
        $lower = in_array($metric, self::LOWER_IS_BETTER, true);
        $base = $baseline ?? ($lower ? $current : 0.0);
        $span = $lower ? $base - $target : $target - $base;
        if ($span <= 0) return ($lower ? $current <= $target : $current >= $target) ? 100.0 : 0.0;
        return round(max(0, min(100, ($lower ? $base - $current : $current - $base) / $span * 100)), 1);
    }

    /** القيم الحالية للمؤشرات من الصورة */
    public static function metricValues(array $snap): array
    {
        $g = static fn (array $path) => array_reduce($path, static fn ($c, $k) => is_array($c) && array_key_exists($k, $c) ? $c[$k] : null, $snap);
        $inc = $g(['ops', 'incidents_open']);
        return ['mrr' => $g(['revenue', 'mrr', 'now']), 'paying' => $g(['revenue', 'paying', 'now']), 'signups_30d' => $g(['brand', 'signups_30d']), 'logo_churn' => $g(['revenue', 'logo_churn', 'now']),
            'trial_conversion' => $g(['revenue', 'trial_conversion']), 'brand_score' => $g(['brand', 'audit_score']), 'incidents_open' => is_array($inc) ? array_sum($inc) : null, 'support_unanswered' => $g(['success', 'support_unanswered'])];
    }

    public function addGoal(array $in, array $current, ?int $actor): int
    {
        $metric = (string) ($in['metric'] ?? '');
        if (!isset(self::METRICS[$metric])) throw new InvalidArgumentException('Choose a metric.');
        if (!is_numeric($in['target'] ?? null)) throw new InvalidArgumentException('Enter a numeric target.');
        $title = trim((string) ($in['title'] ?? '')) ?: self::METRICS[$metric] . ' → ' . $in['target'];
        $quarter = preg_match('/^\d{4}-Q[1-4]$/', (string) ($in['quarter'] ?? '')) ? $in['quarter'] : self::quarter();
        $this->db->query('INSERT INTO aiceo_goals (quarter, title, metric, baseline, target, status, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$quarter, mb_substr($title, 0, 255), $metric, isset($current[$metric]) && is_numeric($current[$metric]) ? (float) $current[$metric] : null, (float) $in['target'], 'active', $actor, gmdate('Y-m-d H:i:s')]);
        return (int) ($this->db->query('SELECT MAX(id) AS id FROM aiceo_goals')[0]['id'] ?? 0);
    }

    public function setGoalStatus(int $id, string $status): void
    {
        if (!in_array($status, ['active', 'achieved', 'missed', 'dropped'], true)) throw new InvalidArgumentException('Invalid status');
        $this->db->query('UPDATE aiceo_goals SET status = ? WHERE id = ?', [$status, $id]);
    }

    public function addTask(int $goalId, string $title, ?string $week = null): int
    {
        if (trim($title) === '' || !$this->db->query('SELECT 1 FROM aiceo_goals WHERE id = ?', [$goalId])) throw new InvalidArgumentException('Unknown goal or empty task.');
        $week = $week && preg_match('/^\d{4}-W\d{2}$/', $week) ? $week : gmdate('o-\WW');
        $now = gmdate('Y-m-d H:i:s');
        $this->db->query('INSERT INTO aiceo_goal_tasks (goal_id, week, title, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)', [$goalId, $week, mb_substr(trim($title), 0, 255), 'todo', $now, $now]);
        return (int) ($this->db->query('SELECT MAX(id) AS id FROM aiceo_goal_tasks')[0]['id'] ?? 0);
    }

    public function setTaskStatus(int $id, string $status): void
    {
        if (!in_array($status, ['todo', 'doing', 'done', 'skipped'], true)) throw new InvalidArgumentException('Invalid status');
        $this->db->query('UPDATE aiceo_goal_tasks SET status = ?, updated_at = ? WHERE id = ?', [$status, gmdate('Y-m-d H:i:s'), $id]);
    }

    public function goals(array $current, ?string $quarter = null): array
    {
        $quarter = $quarter ?? self::quarter();
        $goals = $this->db->query("SELECT * FROM aiceo_goals WHERE quarter = ? OR status = 'active' ORDER BY quarter DESC, id ASC", [$quarter]) ?: [];
        $ids = array_map(static fn ($g) => (int) $g['id'], $goals);
        $tasks = [];
        if ($ids) foreach ($this->db->query('SELECT * FROM aiceo_goal_tasks WHERE goal_id IN (' . implode(',', $ids) . ') ORDER BY week DESC, id ASC') ?: [] as $t) $tasks[(int) $t['goal_id']][] = ['id' => (int) $t['id'], 'week' => $t['week'], 'title' => $t['title'], 'status' => $t['status']];
        $week = gmdate('o-\WW');
        return ['quarter' => $quarter, 'week' => $week, 'items' => array_map(function ($g) use ($current, $tasks, $week) {
            $cur = isset($current[$g['metric']]) && is_numeric($current[$g['metric']]) ? (float) $current[$g['metric']] : null;
            $t = $tasks[(int) $g['id']] ?? [];
            $thisWeek = array_values(array_filter($t, static fn ($x) => $x['week'] === $week));
            return ['id' => (int) $g['id'], 'quarter' => $g['quarter'], 'title' => $g['title'], 'metric' => $g['metric'], 'metric_label' => self::METRICS[$g['metric']] ?? $g['metric'], 'baseline' => $g['baseline'] !== null ? (float) $g['baseline'] : null,
                'target' => (float) $g['target'], 'current' => $cur, 'progress' => self::progress($g['baseline'] !== null ? (float) $g['baseline'] : null, (float) $g['target'], $cur, $g['metric']), 'status' => $g['status'],
                'tasks' => $t, 'week_done' => count(array_filter($thisWeek, static fn ($x) => $x['status'] === 'done')), 'week_total' => count($thisWeek)];
        }, $goals)];
    }

    // ------------------------------------------------------------------ AI Boardroom

    /** @return array{answer:array, title:string} */
    public function boardroom(int $ownerId, string $question, array $context, ?AiCostIntelligence $costs = null): array
    {
        $question = trim($question);
        if (mb_strlen($question) < 10 || mb_strlen($question) > 1000) throw new InvalidArgumentException('Ask a question of 10 to 1000 characters.');
        if ($costs && ($b = $costs->aiCeoBlocked())) throw new RuntimeException('budget_' . $b);
        $prompt = "You are the board of Tourfecto, a SaaS platform for tourism businesses: a CFO, a CMO, a CTO and a COO.\n"
            . "Answer the owner's strategic question using ONLY the DATA below. Where data is missing, say so in data_gaps instead of inventing numbers. Never present estimates as facts.\n"
            . "Give three scenarios (conservative, balanced, aggressive), each with cost, expected_return, risks, assumptions and resources. Money in USD.\n"
            . "Reply in the language of the question.\n"
            . "Return ONLY JSON: {\"summary\": string, \"perspectives\": {\"cfo\": string, \"cmo\": string, \"cto\": string, \"coo\": string}, \"scenarios\": {\"conservative\": {\"cost\": string, \"expected_return\": string, \"risks\": [string], \"assumptions\": [string], \"resources\": [string]}, \"balanced\": {...same}, \"aggressive\": {...same}}, \"recommendation\": string, \"next_steps\": [string], \"data_gaps\": [string]}\n\n"
            . "QUESTION:\n{$question}\n\nDATA:\n" . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($this->ai) {
            $raw = call_user_func($this->ai, $prompt);
        } else {
            $r = (new AIOrchestrator())->generateContent($prompt, ['task' => 'ai_ceo_boardroom', 'user_id' => $ownerId, 'maxOutputTokens' => 4000, 'temperature' => 0.4, 'responseMimeType' => 'application/json']);
            $raw = !empty($r['success']) ? (string) ($r['data'] ?? '') : null;
        }
        if (!$raw) throw new RuntimeException('ai_unavailable');
        $a = json_decode(trim(preg_replace('/^```(?:json)?|```$/m', '', trim($raw))), true);
        if (!is_array($a) || empty($a['scenarios']) || !is_array($a['scenarios'])) throw new RuntimeException('invalid_plan');
        return ['answer' => $a, 'title' => 'Boardroom: ' . mb_substr($question, 0, 200)];
    }

    // ------------------------------------------------------------------ الإعدادات

    public function settings(): array
    {
        $m = array_column($this->q("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('fin_capacity_customers','cs_support_minutes','cs_support_cost_hour')"), 'setting_value', 'setting_key');
        $n = static fn ($k) => isset($m[$k]) && is_numeric($m[$k]) && (float) $m[$k] > 0 ? (float) $m[$k] : null;
        return ['capacity_customers' => $n('fin_capacity_customers'), 'support_minutes_per_message' => $n('cs_support_minutes'), 'support_cost_hour' => $n('cs_support_cost_hour')];
    }

    public function saveCapacity($v): void
    {
        $v = trim((string) $v);
        if ($v !== '' && (!is_numeric($v) || (float) $v <= 0 || (float) $v > 10000000)) throw new InvalidArgumentException('Capacity must be a positive number of customers.');
        $this->db->query("INSERT INTO system_settings (setting_key, setting_value, is_secret, category) VALUES ('fin_capacity_customers', ?, 0, 'ai_ceo') ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)", [$v]);
    }
}
