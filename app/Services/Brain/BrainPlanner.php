<?php

/**
 * Tourfecto - العقل الاستراتيجي: الخطة والأهداف (منطق نقي من غير قاعدة بيانات)
 *
 * - الأهداف: مقياس من صورة الشركة + رقم مستهدف + تاريخ. التقدم بيتقارن بالوقت اللي عدّى (على المسار / متأخر / متقدم).
 * - الخطة الأسبوعية: بتختار من القرارات المفتوحة اللي تناسب طاقة الأسبوع (نقاط جهد)، بترتيب:
 *   اللي شغالين عليه الأول، وبعدين الأعلى أولوية (مع رفع المجالات اللي بتخدم هدف نشط). القرار اللي لسه محتاج
 *   قرار تاني قبله بيتأجل مع ذكر السبب، والقرار اللي الشركة رفضته أو أجّلته (الذاكرة) مابيدخلش.
 */
final class BrainPlanner
{
    /** المقاييس المسموحة كأهداف: اتجاه التحسّن والمجالات اللي بتأثر عليها */
    public const GOAL_METRICS = [
        'bookings.last_30' => ['up', ['acquisition', 'seo', 'social', 'ads', 'content', 'whatsapp']],
        'bookings.revenue_30' => ['up', ['acquisition', 'seo', 'social', 'ads', 'content', 'whatsapp']],
        'reputation.avg_rating' => ['up', ['reputation']],
        'reputation.unanswered_negative' => ['down', ['reputation']],
        'scores.seo_score' => ['up', ['seo']],
        'social.published_30' => ['up', ['social', 'content']],
        'chat.pending_approval' => ['down', ['whatsapp']],
    ];

    /** جهد القرار (نقاط) حسب المجال: المعلومات والإعدادات أخف من المحتوى والإعلانات */
    public const EFFORT = ['data' => 1, 'market' => 1, 'whatsapp' => 1, 'reputation' => 2, 'acquisition' => 2, 'seo' => 3, 'social' => 3, 'content' => 3, 'ads' => 3];
    public const DEFAULT_CAPACITY = 6;
    public const MAX_CAPACITY = 20;
    /** أيام شغل الأسبوع (الأحد لحد الخميس) */
    public const WORK_DAYS = ['sun', 'mon', 'tue', 'wed', 'thu'];

    /** قرار ← قرارات لازم تتقفل قبله (لو لسه مفتوحة) */
    private const DEPENDS = [];
    /** اللي مابيتأجلش لحد ما ملف الشركة يتأكد */
    private const AFTER_PROFILE_EXEMPT = ['data', 'market'];

    /** @return array{0:string,1:string[]}|null */
    public static function metric(string $key): ?array
    {
        return self::GOAL_METRICS[$key] ?? null;
    }

    public static function effort(array $decision): int
    {
        return self::EFFORT[$decision['area'] ?? ''] ?? 2;
    }

    public static function capacity($v): int
    {
        return max(1, min(self::MAX_CAPACITY, (int) ($v ?: self::DEFAULT_CAPACITY)));
    }

    /**
     * @param array $goal  metric_key, direction, target_value, baseline_value, created_at, deadline
     * @param float|null $current القيمة الحالية من صورة الشركة (null = المصدر مش متاح)
     * @return array{status:string,pct:?float,expected_pct:float,current:?float,weeks_left:int,needed_per_week:?float}
     */
    public static function goalProgress(array $goal, ?float $current, ?int $now = null): array
    {
        $now ??= time();
        $up = ($goal['direction'] ?? 'up') !== 'down';
        $base = (float) $goal['baseline_value'];
        $target = (float) $goal['target_value'];
        $created = strtotime((string) $goal['created_at'] . ' UTC') ?: $now;
        $deadline = strtotime((string) $goal['deadline'] . ' 23:59:59 UTC') ?: $now;
        $span = max(1, $deadline - $created);
        $expected = round(max(0.0, min(1.0, ($now - $created) / $span)) * 100, 1);
        $weeks = (int) max(0, ceil(($deadline - $now) / 604800));
        $out = ['status' => 'unknown', 'pct' => null, 'expected_pct' => $expected, 'current' => $current, 'weeks_left' => $weeks, 'needed_per_week' => null];
        if ($current === null) return $out;

        $reached = $up ? $current >= $target : $current <= $target;
        $total = $up ? $target - $base : $base - $target;
        $done = $up ? $current - $base : $base - $current;
        $pct = $reached ? 100.0 : ($total > 0 ? round(max(0.0, min(1.0, $done / $total)) * 100, 1) : 0.0);
        $out['pct'] = $pct;
        if ($reached) return ['status' => 'achieved'] + $out;
        if ($now > $deadline) return ['status' => 'missed'] + $out;
        $remaining = abs($target - $current);
        $out['needed_per_week'] = $weeks > 0 ? round($remaining / $weeks, 2) : null;
        $status = $pct + 10 >= $expected ? ($pct >= $expected + 15 ? 'ahead' : 'on_track') : 'behind';
        return ['status' => $status] + $out;
    }

    /** المجالات اللي بتخدم أهداف نشطة (للرفع في الترتيب). الهدف المتأخر بيرفع أكتر. */
    public static function goalAreas(array $goalsWithProgress): array
    {
        $w = [];
        foreach ($goalsWithProgress as $g) {
            if (($g['status'] ?? 'active') !== 'active' || in_array($g['progress']['status'] ?? '', ['achieved', 'missed'], true)) continue;
            $boost = ($g['progress']['status'] ?? '') === 'behind' ? 1.35 : 1.2;
            foreach ((self::metric((string) $g['metric_key'])[1] ?? []) as $area) $w[$area] = max($w[$area] ?? 1.0, $boost);
        }
        return $w;
    }

    /**
     * @param array<int,array> $decisions قرارات الشركة (من BrainDecisionStore::list)
     * @param array<string,float> $areaBoost من goalAreas
     * @param string[] $excluded مفاتيح قرارات مرفوضة/مؤجلة (الذاكرة)
     * @return array{capacity:int,used:int,items:array<int,array>,deferred:array<int,array>}
     */
    public static function weeklyPlan(array $decisions, array $areaBoost = [], array $excluded = [], int $capacity = self::DEFAULT_CAPACITY): array
    {
        $capacity = self::capacity($capacity);
        $open = array_values(array_filter($decisions, static fn (array $d) => in_array($d['status'] ?? '', ['approved', 'proposed'], true) && !in_array($d['key'] ?? '', $excluded, true)));
        $openKeys = array_column($open, 'key');
        $profileOpen = in_array('confirm_profile', $openKeys, true);

        $score = static fn (array $d): float => (float) ($d['priority'] ?? 0) * ($areaBoost[$d['area'] ?? ''] ?? 1.0) + (($d['status'] ?? '') === 'approved' ? 1000 : 0);
        usort($open, static fn (array $a, array $b) => $score($b) <=> $score($a) ?: ((int) ($a['id'] ?? 0) <=> (int) ($b['id'] ?? 0)));

        $items = [];
        $deferred = [];
        $used = 0;
        foreach ($open as $d) {
            $key = (string) $d['key'];
            $blockedBy = array_values(array_filter(self::DEPENDS[$key] ?? [], static fn ($k) => in_array($k, $openKeys, true)));
            if ($profileOpen && $key !== 'confirm_profile' && !in_array($d['area'] ?? '', self::AFTER_PROFILE_EXEMPT, true)) $blockedBy[] = 'confirm_profile';
            if ($blockedBy) { $deferred[] = ['key' => $key, 'id' => $d['id'] ?? null, 'reason' => 'blocked', 'blocked_by' => array_values(array_unique($blockedBy))]; continue; }
            $effort = self::effort($d);
            // أول قرار دايمًا يدخل (حتى لو جهده أكبر من الطاقة)، والباقي بحسب اللي فاضل
            if ($items && $used + $effort > $capacity) { $deferred[] = ['key' => $key, 'id' => $d['id'] ?? null, 'reason' => 'capacity']; continue; }
            $items[] = ['id' => $d['id'] ?? null, 'key' => $key, 'area' => $d['area'] ?? '', 'effort' => $effort, 'day' => self::WORK_DAYS[count($items) % count(self::WORK_DAYS)],
                'status' => $d['status'] ?? 'proposed', 'requires_spend' => !empty($d['requires_spend']), 'goal_aligned' => ($areaBoost[$d['area'] ?? ''] ?? 1.0) > 1.0];
            $used += $effort;
        }
        return ['capacity' => $capacity, 'used' => $used, 'items' => $items, 'deferred' => $deferred];
    }
}
