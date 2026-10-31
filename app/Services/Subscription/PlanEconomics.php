<?php

/**
 * اقتصاديات الباقات من أرقام فعلية مش تقديرات: لكل باقة، المشتركين الشغالين والإيراد الشهري المكافئ،
 * وتكلفة آخر 30 يوم الحقيقية (DataForSEO من دفتر الطلبات + الذكاء الاصطناعي من api_usage_logs) للحسابات دي بس.
 * مفيش افتراض لتكلفة وحدة؛ لو مفيش مشتركين بنقول كده بدل ما نخترع هامش.
 */
final class PlanEconomics
{
    public const LOW_MARGIN_PCT = 40;

    public static function report(Database $db, int $days = 30): array
    {
        $pdo = $db->getConnection();
        $days = max(1, min(90, $days));
        $since = gmdate('Y-m-d H:i:s', time() - $days * 86400);

        $subs = $pdo->query("SELECT s.user_id, sp.plan_code, sp.billing_cycle, sp.price, sp.currency
            FROM subscriptions s JOIN subscription_plans sp ON sp.id = s.plan_id
            WHERE s.status IN ('active','trialing','past_due')")->fetchAll(PDO::FETCH_ASSOC);

        $plans = [];
        $ownerPlan = [];
        foreach ($subs as $r) {
            if (!preg_match('/^([a-z0-9-]+)_(monthly|yearly)$/D', (string) $r['plan_code'], $m)) continue;
            $usd = class_exists('CurrencyService') ? CurrencyService::toUsd((float) $r['price'], (string) $r['currency']) : (float) $r['price'];
            $monthly = $m[2] === 'yearly' ? $usd / 12 : $usd;
            $plans[$m[1]] ??= ['plan_key' => $m[1], 'subscribers' => 0, 'mrr_usd' => 0.0, 'dfs_cost' => 0.0, 'ai_cost' => 0.0];
            $plans[$m[1]]['subscribers']++;
            $plans[$m[1]]['mrr_usd'] += $monthly;
            $ownerPlan[(int) $r['user_id']] = $m[1];
        }
        if (!$ownerPlan) return ['days' => $days, 'plans' => [], 'note' => 'مفيش اشتراكات شغالة لسه، فمفيش هامش فعلي يتحسب.'];

        // أي حساب فرعي (عضو فريق) بيتنسب لصاحب الشركة
        $ids = implode(',', array_map('intval', array_keys($ownerPlan)));
        $team = [];
        foreach ($pdo->query("SELECT id, owner_user_id FROM users WHERE owner_user_id IN ($ids)")->fetchAll(PDO::FETCH_ASSOC) as $u) {
            $team[(int) $u['id']] = (int) $u['owner_user_id'];
        }
        $owner = static fn (int $uid): int => $team[$uid] ?? $uid;

        $st = $pdo->prepare('SELECT w.user_id, SUM(c.cost) AS cost FROM dataforseo_calls c JOIN websites w ON w.id = c.website_id WHERE c.created_at >= ? AND c.website_id > 0 GROUP BY w.user_id');
        $st->execute([$since]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $o = $owner((int) $r['user_id']);
            if (isset($ownerPlan[$o])) $plans[$ownerPlan[$o]]['dfs_cost'] += (float) $r['cost'];
        }
        $st = $pdo->prepare('SELECT user_id, SUM(cost_in_usd) AS cost FROM api_usage_logs WHERE created_at >= ? GROUP BY user_id');
        $st->execute([$since]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $o = $owner((int) $r['user_id']);
            if (isset($ownerPlan[$o])) $plans[$ownerPlan[$o]]['ai_cost'] += (float) $r['cost'];
        }

        $out = [];
        foreach ($plans as $p) {
            $cost = $p['dfs_cost'] + $p['ai_cost'];
            $margin = $p['mrr_usd'] > 0 ? round((1 - $cost / $p['mrr_usd']) * 100, 1) : null;
            $out[] = [
                'plan_key' => $p['plan_key'], 'subscribers' => $p['subscribers'],
                'mrr_usd' => round($p['mrr_usd'], 2), 'dfs_cost_usd' => round($p['dfs_cost'], 4), 'ai_cost_usd' => round($p['ai_cost'], 4),
                'cost_usd' => round($cost, 4), 'cost_per_subscriber_usd' => round($cost / max(1, $p['subscribers']), 4),
                'margin_pct' => $margin, 'low_margin' => $margin !== null && $margin < self::LOW_MARGIN_PCT,
            ];
        }
        usort($out, static fn ($a, $b) => $b['mrr_usd'] <=> $a['mrr_usd']);
        return ['days' => $days, 'plans' => $out, 'note' => 'أرقام فعلية لآخر ' . $days . ' يوم (بتشمل تكلفة الحسابات دي في كل الخدمات). مش تقدير.'];
    }
}
