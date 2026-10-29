<?php

/**
 * TOURFECTO AI CEO (المرحلة 2) — ذكاء تكلفة وجودة الذكاء الاصطناعي.
 *
 * بيقرا api_usage_logs (اللي AIOrchestrator و GeminiClient بيكتبوا فيه feature = اسم المهمة، و model، والتكلفة، والمدة)
 * وبيطلع: الإنفاق النهارده/الأسبوع/الشهر، وتوقع آخر الشهر، والتكلفة حسب المهمة والنموذج والعميل، والاستهلاك غير الطبيعي،
 * والمهام اللي بتستخدم نموذج أغلى من اللازم، وميزانية AI CEO المنفصلة، والتوفير اللي اتقاس فعلًا.
 *
 * حدود البيانات (بتتقال في التقرير، مش بتتخبّى):
 *  - الطلبات اللي فشلت مش بتتسجل في api_usage_logs، فنسبة الفشل مش محسوبة من هنا.
 *  - التوفير بيتحسب بس من حاجات اتقاست (إجابات AI CEO من الأرقام أو من الكاش × متوسط تكلفة طلب AI CEO فعلي).
 * الميزانيات في system_settings:
 *   ai_platform_budget_usd_month  ميزانية تنبيه للمنصة كلها (فاضي = مش متحددة)
 *   aiceo_ai_budget_usd_day / aiceo_ai_budget_usd_month  ميزانية AI CEO نفسه، منفصلة عن العملاء (افتراضي 1 و 20 دولار)
 */
final class AiCostIntelligence
{
    public const BUDGET_KEYS = ['ai_platform_budget_usd_month' => [0, 100000, null], 'aiceo_ai_budget_usd_day' => [0, 1000, 1.0], 'aiceo_ai_budget_usd_month' => [0, 10000, 20.0]];
    /** المهام اللي بتتحسب على ميزانية AI CEO */
    public const AICEO_FEATURE = 'ai_ceo%';

    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }

    public function budgets(): array
    {
        $out = [];
        foreach (self::BUDGET_KEYS as $k => [, , $default]) $out[$k] = $default;
        try {
            $keys = array_keys(self::BUDGET_KEYS);
            foreach ($this->db->query('SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN (' . implode(',', array_fill(0, count($keys), '?')) . ')', $keys) ?: [] as $r) {
                if (is_numeric($r['setting_value'])) $out[$r['setting_key']] = (float) $r['setting_value'];
            }
        } catch (Throwable $e) {
        }
        return $out;
    }

    /** صاحب المنصة بيحدد الميزانيات (فاضي = يرجع للافتراضي). مش صرف، دي حدود ومراقبة. */
    public function saveBudgets(array $in): array
    {
        foreach (self::BUDGET_KEYS as $k => [$min, $max]) {
            if (!array_key_exists($k, $in)) continue;
            $v = $in[$k];
            if ($v === '' || $v === null) { $this->db->query('DELETE FROM system_settings WHERE setting_key = ?', [$k]); continue; }
            if (!is_numeric($v) || (float) $v < $min || (float) $v > $max) throw new InvalidArgumentException('Invalid value: ' . $k);
            $this->db->query("INSERT INTO system_settings (setting_key, setting_value, is_secret, category) VALUES (?, ?, 0, 'ai_ceo') ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)", [$k, (string) round((float) $v, 2)]);
        }
        return $this->budgets();
    }

    /** إنفاق AI CEO نفسه (اليوم والشهر) */
    public function aiCeoSpend(): array
    {
        $r = $this->db->query("SELECT COALESCE(SUM(CASE WHEN created_at >= CURDATE() THEN cost_in_usd END), 0) AS day, COALESCE(SUM(cost_in_usd), 0) AS month, COUNT(*) AS calls
            FROM api_usage_logs WHERE feature LIKE ? AND created_at >= DATE_FORMAT(NOW(), '%Y-%m-01')", [self::AICEO_FEATURE])[0] ?? [];
        return ['day' => round((float) ($r['day'] ?? 0), 4), 'month' => round((float) ($r['month'] ?? 0), 4), 'calls_month' => (int) ($r['calls'] ?? 0)];
    }

    /** null = مسموح؛ وإلا سبب الإيقاف (AI CEO وصل ميزانيته) */
    public function aiCeoBlocked(): ?string
    {
        $b = $this->budgets();
        $s = $this->aiCeoSpend();
        if ($b['aiceo_ai_budget_usd_day'] !== null && $s['day'] >= (float) $b['aiceo_ai_budget_usd_day']) return 'daily';
        if ($b['aiceo_ai_budget_usd_month'] !== null && $s['month'] >= (float) $b['aiceo_ai_budget_usd_month']) return 'monthly';
        return null;
    }

    public function report(): array
    {
        $q = fn (string $sql, array $p = []) => $this->db->query($sql, $p) ?: [];
        $tot = $q("SELECT COALESCE(SUM(CASE WHEN created_at >= CURDATE() THEN cost_in_usd END), 0) AS today,
                COALESCE(SUM(CASE WHEN created_at >= CURDATE() - INTERVAL 7 DAY AND created_at < CURDATE() THEN cost_in_usd END), 0) AS prev7,
                COALESCE(SUM(CASE WHEN created_at >= DATE_FORMAT(NOW(), '%Y-%m-01') THEN cost_in_usd END), 0) AS month,
                SUM(created_at >= CURDATE()) AS req_today, SUM(created_at >= DATE_FORMAT(NOW(), '%Y-%m-01')) AS req_month
            FROM api_usage_logs WHERE created_at >= LEAST(CURDATE() - INTERVAL 7 DAY, DATE_FORMAT(NOW(), '%Y-%m-01'))")[0] ?? [];
        $day = (int) date('j');
        $daysInMonth = (int) date('t');
        $month = (float) ($tot['month'] ?? 0);
        $projected = $day > 0 ? round($month / $day * $daysInMonth, 2) : null;

        // حسب المهمة: النهارده مقابل متوسط اليوم آخر 7 أيام
        $features = $q("SELECT COALESCE(feature, '(untagged)') AS feature,
                COALESCE(SUM(CASE WHEN created_at >= CURDATE() THEN cost_in_usd END), 0) AS today,
                COALESCE(SUM(CASE WHEN created_at < CURDATE() THEN cost_in_usd END), 0) / 7 AS avg_day,
                SUM(created_at >= CURDATE()) AS req_today, COUNT(*) AS req_8d, COALESCE(SUM(cost_in_usd), 0) AS cost_8d
            FROM api_usage_logs WHERE created_at >= CURDATE() - INTERVAL 7 DAY GROUP BY feature ORDER BY cost_8d DESC LIMIT 25");
        $models = $q("SELECT COALESCE(model, '?') AS model, api_type AS provider, COUNT(*) AS requests, COALESCE(SUM(cost_in_usd), 0) AS cost,
                COALESCE(SUM(cost_in_usd), 0) / NULLIF(COUNT(*), 0) AS cost_per_request, AVG(tokens_used) AS avg_tokens, AVG(NULLIF(duration_ms, 0)) AS avg_ms
            FROM api_usage_logs WHERE created_at >= DATE_FORMAT(NOW(), '%Y-%m-01') GROUP BY model, api_type ORDER BY cost DESC LIMIT 20");
        $customers = $q("SELECT user_id, COUNT(*) AS requests, COALESCE(SUM(cost_in_usd), 0) AS cost,
                COALESCE(SUM(CASE WHEN created_at >= CURDATE() THEN cost_in_usd END), 0) AS today
            FROM api_usage_logs WHERE created_at >= DATE_FORMAT(NOW(), '%Y-%m-01') AND user_id IS NOT NULL GROUP BY user_id ORDER BY cost DESC LIMIT 10");
        // نفس المهمة بأكتر من نموذج: هل فيه نموذج أرخص بكتير شغال على نفس الشغل؟
        $featureModels = $q("SELECT COALESCE(feature, '(untagged)') AS feature, COALESCE(model, '?') AS model, COUNT(*) AS requests,
                COALESCE(SUM(cost_in_usd), 0) / NULLIF(COUNT(*), 0) AS cost_per_request
            FROM api_usage_logs WHERE created_at >= CURDATE() - INTERVAL 30 DAY AND cost_in_usd > 0 GROUP BY feature, model HAVING requests >= 5");

        $budgets = $this->budgets();
        $aiceo = $this->aiCeoSpend();
        return [
            'totals' => ['today' => round((float) ($tot['today'] ?? 0), 4), 'avg_day_7d' => round((float) ($tot['prev7'] ?? 0) / 7, 4), 'month' => round($month, 4),
                'projected_month' => $projected, 'requests_today' => (int) ($tot['req_today'] ?? 0), 'requests_month' => (int) ($tot['req_month'] ?? 0)],
            'budgets' => $budgets,
            'platform_budget' => $budgets['ai_platform_budget_usd_month'] !== null ? ['limit' => (float) $budgets['ai_platform_budget_usd_month'], 'used_pct' => $budgets['ai_platform_budget_usd_month'] > 0 ? round($month / $budgets['ai_platform_budget_usd_month'] * 100, 1) : null,
                'projected_pct' => $budgets['ai_platform_budget_usd_month'] > 0 && $projected !== null ? round($projected / $budgets['ai_platform_budget_usd_month'] * 100, 1) : null] : null,
            'aiceo' => $aiceo + ['day_limit' => $budgets['aiceo_ai_budget_usd_day'], 'month_limit' => $budgets['aiceo_ai_budget_usd_month'], 'blocked' => $this->aiCeoBlocked()],
            'features' => array_map(static fn ($f) => ['feature' => $f['feature'], 'today' => round((float) $f['today'], 4), 'avg_day' => round((float) $f['avg_day'], 4),
                'requests_today' => (int) $f['req_today'], 'cost_8d' => round((float) $f['cost_8d'], 4)], $features),
            'models' => array_map(static fn ($m) => ['model' => $m['model'], 'provider' => $m['provider'], 'requests' => (int) $m['requests'], 'cost' => round((float) $m['cost'], 4),
                'cost_per_request' => round((float) $m['cost_per_request'], 5), 'avg_tokens' => (int) round((float) $m['avg_tokens']), 'avg_ms' => $m['avg_ms'] !== null ? (int) round((float) $m['avg_ms']) : null], $models),
            'customers' => array_map(static fn ($c) => ['user_id' => (int) $c['user_id'], 'requests' => (int) $c['requests'], 'cost' => round((float) $c['cost'], 4), 'today' => round((float) $c['today'], 4)], $customers),
            'anomalies' => self::anomalies($features, $customers),
            'routing_review' => self::routingReview($featureModels),
            'savings' => $this->savings($aiceo),
            'limits' => [
                'Failed AI requests are not written to api_usage_logs, so failure rates are not measured here (provider outages show in platform health).',
                'Savings count only measured avoided calls; they are not projected.',
            ],
        ];
    }

    /** مهمة أو عميل النهارده أكتر من 3 أضعاف متوسطه اليومي، ومبلغ يستاهل (≥ 0.5 دولار) */
    public static function anomalies(array $features, array $customers): array
    {
        $out = [];
        foreach ($features as $f) {
            $today = (float) $f['today'];
            $avg = (float) $f['avg_day'];
            if ($today >= 0.5 && $today > 3 * max($avg, 0.01)) $out[] = ['type' => 'feature', 'name' => (string) $f['feature'], 'today' => round($today, 2), 'avg_day' => round($avg, 2)];
        }
        foreach ($customers as $c) {
            // متوسط اليوم للعميل الشهر ده (من غير النهارده)
            $day = max(1, (int) date('j') - 1);
            $avg = max(0.0, ((float) $c['cost'] - (float) $c['today']) / $day);
            if ((float) $c['today'] >= 0.5 && (float) $c['today'] > 3 * max($avg, 0.01)) $out[] = ['type' => 'customer', 'name' => 'user #' . (int) $c['user_id'], 'today' => round((float) $c['today'], 2), 'avg_day' => round($avg, 2)];
        }
        return $out;
    }

    /**
     * نفس المهمة شغالة على أكتر من نموذج، والأغلى تكلفته للطلب أكتر من 3 أضعاف الأرخص (بعينة ≥ 5 طلبات لكل واحد):
     * اقتراح لمراجعة التوزيع في /admin/ai-routing بعد اختبار الجودة. مش بيغيّر حاجة لوحده.
     */
    public static function routingReview(array $rows): array
    {
        $by = [];
        foreach ($rows as $r) $by[$r['feature']][] = $r;
        $out = [];
        foreach ($by as $feature => $list) {
            if (count($list) < 2) continue;
            usort($list, static fn ($a, $b) => (float) $a['cost_per_request'] <=> (float) $b['cost_per_request']);
            $cheap = $list[0];
            $dear = end($list);
            if ((float) $cheap['cost_per_request'] > 0 && (float) $dear['cost_per_request'] > 3 * (float) $cheap['cost_per_request']) {
                $out[] = ['feature' => (string) $feature, 'expensive_model' => (string) $dear['model'], 'expensive_cpr' => round((float) $dear['cost_per_request'], 5), 'expensive_requests' => (int) $dear['requests'],
                    'cheap_model' => (string) $cheap['model'], 'cheap_cpr' => round((float) $cheap['cost_per_request'], 5)];
            }
        }
        return $out;
    }

    /** التوفير المقاس: إجابات AI CEO اللي اتجابت من الأرقام أو من الكاش بدل طلب ذكاء اصطناعي، × متوسط تكلفة طلب AI CEO فعلي */
    private function savings(array $aiceo): array
    {
        $avoided = $this->db->query("SELECT COUNT(*) AS c FROM aiceo_activity WHERE action = 'chat' AND created_at >= DATE_FORMAT(NOW(), '%Y-%m-01')
            AND (detail_json LIKE '%\"source\":\"data\"%' OR detail_json LIKE '%\"source\":\"cache\"%')")[0]['c'] ?? 0;
        $avg = $aiceo['calls_month'] > 0 ? $aiceo['month'] / $aiceo['calls_month'] : null;
        return ['aiceo_avoided_calls_month' => (int) $avoided, 'aiceo_avg_call_usd' => $avg !== null ? round($avg, 5) : null,
            'aiceo_saved_usd_month' => $avg !== null ? round((int) $avoided * $avg, 4) : null];
    }
}
